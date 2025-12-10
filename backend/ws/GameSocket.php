<?php
// backend/ws/GameSocket.php
// -----------------------------------------------------------------------------
// Game WebSocket handler for per-table, real-time poker gameplay.
// - AuthenticatedServer attaches $conn->userCtx prior to onAuthenticated()
// - This class manages per-table connection tracking with multi-connection support.
// - Session-based model: multiple connections per user per table are allowed.
// - Refreshing/reconnecting doesn't close old connections, preventing flicker.
// - Players are only marked disconnected after ALL their connections close + delay.
// -----------------------------------------------------------------------------

declare(strict_types=1);

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Psr\Http\Message\RequestInterface;

require_once __DIR__ . '/../app/services/game/GameService.php';
require_once __DIR__ . '/../app/services/game/rules/GameTypes.php';
require_once __DIR__ . '/../app/services/game/cards/DealerService.php';
require_once __DIR__ . '/../app/services/game/cards/HandEvaluator.php';
require_once __DIR__ . '/../app/services/game/GamePersistence.php';
require_once __DIR__ . '/../app/services/SubscriptionService.php';
require_once __DIR__ . '/../app/services/AuditService.php';
require_once __DIR__ . '/../app/services/PresenceService.php';
require_once __DIR__ . '/LobbySocket.php';

require_once __DIR__ . '/../app/db/game_snapshots.php';
require_once __DIR__ . '/../app/db/games.php';
require_once __DIR__ . '/../app/db/users.php';
require_once __DIR__ . '/../app/db/chat_messages.php';
require_once __DIR__ . '/../app/db/table_seats.php';
require_once __DIR__ . '/../app/db/presence.php';

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/security.php';

final class GameSocket implements MessageComponentInterface
{
    // -------------------------------------------------------------------------
    // Tunables
    // -------------------------------------------------------------------------

    private const MAX_MSG_BYTES        = 2048;
    private const CHAT_MAX_CHARS       = 500;
    private const CHAT_HISTORY_SIZE    = 20;

    // Token bucket (per-connection) anti-spam limits
    private const RATE_TOKENS          = 10.0;
    private const RATE_REFILL_PER_S    = 2.0;

    // Delay before broadcasting disconnect (allows reconnects to register)
    private const DISCONNECT_DELAY_MS = 2000; // 2 seconds

    // Snapshot cadence (delegated to GamePersistence but kept here for clarity)
    private const SNAPSHOT_MAX_GAP = 5;

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /** @var \SplObjectStorage<ConnectionInterface, null> */
    protected \SplObjectStorage $clients;

    protected PDO $pdo;
    protected GamePersistence $persistenceService;
    protected SubscriptionService $subscriptionService;
    protected PresenceService $presenceService;
    protected ?LobbySocket $lobbySocket = null;

    /**
     * Connection metadata by Ratchet resourceId.
     * @var array<int, array{
     *   user_id:int, username:string, table_id:int, seat:int, game_id:int|null,
     *   rate:array{ts:float,tokens:float}
     * }>
     */
    protected array $connInfo = [];

    /** @var array<int, GameService> table_id => GameService */
    protected array $gameServices = [];

    /** @var array<int, list<ConnectionInterface>> table_id => list of conns */
    protected array $tableConnections = [];

    protected array $tableSeats = [];

    /**
     * Multi-connection support: [table_id => [user_id => [ConnectionInterface, ...]]]
     * Allows multiple active connections per user (refresh-safe)
     */
    protected array $userConnections = [];

    /**
     * Last seen timestamp per user per table: [table_id => [user_id => float]]
     * Used for delayed disconnect detection
     */
    protected array $lastSeen = [];

    /**
     * Pending disconnect checks: [table_id => [user_id => ['timestamp' => float, 'seat' => int, 'username' => string]]]
     * Scheduled when user's last connection closes
     */
    protected array $pendingDisconnects = [];

    /** @var array<int, int> table_id => game_id */
    protected array $tableIdToGameId = [];

    /** @var array<int, true> table_id => rebuilding flag */
    protected array $rebuildingTables = [];

    /** @var array<int, bool> table_id => bootstrapped flag */
    protected array $tableBootstrapped = [];

    /** @var ?GameSocket Static instance for broadcasting from GameService */
    private static ?GameSocket $instance = null;

    public function __construct(PDO $pdo, ?LobbySocket $lobbySocket = null)
    {
        $this->clients = new \SplObjectStorage();
        $this->pdo = $pdo;
        $this->persistenceService = new GamePersistence($pdo, self::SNAPSHOT_MAX_GAP);
        $this->subscriptionService = new SubscriptionService($pdo);
        $this->presenceService = new PresenceService($pdo);
        $this->lobbySocket = $lobbySocket;
        self::$instance = $this;
        echo "🎮 GameSocket initialized\n";
    }

    // -------------------------------------------------------------------------
    // Ratchet lifecycle
    // -------------------------------------------------------------------------

    public function onOpen(ConnectionInterface $conn): void
    {
        // AuthenticatedServer will call $this->onAuthenticated($conn) after attaching userCtx.
        $this->clients->attach($conn);
    }

    /**
     * Called by AuthenticatedServer after successful authentication and after onOpen().
     * Establishes per-table tracking and syncs state.
     * 
     * REFACTORED: Now supports multiple connections per user (refresh-safe).
     * Old connections are NOT closed when new ones open.
     */
    public function onAuthenticated(ConnectionInterface $conn): void
    {
        try {
            $userCtx = $conn->userCtx ?? null;
            if (!$userCtx) {
                $this->sendError($conn, 'unauthorized');
                $conn->close();
                return;
            }

            $uid   = (int)$userCtx['user_id'];
            $uname = $userCtx['username'] ?? (db_get_username_by_id($this->pdo, $uid) ?? "User#{$uid}");
            $rid   = (int)$conn->resourceId;

            // --- table id from handshake ---
            $req = $conn->httpRequest ?? null;
            $q = [];
            parse_str($req?->getUri()?->getQuery() ?? '', $q);
            $tableId = (int)($q['table_id'] ?? 0);
            if ($tableId <= 0) {
                $this->sendError($conn, 'invalid_table_id');
                return;
            }

            // --- verify seat ---
            $seatRow = db_find_seat_by_user($this->pdo, $tableId, $uid);
            if (!$seatRow) {
                $this->sendError($conn, 'not_seated');
                return;
            }
            $seat = (int)$seatRow['seat_no'];

            $activeGame = db_get_active_game($this->pdo, $tableId);
            $dbGameId   = $activeGame ? (int)$activeGame['id'] : null;
            $gameId     = $this->ensureGameService($tableId, $dbGameId);

            // --- connection bookkeeping ---
            $this->connInfo[$rid] = [
                'user_id'  => $uid,
                'username' => $uname,
                'table_id' => $tableId,
                'seat'     => $seat,
                'game_id'  => $gameId ?? 0, // Store as 0 if null (no active game)
                'rate'     => ['ts' => microtime(true), 'tokens' => self::RATE_TOKENS],
            ];
            $this->subscriptionService->register($uid, (string)$rid, 'game', $gameId ?? 0);

            $this->tableConnections[$tableId][] = $conn;
            $this->userConnections[$tableId][$uid][] = $conn;
            $this->tableSeats[$tableId][$seat] = ['user_id'=>$uid,'username'=>$uname];

            // --- reconnect detection ---
            $now = microtime(true) * 1000;
            $last = $this->lastSeen[$tableId][$uid] ?? 0;
            $isReconnect = ($now - $last) < 3000; // 3-second window
            $this->lastSeen[$tableId][$uid] = $now;

            // --- cancel pending disconnects ---
            unset($this->pendingDisconnects[$tableId][$uid]);
            if (empty($this->pendingDisconnects[$tableId])) unset($this->pendingDisconnects[$tableId]);

            $isFirstConnection = count($this->userConnections[$tableId][$uid]) === 1;

            // Update presence to 'in_game' when user connects to a game
            // Always update (even on refresh) to ensure presence reflects current state
            try {
                db_upsert_presence($this->pdo, $uid, $uname, 'in_game');
                // Broadcast presence update to lobby clients
                if ($this->lobbySocket !== null) {
                    $this->lobbySocket->broadcastPresenceUpdate($uid, $uname, 'in_game');
                }
            } catch (\Throwable $e) {
                error_log("[GameSocket] Error updating presence to in_game for user {$uid}: " . $e->getMessage());
            }

            $this->ensureHandBootstrapped($tableId, $gameId);
            $this->syncGameState($conn, $tableId, $seat, $gameId);
            // Always send chat history (even if gameId is 0 for table-level chat)
            $this->sendChatHistory($conn, $gameId ?? 0);

            // --- only broadcast if truly new user, not quick reconnect ---
            if ($isFirstConnection && !$isReconnect) {
                $this->broadcast($tableId, [
                    'type'     => 'PLAYER_CONNECTED',
                    'seat_no'  => $seat,
                    'username' => $uname,
                ]);
            }

            $connCount = count($this->userConnections[$tableId][$uid]);
            echo "[GameSocket] {$uname} connected to table #{$tableId} (game #{$gameId}, seat {$seat}, {$connCount} conn(s))\n";

        } catch (Throwable $e) {
            error_log('[GameSocket] onAuthenticated error: '.$e->getMessage());
            $this->sendError($conn, 'connection_failed');
        }
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $rid  = (int)$from->resourceId;
        $info = $this->connInfo[$rid] ?? null;

        if (!$info || !\is_string($msg) || \strlen($msg) > self::MAX_MSG_BYTES) {
            $this->sendError($from, 'invalid_request');
            return;
        }

        if (!$this->rateAllow($rid)) {
            $this->sendError($from, 'rate_limited');
            return;
        }

        $data = json_decode($msg, true);
        if (!\is_array($data) || !isset($data['type'])) {
            $this->sendError($from, 'invalid_payload');
            return;
        }

        try {
            match ($data['type']) {
                'ping'          => $this->handlePing($from, $rid),
                'action'        => $this->handleAction($from, $data, $info),
                'next_hand'     => $this->handleNextHand($from, $info),
                'chat'          => $this->handleChat($from, $data, $info),
                'chat_history'  => $this->sendChatHistory($from, (int)($info['game_id'] ?? 0)),
            
                default         => $this->sendError($from, 'unknown_type'),
            };            
        } catch (\Throwable $e) {
            error_log("[GameSocket] onMessage handler error: " . $e->getMessage());
            $this->sendError($from, 'server_error');
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $rid  = (int)$conn->resourceId;
        $info = $this->connInfo[$rid] ?? null;

        // If we never registered, just drop
        if (!$info) {
            $this->clients->detach($conn);
            return;
        }

        $tableId = (int)$info['table_id'];
        $uid     = (int)$info['user_id'];
        $seat    = (int)$info['seat'];
        $uname   = (string)$info['username'];

        // CRITICAL: Check remaining connections BEFORE removal to avoid race conditions
        // This ensures we accurately detect if this is the user's last connection
        $remainingConnsBefore = $this->userConnections[$tableId][$uid] ?? [];
        $isLastConnection = count($remainingConnsBefore) === 1 && $remainingConnsBefore[0] === $conn;

        // Remove connection from tracking (scoped to this table only)
        $this->removeConnection($conn, $tableId);
        $this->subscriptionService->disconnect((string)$rid);

        // MULTI-CONNECTION: Only schedule disconnect if this was the user's last connection
        // This prevents false disconnects during refreshes when new connections exist
        if ($isLastConnection) {
            // Check if user has any other active games (other tables)
            $hasOtherActiveGames = false;
            foreach ($this->userConnections as $otherTableId => $users) {
                if ($otherTableId !== $tableId && isset($users[$uid]) && !empty($users[$uid])) {
                    $hasOtherActiveGames = true;
                    break;
                }
            }
            
            // If user has no other active games, update presence back to 'online'
            // This allows them to appear in lobby while still being able to rejoin
            // CRITICAL: Always update to 'online' when they disconnect from their last game connection
            // This ensures they appear in the lobby even if they still have a seat (can rejoin)
            // NOTE: This happens BEFORE they connect to LobbySocket, so LobbySocket will see 'online' status
            if (!$hasOtherActiveGames) {
                try {
                    // Always update to 'online' when user disconnects from their last game connection
                    // They may still have a seat (can rejoin), but they're not actively in the game anymore
                    db_upsert_presence($this->pdo, $uid, $uname, 'online');
                    error_log("[GameSocket] Updated user {$uid} ({$uname}) presence to 'online' after disconnecting from table #{$tableId} (isLastConnection={$isLastConnection}, hasOtherActiveGames={$hasOtherActiveGames})");
                } catch (\Throwable $e) {
                    error_log("[GameSocket] Error updating presence to online for user {$uid}: " . $e->getMessage());
                }
            } else {
                error_log("[GameSocket] User {$uid} ({$uname}) still has other active games, keeping presence as 'in_game'");
            }
            
            // User's last connection closed - schedule delayed disconnect check
            $this->lastSeen[$tableId] ??= [];
            $this->lastSeen[$tableId][$uid] = microtime(true) * 1000; // milliseconds
            
            // Schedule disconnect broadcast check (async, non-blocking)
            $this->pendingDisconnects[$tableId] ??= [];
            $this->pendingDisconnects[$tableId][$uid] = [
                'timestamp' => microtime(true) * 1000,
                'seat'      => $seat,
                'username'  => $uname,
            ];
        }

        $remainingCount = count($remainingConnsBefore) - 1;
        echo "[GameSocket] {$uname} disconnected from table #{$tableId} ({$remainingCount} conn(s) remaining)\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        error_log("GameSocket transport error: " . $e->getMessage());
        $this->sendError($conn, 'server_error');
        // Let AuthenticatedServer close the conn; we avoid double-closing.
    }

    // -------------------------------------------------------------------------
    // Message handlers
    // -------------------------------------------------------------------------

    private function handlePing(ConnectionInterface $conn, int $rid): void
    {
        $this->subscriptionService->ping((string)$rid);
        $conn->send(json_encode(['type' => 'pong']));
    }

    private function handleAction(ConnectionInterface $from, array $data, array $info): void
    {
        $tableId = (int)$info['table_id'];
        $seat    = (int)$info['seat'];
    
        $gameService = $this->gameServices[$tableId] ?? null;
        if (!$gameService) {
            // Check if match has ended (gameService was cleared)
            // If tableIdToGameId is also cleared, match has ended
            if (!isset($this->tableIdToGameId[$tableId])) {
                $this->sendError($from, 'match_ended');
            } else {
                $this->sendError($from, 'game_not_found');
            }
            return;
        }
    
        try {
            $actionStr     = $data['action'] ?? '';
            $amount        = isset($data['amount']) ? (int)$data['amount'] : 0;
            $clientVersion = isset($data['game_version']) ? (int)$data['game_version'] : 0;
    
            // Version check → force sync if client is stale
            $currentVersion = $gameService->getVersion();
            if ($clientVersion > 0 && $clientVersion !== $currentVersion) {
                $this->sendStateSync($from, $tableId, $seat, 'version_mismatch');
                return;
            }
    
            $this->pdo->beginTransaction();
    
            $result = $gameService->applyAction($seat, $actionStr, $amount);
            if (!($result['ok'] ?? false)) {
                $this->pdo->rollBack();
                $this->sendError($from, 'action_failed', (string)($result['message'] ?? ''));
                return;
            }
    
            $this->pdo->commit();
    
            // ------- Read matchEnd flags from GameService -------
            $handEnded   = $result['handEnded']   ?? false;
            $handSummary = $result['summary']     ?? null;
            $matchEnded  = $result['matchEnded']  ?? false;
            $winner      = $result['winner']      ?? null;
            $loser       = $result['loser']       ?? null;
            $reason      = $result['reason']      ?? null;
            
            // If the entire MATCH is over (one player busted), end the match now
            if ($matchEnded) {
                // Validate winner/loser data before proceeding
                if (!$winner || !$loser) {
                    error_log("[GameSocket] Match end detected but winner/loser missing: tableId={$tableId}");
                    $this->sendError($from, 'server_error', 'Match end data invalid');
                    return;
                }
                
                $gameId = $gameService->getGameId();
                
                // Save snapshot if state is provided, otherwise use current snapshot
                if ($gameId && isset($result['state'])) {
                    $stateAfter = $result['state'];
                    $newVersion = $gameService->getVersion();
                    $this->persistenceService->saveSnapshot($gameId, $stateAfter, $newVersion);
                }
                
                // Clean up DB state for this game
                if ($gameId) {
                    db_delete_game($this->pdo, $gameId);
                    db_delete_snapshots($this->pdo, $gameId);
                }
            
                // Clear seats at this table so players return to lobby
                db_clear_table_seats($this->pdo, $tableId);
            
                // Get board and players from state snapshot (for first-hand all-ins)
                $stateSnapshot = isset($result['state']) ? $result['state'] : $gameService->getSnapshot();
                $board = $stateSnapshot['board'] ?? [];
                $players = $stateSnapshot['players'] ?? [];
            
                // Extract player cards for match end (all cards should be revealed)
                $playerData = [];
                foreach ($players as $seat => $p) {
                    $playerData[$seat] = [
                        'seat'         => $seat,
                        'user_id'      => $p['user_id'] ?? 0,
                        'cards'        => $p['cards'] ?? [],
                        'folded'       => $p['folded'] ?? false,
                        'stack'        => $p['stack'] ?? 0,
                        'bet'          => $p['bet'] ?? 0,
                    ];
                }
            
                // Broadcast final match result
                $this->broadcast($tableId, [
                    'event'  => 'match_end',
                    'winner' => $winner,
                    'loser'  => $loser,
                    'reason' => $reason, // Include reason (forfeit/fold/showdown)
                    'board'  => $board,  // Include board for first-hand all-ins
                    'players' => $playerData, // Include player cards for first-hand all-ins
                ]);

                // ======================================================================
                // NEW: Immediately update presence for BOTH players after match ends
                // ======================================================================
                try {
                    if (isset($this->userConnections[$tableId])) {
                        foreach ($this->userConnections[$tableId] as $uid => $conns) {
                            // Determine username from first connection
                            $username = null;
                            if (!empty($conns)) {
                                $rid = $conns[0]->resourceId;
                                $username = $this->connInfo[$rid]['username'] ?? null;
                            }

                            // Update DB presence → back to online
                            db_upsert_presence($this->pdo, $uid, $username, 'online');

                            // Notify lobby instantly
                            if ($this->lobbySocket !== null) {
                                $this->lobbySocket->broadcastPresenceUpdate($uid, $username, 'online');
                            }

                            error_log("[GameSocket] Presence updated to ONLINE after match_end for user {$uid}");
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("[GameSocket] Failed to update presence on match_end: " . $e->getMessage());
                }

                // ======================================================================
                // NEW: Clear pending disconnect timers for this table
                // ======================================================================
                unset($this->pendingDisconnects[$tableId]);
                unset($this->lastSeen[$tableId]);
            
                // Clear in-memory game mapping for this table
                unset(
                    $this->gameServices[$tableId],
                    $this->tableIdToGameId[$tableId],
                    $this->tableBootstrapped[$tableId]
                );
            
                // Still process any pending disconnect timers
                $this->processPendingDisconnects();
                return; // DO NOT send further state; table is done
            }
            
            // Normal path: save snapshot and broadcast state updates
            $gameId = $gameService->getGameId();
            if ($gameId && isset($result['state'])) {
                $stateAfter  = $result['state'];
                $newVersion  = $gameService->getVersion();
                $this->persistenceService->saveSnapshot($gameId, $stateAfter, $newVersion);
            }
            
            // If the hand ended, always broadcast the hand summary for the overlay
            if ($handEnded && $handSummary !== null) {
                // Enrich hand summary with usernames from tableSeats cache
                $enrichedSummary = $this->enrichHandSummaryWithUsernames($handSummary, $tableId);
                $this->broadcast($tableId, [
                    'event'   => 'hand_end',
                    'summary' => $enrichedSummary,
                ]);
            }
                
            // Normal continuing state: broadcast public diff + private state
            $stateToBroadcast = $result['state'] ?? $gameService->getSnapshot();
            $this->broadcastStateUpdate($tableId, $stateToBroadcast, $gameService->getVersion());
    
            // Process pending disconnect checks (async debouncing)
            $this->processPendingDisconnects();
    
            // Send private to acting player (redundant but immediate)
            $privateState = $this->buildPrivateState($stateToBroadcast, $seat, $gameId ?? 0);
            $from->send(json_encode([
                'type'  => 'STATE_PRIVATE',
                'state' => $privateState,
            ]));
        } catch (\ValueError $e) {
            $this->sendError($from, 'invalid_action');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("[GameSocket] Action error: " . $e->getMessage());
            $this->sendError($from, 'server_error');
        }
    }
        
    private function handleNextHand(ConnectionInterface $from, array $info): void
    {
        $tableId = (int)$info['table_id'];
        $gameService = $this->gameServices[$tableId] ?? null;
    
        if (!$gameService) {
            $this->sendError($from, 'game_not_found');
            return;
        }
    
        try {
            $this->pdo->beginTransaction();
    
            $result = $gameService->startHand(); // now may return matchEnded
            $this->pdo->commit();
    
            // ============================
            // 1. MATCH END CASE
            // ============================
            if (($result['matchEnded'] ?? false) === true) {
                $winner = $result['winner'] ?? null;
                $loser  = $result['loser']  ?? null;
                $reason = $result['reason'] ?? null;
                
                // Validate winner/loser data before proceeding
                if (!$winner || !$loser) {
                    error_log("[GameSocket] Match end in handleNextHand but winner/loser missing: tableId={$tableId}");
                    $this->sendError($from, 'server_error', 'Match end data invalid');
                    return;
                }
                
                $gameId = $gameService->getGameId();
    
                // Wipe state from DB
                if ($gameId) {
                    db_delete_game($this->pdo, $gameId);
                    db_delete_snapshots($this->pdo, $gameId);
                }
    
                // Remove table seats (people leave table)
                db_clear_table_seats($this->pdo, $tableId);
    
                // Get board and players from state snapshot (for first-hand all-ins)
                $stateSnapshot = isset($result['state']) ? $result['state'] : $gameService->getSnapshot();
                $board = $stateSnapshot['board'] ?? [];
                $players = $stateSnapshot['players'] ?? [];
    
                // Extract player cards for match end (all cards should be revealed)
                $playerData = [];
                foreach ($players as $seat => $p) {
                    $playerData[$seat] = [
                        'seat'         => $seat,
                        'user_id'      => $p['user_id'] ?? 0,
                        'cards'        => $p['cards'] ?? [],
                        'folded'       => $p['folded'] ?? false,
                        'stack'        => $p['stack'] ?? 0,
                        'bet'          => $p['bet'] ?? 0,
                    ];
                }
    
                // Broadcast final result
                $this->broadcast($tableId, [
                    'event'  => 'match_end',
                    'winner' => $winner,
                    'loser'  => $loser,
                    'reason' => $reason, // Include reason (forfeit/fold/showdown)
                    'board'  => $board,  // Include board for first-hand all-ins
                    'players' => $playerData, // Include player cards for first-hand all-ins
                ]);

                $unameWinner = $winner['username'] ?? ("User#" . $winner['user_id']);
                $unameLoser  = $loser['username']  ?? ("User#" . $loser['user_id']);

                try {
                    if ($this->lobbySocket !== null) {
                        $this->lobbySocket->broadcastPresenceUpdate(
                            (int)$winner['user_id'],
                            $unameWinner,
                            'online'
                        );

                        $this->lobbySocket->broadcastPresenceUpdate(
                            (int)$loser['user_id'],
                            $unameLoser,
                            'online'
                        );
                    }

                    db_upsert_presence($this->pdo, (int)$winner['user_id'], $unameWinner, 'online');
                    db_upsert_presence($this->pdo, (int)$loser['user_id'],  $unameLoser,  'online');

                } catch (\Throwable $e) {
                    error_log("[GameSocket] Presence update failed after match_end in handleNextHand: " . $e->getMessage());
                }
    
                // Clear in-memory game mapping for this table
                unset(
                    $this->gameServices[$tableId],
                    $this->tableIdToGameId[$tableId],
                    $this->tableBootstrapped[$tableId]
                );
                
                // Process pending disconnect timers
                $this->processPendingDisconnects();
    
                // Let frontend navigate away
                return;
            }
    
            // ============================
            // 2. NORMAL NEW HAND START
            // ============================
            // Note: hand_start event, version bump, and snapshot are already handled in GameService::startHand()
            // We just need to send private state to each player
            $gameId = $gameService->getGameId();
            $state = $result['state'] ?? $gameService->getSnapshot();
    
            // Send private cards to each player
            foreach ($this->userConnections[$tableId] ?? [] as $uid => $conns) {
                if (empty($conns)) continue;
    
                $firstRid = (int)$conns[0]->resourceId;
                $playerInfo = $this->connInfo[$firstRid] ?? null;
                if (!$playerInfo) continue;
    
                $seat = (int)$playerInfo['seat'];
                $privateState = $this->buildPrivateState($state, $seat, $gameId ?? 0);
    
                foreach ($conns as $conn) {
                    try {
                        $conn->send(json_encode([
                            'type'  => 'STATE_PRIVATE',
                            'seat'  => $seat,
                            'state' => $privateState,
                        ]));
                    } catch (\Throwable $e) {
                        error_log("[GameSocket] Private state failed: " . $e->getMessage());
                    }
                }
            }
    
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("[GameSocket] Next hand error: " . $e->getMessage());
            $this->sendError($from, 'server_error');
        }
    }    

    private function handleChat(ConnectionInterface $from, array $data, array $info): void
    {
        $text = trim(mb_substr((string)($data['msg'] ?? ''), 0, self::CHAT_MAX_CHARS));
        if ($text === '') {
            $this->sendError($from, 'empty_message');
            return;
        }

        $tableId  = (int)$info['table_id'];
        $gameId   = (int)($info['game_id'] ?? 0);
        $userId   = (int)$info['user_id'];
        $username = $info['username'] ?? "User#{$userId}";

        // Allow chat even when no active game (gameId = 0 means table-level chat)
        // Store with game_id = 0, which will be retrieved for the table
        $msgId = db_insert_chat_message($this->pdo, 'game', $gameId, $userId, $text, null, $username);

        // Retrieve canonical timestamp from DB to prevent clock skew in UI
        $stmt = $this->pdo->prepare("SELECT created_at FROM chat_messages WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $msgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $createdAt = $row ? $row['created_at'] : date('Y-m-d H:i:s');
        $timeStr   = date('H:i', strtotime($createdAt));

        $this->broadcast($tableId, [
            'type'       => 'CHAT',
            'from'       => escape_html($username),
            'msg'        => escape_html($text),
            'time'       => $timeStr,
            'created_at' => $createdAt,
        ]);

        // Process pending disconnect checks (async debouncing)
        $this->processPendingDisconnects();
    }

    // -------------------------------------------------------------------------
    // State synchronization
    // -------------------------------------------------------------------------

    private function syncGameState(
        ConnectionInterface $conn,
        int $tableId,
        int $seat,
        ?int $gameId
    ): void {
        if (!$gameId) {
            $this->sendStateSync($conn, $tableId, $seat, 'connect');
            return;
        }

        $gameService = $this->gameServices[$tableId] ?? null;
        if (!$gameService) {
            $this->sendStateSync($conn, $tableId, $seat, 'connect');
            return;
        }

        // Rebuild gate: only rebuild if service is behind DB AND there are NO active connections
        // This prevents rebuilds during refreshes when other players are still connected
        $dbVersion      = $this->getGameVersion($gameId);
        $serviceVersion = $gameService->getVersion();

        if ($serviceVersion <= 0 || $dbVersion > $serviceVersion) {
            // CRITICAL: Check connection count INSIDE this method to avoid race conditions
            // Use userConnections to count unique users, not just connections (more accurate)
            $activeUserCount = isset($this->userConnections[$tableId]) 
                ? count($this->userConnections[$tableId]) 
                : 0;
            $activeConnectionCount = count($this->tableConnections[$tableId] ?? []);
            
            // Only rebuild when there are truly NO active connections (table is completely empty)
            // This ensures refreshes never trigger rebuilds that affect other players
            if ($activeConnectionCount === 0 && $activeUserCount === 0 && !isset($this->rebuildingTables[$tableId])) {
                $this->rebuildFromDatabase($tableId, $gameId, $gameService);
            } elseif ($activeConnectionCount > 0 || $activeUserCount > 0) {
                // Log when we skip rebuild due to active connections (version mismatch exists but table has players)
                echo "[GameSocket] Skipping rebuild for table #{$tableId} ({$activeUserCount} user(s), {$activeConnectionCount} conn(s))\n";
            }
        }

        $this->sendStateSync($conn, $tableId, $seat, 'connect');
    }

    /**
     * Rebuild game state from database (snapshot + actions).
     * HARDENED: Only touches GameService, never modifies connection tracking arrays.
     * This ensures other players' connections remain unaffected.
     */
    private function rebuildFromDatabase(int $tableId, int $gameId, GameService $gameService): void
    {
        // Abort if players appeared during rebuild
        $activeConnectionCount = count($this->tableConnections[$tableId] ?? []);
        $activeUserCount = isset($this->userConnections[$tableId])
            ? count($this->userConnections[$tableId])
            : 0;
    
        if ($activeConnectionCount > 0 || $activeUserCount > 0) {
            echo "[GameSocket] Aborting rebuild for table #{$tableId} (connections appeared)\n";
            return;
        }
    
        $this->rebuildingTables[$tableId] = true;
    
        try {
            // Snapshot-only restore
            $snapshot = $this->persistenceService->loadLatest($gameId); // uses db_get_latest_snapshot
    
            if ($snapshot && !empty($snapshot['state'])) {
                $this->restoreFromSnapshot(
                    $gameService,
                    $snapshot['state'],
                    (int)($snapshot['version'] ?? 0)
                );
                echo "[GameSocket] Snapshot restored for table #{$tableId}\n";
            } else {
                echo "[GameSocket] No snapshot found for game #{$gameId}; skipping rebuild.\n";
            }
        } catch (\Throwable $e) {
            error_log("[GameSocket] Rebuild failed: " . $e->getMessage());
        } finally {
            unset($this->rebuildingTables[$tableId]);
        }
    }    

    private function restoreFromSnapshot(GameService $gameService, array $state, int $version): void
    {
        $reflection = new \ReflectionClass($gameService);

        // Players
        $players = [];
        if (isset($state['players']) && is_array($state['players'])) {
            foreach ($state['players'] as $seat => $row) {
                $players[(int)$seat] = new PlayerState(
                    (int)$seat,
                    (int)($row['stack'] ?? 0),
                    (int)($row['bet'] ?? 0),
                    (bool)($row['folded'] ?? false),
                    (bool)($row['allIn'] ?? false),
                    (array)($row['cards'] ?? []),
                    isset($row['handRank']) ? (int)$row['handRank'] : null,
                    isset($row['handDescription']) ? (string)$row['handDescription'] : null
                );
            }
        }

        $props = [
            'players'        => $players,
            'board'          => (array)($state['board'] ?? []),
            'phase'          => Phase::from($state['phase'] ?? 'preflop'),
            'currentBet'     => (int)($state['currentBet'] ?? 0),
            'pot'            => (int)($state['pot'] ?? 0),
            'dealerSeat'     => (int)($state['dealerSeat'] ?? 0),
            'smallBlindSeat' => (int)($state['smallBlindSeat'] ?? 0),
            'bigBlindSeat'   => (int)($state['bigBlindSeat'] ?? 0),
            'actionSeat'     => (int)($state['actionSeat'] ?? 0),
            'version'        => $version,
        ];

        foreach ($props as $name => $value) {
            $p = $reflection->getProperty($name);
            $p->setAccessible(true);
            $p->setValue($gameService, $value);
        }
    }

    private function sendStateSync(ConnectionInterface $conn, int $tableId, int $seat, string $reason = 'connect'): void
    {
        $gameService = $this->gameServices[$tableId] ?? null;
        $gameId = $this->tableIdToGameId[$tableId] ?? ($gameService ? $gameService->getGameId() : null) ?? 0;

        if (!$gameService) {
            $conn->send(json_encode([
                'type'    => 'STATE_SYNC',
                'reason'  => $reason,
                'game_id' => $gameId,
                'version' => 0,
                'state'   => [
                    'phase'      => 'waiting',
                    'board'      => [],
                    'pot'        => 0,
                    'currentBet' => 0,
                    'actionSeat' => null,
                    'players'    => [],
                ],
            ]));
            return;
        }

        $snapshot = $gameService->getSnapshot();
        $version  = $gameService->getVersion();

        // Public state
        $conn->send(json_encode([
            'type'    => 'STATE_SYNC',
            'reason'  => $reason,
            'game_id' => $gameId,
            'version' => $version,
            'state'   => $this->buildPublicState($snapshot, $tableId),
        ]));

        // Private state
        $conn->send(json_encode([
            'type'    => 'STATE_PRIVATE',
            'game_id' => $gameId,
            'seat'    => $seat,
            'state'   => $this->buildPrivateState($snapshot, $seat, $gameId),
        ]));
    }

    private function broadcastStateUpdate(int $tableId, array $state, int $version): void
    {
        if (empty($this->userConnections[$tableId])) {
            return;
        }

        $gameId      = $this->tableIdToGameId[$tableId] ?? 0;
        $publicState = $this->buildPublicState($state, $tableId);

        // MULTI-CONNECTION: Broadcast to all connections for each user
        foreach ($this->userConnections[$tableId] as $uid => $conns) {
            if (empty($conns)) {
                continue;
            }

            // Get seat from first connection (all connections for same user have same seat)
            $firstRid = (int)$conns[0]->resourceId;
            $info     = $this->connInfo[$firstRid] ?? null;
            if (!$info) {
                continue;
            }

            $seat = (int)$info['seat'];

            // Send to all connections for this user
            foreach ($conns as $conn) {
                try {
                    // Public diff
                    $conn->send(json_encode([
                        'type'    => 'STATE_DIFF',
                        'game_id' => $gameId,
                        'version' => $version,
                        'state'   => $publicState,
                    ]));

                    // Private view for this seat
                    $conn->send(json_encode([
                        'type'    => 'STATE_PRIVATE',
                        'game_id' => $gameId,
                        'seat'    => $seat,
                        'state'   => $this->buildPrivateState($state, $seat, $gameId),
                    ]));
                } catch (\Throwable $e) {
                    error_log("[GameSocket] State update failed: " . $e->getMessage());
                }
            }
        }
    }

    private function buildPublicState(array $state, int $tableId): array
    {
        $public = [
            'phase'          => $state['phase'],
            'board'          => $state['board'],
            'pot'            => $state['pot'],
            'currentBet'     => $state['currentBet'],
            'actionSeat'     => $state['actionSeat'],
            'dealerSeat'     => $state['dealerSeat'],
            'smallBlindSeat' => $state['smallBlindSeat'],
            'bigBlindSeat'   => $state['bigBlindSeat'],
            'players'        => [],
        ];

        // ✅ Use cached seat data (no DB lookups)
        $seatUsernames = [];
        $seatUserIds   = [];
        if (isset($this->tableSeats[$tableId])) {
            foreach ($this->tableSeats[$tableId] as $seatNo => $data) {
                $seatUserIds[$seatNo] = $data['user_id'];
                $seatUsernames[$seatNo] = $data['username'];
            }
        }

        foreach ($state['players'] as $seat => $p) {
            $public['players'][$seat] = [
                'seat'            => $p['seat'],
                'stack'           => $p['stack'],
                'bet'             => $p['bet'],
                'folded'          => $p['folded'],
                'allIn'           => $p['allIn'],
                'username'        => $seatUsernames[$seat] ?? "Seat{$seat}",
                'user_id'         => $seatUserIds[$seat] ?? null,
                'handRank'        => $p['handRank'],
                'handDescription' => $p['handDescription'],
            ];
        }

        return $public;
    }

    /**
     * Enrich hand summary with usernames from tableSeats cache.
     * Adds 'username' field to each player in the summary's players array.
     */
    private function enrichHandSummaryWithUsernames(array $summary, int $tableId): array
    {
        if (!isset($summary['players']) || !is_array($summary['players'])) {
            return $summary;
        }

        // Get username mapping from cached table seats
        $seatUsernames = [];
        if (isset($this->tableSeats[$tableId])) {
            foreach ($this->tableSeats[$tableId] as $seatNo => $data) {
                $seatUsernames[$seatNo] = $data['username'] ?? null;
            }
        }

        // Add usernames to each player in the summary
        $enrichedPlayers = [];
        foreach ($summary['players'] as $seat => $player) {
            $seatNum = is_numeric($seat) ? (int)$seat : (int)($player['seat'] ?? $seat);
            $enrichedPlayers[$seat] = array_merge($player, [
                'username' => $seatUsernames[$seatNum] ?? "Seat{$seatNum}",
            ]);
        }

        $summary['players'] = $enrichedPlayers;
        return $summary;
    }

    private function buildPrivateState(array $state, int $seat, int $gameId): array
    {
        $cards = [];
        $legal = [];

        $tableId = array_search($gameId, $this->tableIdToGameId ?? [], true);
        if ($tableId !== false && isset($this->gameServices[$tableId])) {
            $svc = $this->gameServices[$tableId];
            $cards = $svc->getSeatCards($seat);
            $legal = $svc->getLegalActionsForSeat($seat);
        } else {
            $cards = $state['players'][$seat]['cards'] ?? [];
        }

        return [
            'mySeat' => $seat,
            'myCards' => $cards,
            'legalActions' => $legal,
        ];
    }

    // -------------------------------------------------------------------------
    // Connection management (multi-connection support)
    // -------------------------------------------------------------------------

    /**
     * Remove connection from all tracking structures for THIS table only.
     * MULTI-CONNECTION: Removes from array of connections per user.
     * Safe to call multiple times (idempotent).
     */
    private function removeConnection(ConnectionInterface $conn, int $tableId): void
    {
        $rid  = (int)$conn->resourceId;
        $info = $this->connInfo[$rid] ?? null;

        // Always detach from SplObjectStorage
        $this->clients->detach($conn);

        if (!$info) {
            return;
        }

        if ((int)$info['table_id'] !== $tableId) {
            error_log("[GameSocket] Warning: removeConnection called with mismatched table_id");
            unset($this->connInfo[$rid]);
            return;
        }

        $uid = (int)$info['user_id'];

        // Remove from tableConnections
        if (isset($this->tableConnections[$tableId])) {
            $this->tableConnections[$tableId] = array_values(array_filter(
                $this->tableConnections[$tableId],
                static fn($c) => $c !== $conn
            ));
            if (empty($this->tableConnections[$tableId])) {
                unset($this->tableConnections[$tableId]);
            }
        }

        // MULTI-CONNECTION: Remove from array of connections for this user
        if (isset($this->userConnections[$tableId][$uid])) {
            $this->userConnections[$tableId][$uid] = array_values(array_filter(
                $this->userConnections[$tableId][$uid],
                static fn($c) => $c !== $conn
            ));
            
            // Clean up empty arrays
            if (empty($this->userConnections[$tableId][$uid])) {
                unset($this->userConnections[$tableId][$uid]);
            }
            if (empty($this->userConnections[$tableId])) {
                unset($this->userConnections[$tableId]);
            }
        }

        unset($this->connInfo[$rid]);
    }

    /**
     * Process pending disconnect broadcasts (async, non-blocking).
     * Called during action/chat handling to check if users truly disconnected.
     * Only broadcasts if delay period passed AND user hasn't reconnected.
     * 
     * HARDENED: Double-checks connection state before broadcasting to prevent false disconnects.
     */
    private function processPendingDisconnects(): void
    {
        $now = microtime(true) * 1000; // ms

        foreach ($this->pendingDisconnects as $tableId => $userTimers) {
            foreach ($userTimers as $uid => $timer) {
                $elapsed = $now - $timer['timestamp'];

                if ($elapsed >= self::DISCONNECT_DELAY_MS) {
                    // CRITICAL: Double-check that user truly has no active connections
                    // This prevents false disconnects if user reconnected during the delay
                    $hasActiveConnections = !empty($this->userConnections[$tableId][$uid] ?? []);
                    
                    // Also verify the connection is actually gone (not just empty array)
                    if (!$hasActiveConnections && !isset($this->userConnections[$tableId][$uid])) {
                        // User truly disconnected - broadcast away status
                        $this->broadcast($tableId, [
                            'type'     => 'PLAYER_AWAY',
                            'seat_no'  => $timer['seat'],
                            'username' => $timer['username'],
                        ]);
                        echo "[GameSocket] {$timer['username']} marked AWAY on table #{$tableId}\n";
                    }

                    // Always remove timer (whether broadcasted or not) to prevent re-processing
                    unset($this->pendingDisconnects[$tableId][$uid]);
                }
            }

            if (empty($this->pendingDisconnects[$tableId])) {
                unset($this->pendingDisconnects[$tableId]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    private function sendError(ConnectionInterface $conn, string $error, string $message = ''): void
    {
        try {
            $conn->send(json_encode(['type' => 'error', 'error' => $error, 'message' => $message]));
            if (\in_array($error, ['missing_request', 'invalid_table_id', 'not_seated', 'unauthorized'], true)) {
                $conn->close();
            }
        } catch (\Throwable) {
            // ignore
        }
    }

    private function sendChatHistory(ConnectionInterface $conn, int $gameId): void
    {
        // Allow chat history even when gameId is 0 (table-level chat before game starts)
        $recent = db_get_recent_chat_messages($this->pdo, 'game', $gameId, self::CHAT_HISTORY_SIZE);
        $messages = array_map(static function ($m) {
            $timeStr = date('H:i', strtotime($m['created_at']));
            return [
                'from'       => escape_html($m['sender_username']),
                'msg'        => escape_html($m['body']),
                'time'       => $timeStr,
                'created_at' => $m['created_at'],
            ];
        }, $recent);

        $conn->send(json_encode([
            'type'     => 'CHAT_HISTORY',
            'messages' => $messages,
        ]));
    }

    private function getGameVersion(int $gameId): int
    {
        // If no 'version' column, treat as 0 for backward compatibility
        $stmt = $this->pdo->query("SHOW COLUMNS FROM games LIKE 'version'");
        if ($stmt && $stmt->rowCount() > 0) {
            $st = $this->pdo->prepare("SELECT version FROM games WHERE id = :game_id LIMIT 1");
            $st->execute(['game_id' => $gameId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return (int)($row['version'] ?? 0);
        }
        return 0;
    }

    private function rateAllow(int $rid): bool
    {
        if (!isset($this->connInfo[$rid]['rate'])) {
            $this->connInfo[$rid]['rate'] = ['ts' => microtime(true), 'tokens' => self::RATE_TOKENS];
            return true;
        }

        $now   = microtime(true);
        $state = &$this->connInfo[$rid]['rate'];
        $elapsed = max(0.0, $now - $state['ts']);
        $state['ts'] = $now;

        $state['tokens'] = min(self::RATE_TOKENS, $state['tokens'] + $elapsed * self::RATE_REFILL_PER_S);
        if ($state['tokens'] < 1.0) {
            return false;
        }
        $state['tokens'] -= 1.0;
        return true;
    }

    // -------------------------------------------------------------------------
    // Broadcasting
    // -------------------------------------------------------------------------

    private function broadcast(int $tableId, array $message): void
    {
        if (empty($this->userConnections[$tableId])) {
            return;
        }

        $eventType = $message['event'] ?? $message['type'] ?? 'unknown';
        $sentCount = 0;
        $errorCount = 0;

        // MULTI-CONNECTION: Broadcast to all connections for all users at this table
        foreach ($this->userConnections[$tableId] as $uid => $conns) {
            foreach ($conns as $conn) {
                try {
                    $conn->send(json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    $sentCount++;
                } catch (\Throwable $e) {
                    $errorCount++;
                    error_log("[GameSocket] Broadcast failed for table {$tableId}, user {$uid}: " . $e->getMessage());
                }
            }
        }
        
        // Log if all broadcasts failed (critical for match_end)
        if ($errorCount > 0 && $sentCount === 0 && $eventType === 'match_end') {
            error_log("[GameSocket] CRITICAL: All match_end broadcasts failed for table {$tableId}");
        }
    }

    /**
     * Static method to broadcast by game ID (called from GameService).
     * Looks up the table ID from the game ID mapping and broadcasts to that table.
     */
    public static function broadcastByGameId(int $gameId, array $message): void
    {
        if (self::$instance === null) {
            error_log("[GameSocket] Cannot broadcast: no instance available");
            return;
        }

        // Find table ID from game ID
        $tableId = array_search($gameId, self::$instance->tableIdToGameId, true);
        if ($tableId === false) {
            error_log("[GameSocket] Cannot broadcast: game ID {$gameId} not found in tableIdToGameId mapping");
            return;
        }

        self::$instance->broadcast((int)$tableId, $message);
    }

    // -------------------------------------------------------------------------
    // Game service management
    // -------------------------------------------------------------------------

    /**
     * Ensure a GameService instance exists for the specified table.
     * Reuses existing service if present; otherwise creates one.
     * Returns the current game_id (may be null if no active game).
     * 
     * HARDENED: Only touches $gameServices and $tableIdToGameId arrays.
     * Never modifies connection tracking arrays ($tableConnections, $userConnections).
     */
    private function ensureGameService(int $tableId, ?int $dbGameId): ?int
    {
        // Reuse existing service
        if (isset($this->gameServices[$tableId])) {
            // Check if we already have a gameId tracked for this table
            if (isset($this->tableIdToGameId[$tableId])) {
                return $this->tableIdToGameId[$tableId];
            }
        }

        // Create new service
        $persistence = new GamePersistence($this->pdo, self::SNAPSHOT_MAX_GAP);
        
        if ($dbGameId) {
            $service = new GameService($persistence, 10, 20);
            $service->setGameId($dbGameId);
            $this->gameServices[$tableId] = $service;
            $this->tableIdToGameId[$tableId] = $dbGameId;
            return $dbGameId;
        }

        // No active game yet → initialize blank service
        $service = new GameService($persistence, 10, 20);
        $this->gameServices[$tableId] = $service;
        return null;
    }

    private function ensureHandBootstrapped(int $tableId, ?int $gameId): void
    {
        $svc = $this->gameServices[$tableId] ?? null;
        if (!$svc) return;
    
        if (!empty($this->tableBootstrapped[$tableId])) {
            return;
        }
    
        // Load seated players
        $seats = db_get_table_seats($this->pdo, $tableId);
        $active = array_values(array_filter(
            $seats,
            fn($r) => $r['user_id'] !== null && $r['left_at'] === null
        ));
    
        if (count($active) < 2) {
            return; // not enough players
        }
    
        // Convert DB seat rows -> state players
        $players = array_map(
            fn($r) => [
                'seat'  => (int)$r['seat_no'],
                'stack' => 1000, // default starting stack
            ],
            $active
        );
    
        $svc->loadPlayers($players);
    
        // Try to start the first hand
        $result = $svc->startHand();
    
        // Match is already over before any hand starts
        if (($result['matchEnded'] ?? false) === true) {
            $winner = $result['winner'] ?? null;
            $loser  = $result['loser']  ?? null;
            $reason = $result['reason'] ?? null;
            
            // Validate winner/loser data before proceeding
            if (!$winner || !$loser) {
                error_log("[GameSocket] Match end in ensureHandBootstrapped but winner/loser missing: tableId={$tableId}");
                // Don't broadcast invalid data, but mark as bootstrapped to prevent retry
                $this->tableBootstrapped[$tableId] = true;
                return;
            }
    
            // Remove table seats
            db_clear_table_seats($this->pdo, $tableId);
    
            // Get board and players from state snapshot (for first-hand all-ins)
            $stateSnapshot = isset($result['state']) ? $result['state'] : $svc->getSnapshot();
            $board = $stateSnapshot['board'] ?? [];
            $players = $stateSnapshot['players'] ?? [];
    
            // Extract player cards for match end (all cards should be revealed)
            $playerData = [];
            foreach ($players as $seat => $p) {
                $playerData[$seat] = [
                    'seat'         => $seat,
                    'user_id'      => $p['user_id'] ?? 0,
                    'cards'        => $p['cards'] ?? [],
                    'folded'       => $p['folded'] ?? false,
                    'stack'        => $p['stack'] ?? 0,
                    'bet'          => $p['bet'] ?? 0,
                ];
            }
    
            // Broadcast match end
            $this->broadcast($tableId, [
                'event'  => 'match_end',
                'winner' => $winner,
                'loser'  => $loser,
                'reason' => $reason, // Include reason (forfeit/fold/showdown)
                'board'  => $board,  // Include board for first-hand all-ins
                'players' => $playerData, // Include player cards for first-hand all-ins
            ]);
    
            // Clear in-memory game mapping for this table
            unset(
                $this->gameServices[$tableId],
                $this->tableIdToGameId[$tableId],
                $this->tableBootstrapped[$tableId]
            );
            
            // Process pending disconnect timers
            $this->processPendingDisconnects();
            return;
        }
    
        // Persist initial hand snapshot
        if ($gameId = $svc->getGameId()) {
            $this->persistenceService->saveSnapshot($gameId, $result, $svc->getVersion());
        }
    
        $this->tableBootstrapped[$tableId] = true;
    }      
}
