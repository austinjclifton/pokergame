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
use React\EventLoop\LoopInterface;

require_once __DIR__ . '/../app/services/game/GameService.php';
require_once __DIR__ . '/../app/services/SubscriptionService.php';
require_once __DIR__ . '/../app/services/SocketPresenceService.php';
require_once __DIR__ . '/LobbySocket.php';

require_once __DIR__ . '/../app/db/game_snapshots.php';
require_once __DIR__ . '/../app/db/games.php';
require_once __DIR__ . '/../app/db/users.php';
require_once __DIR__ . '/../app/db/chat_messages.php';
require_once __DIR__ . '/../app/db/table_seats.php';
require_once __DIR__ . '/../app/db/tables.php';

require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/TokenBucketRateLimiter.php';
require_once __DIR__ . '/../lib/WebSocketLog.php';
require_once __DIR__ . '/../lib/WebSocketJson.php';

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
    private const DISCONNECT_SWEEP_INTERVAL_S = 0.5;

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    protected PDO $pdo;
    protected SubscriptionService $subscriptionService;
    protected SocketPresenceService $presenceService;
    protected ?LobbySocket $lobbySocket = null;

    /**
     * Connection metadata by Ratchet resourceId.
     * @var array<int, array{
    *   user_id:int, username:string, table_id:int, seat:int, game_id:int,
     *   rate:array{ts:float,tokens:float}
     * }>
     */
    protected array $connInfo = [];

    /** @var array<int, GameService> table_id => GameService */
    protected array $gameServices = [];

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

    /** @var array<int, bool> table_id => bootstrapped flag */
    protected array $tableBootstrapped = [];

    private bool $usesPeriodicDisconnectSweep = false;

    public function __construct(PDO $pdo, ?LobbySocket $lobbySocket = null, ?LoopInterface $loop = null)
    {
        $this->pdo = $pdo;
        $this->subscriptionService = new SubscriptionService($pdo);
        $this->presenceService = new SocketPresenceService($pdo);
        $this->lobbySocket = $lobbySocket;

        if ($loop !== null) {
            $this->registerDisconnectSweep($loop);
        }

        WebSocketLog::debug('GameSocket', 'Initialized');
    }

    // -------------------------------------------------------------------------
    // Ratchet lifecycle
    // -------------------------------------------------------------------------

    public function onOpen(ConnectionInterface $conn): void
    {
        // AuthenticatedServer calls onAuthenticated() after attaching userCtx.
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
                WebSocketJson::closeQuietly($conn);
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
            if (!$activeGame) {
                $this->sendError($conn, 'game_not_found', 'No active game for table');
                WebSocketJson::closeQuietly($conn);
                return;
            }

            $dbGameId = (int)$activeGame['id'];
            $gameId   = $this->ensureGameService($tableId, $dbGameId);

            // --- connection bookkeeping ---
            $this->connInfo[$rid] = [
                'user_id'  => $uid,
                'username' => $uname,
                'table_id' => $tableId,
                'seat'     => $seat,
                'game_id'  => $gameId,
                'rate'     => TokenBucketRateLimiter::seed(self::RATE_TOKENS),
            ];
            $this->subscriptionService->register($uid, (string)$rid, 'game', $gameId);

            $this->userConnections[$tableId][$uid][] = $conn;
            $this->tableSeats[$tableId][$seat] = ['user_id'=>$uid,'username'=>$uname];

            // --- reconnect detection ---
            $now = self::nowMs();
            $last = $this->lastSeen[$tableId][$uid] ?? 0;
            $isReconnect = ($now - $last) < 3000; // 3-second window
            $this->lastSeen[$tableId][$uid] = $now;

            // --- cancel pending disconnects ---
            $this->clearPendingDisconnect($tableId, $uid);

            $isFirstConnection = count($this->userConnections[$tableId][$uid]) === 1;

            // Update presence to 'in_game' when user connects to a game
            // Always update (even on refresh) to ensure presence reflects current state
            try {
                $presenceUser = $this->presenceService->syncGameConnection($uid, $uname, $tableId);
                $this->broadcastLobbyPresenceUpdate($presenceUser);
            } catch (\Throwable $e) {
                WebSocketLog::warn('GameSocket', "Failed to update in-game presence for user {$uid}: " . $e->getMessage());
            }

            $this->ensureHandBootstrapped(
                $tableId,
                $gameId,
                isset($activeGame['deck_seed']) ? (int)$activeGame['deck_seed'] : null
            );
            $this->sendStateSync($conn, $tableId, $seat);
            $this->sendChatHistory($conn, $gameId);

            // --- only broadcast if truly new user, not quick reconnect ---
            if ($isFirstConnection && !$isReconnect) {
                $this->broadcast($tableId, [
                    'type'     => 'PLAYER_CONNECTED',
                    'seat_no'  => $seat,
                    'username' => $uname,
                ]);
            }

            $connCount = count($this->userConnections[$tableId][$uid]);
            WebSocketLog::debug('GameSocket', "{$uname} connected to table #{$tableId} (game #{$gameId}, seat {$seat}, {$connCount} conn(s))");

        } catch (Throwable $e) {
            WebSocketLog::error('GameSocket', 'onAuthenticated failed: ' . $e->getMessage());
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
                'next_hand'     => $this->handleNextHand($from, $data, $info),
                'chat'          => $this->handleChat($from, $data, $info),
                'chat_history'  => $this->sendChatHistory($from, (int)$info['game_id']),
            
                default         => $this->sendError($from, 'unknown_type'),
            };            
        } catch (\Throwable $e) {
            WebSocketLog::error('GameSocket', 'Message handler failed: ' . $e->getMessage());
            $this->sendError($from, 'server_error');
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $rid  = (int)$conn->resourceId;
        $info = $this->connInfo[$rid] ?? null;

        // If we never registered, just drop
        if (!$info) {
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
                    // Always update to 'online' when user disconnects from their last game connection.
                    // They may still have a seat (can rejoin), but they're not actively in the game anymore.
                    $presenceUser = $this->presenceService->syncOnlineAfterGameDisconnect($uid, $uname, false);
                    $this->broadcastLobbyPresenceUpdate($presenceUser);

                    WebSocketLog::debug('GameSocket', "Updated user {$uid} ({$uname}) presence to online after disconnecting from table #{$tableId} (isLastConnection={$isLastConnection}, hasOtherActiveGames={$hasOtherActiveGames})");
                } catch (\Throwable $e) {
                    WebSocketLog::warn('GameSocket', "Failed to update online presence for user {$uid}: " . $e->getMessage());
                }
            } else {
                WebSocketLog::debug('GameSocket', "User {$uid} ({$uname}) still has other active games, keeping presence as in_game");
            }
            
            // User's last connection closed - schedule delayed disconnect check
            $this->schedulePendingDisconnect($tableId, $uid, $seat, $uname);
        }

        $remainingCount = count($remainingConnsBefore) - 1;
        WebSocketLog::debug('GameSocket', "{$uname} disconnected from table #{$tableId} ({$remainingCount} conn(s) remaining)");
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        WebSocketLog::error('GameSocket', 'Transport error: ' . $e->getMessage());
        $this->sendError($conn, 'server_error');
        // Let AuthenticatedServer close the conn; we avoid double-closing.
    }

    // -------------------------------------------------------------------------
    // Message handlers
    // -------------------------------------------------------------------------

    private function handlePing(ConnectionInterface $conn, int $rid): void
    {
        $this->subscriptionService->ping((string)$rid);
        $this->sendJson($conn, ['type' => 'pong']);
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
            $stateBefore = method_exists($gameService, 'getSnapshot')
                ? $gameService->getSnapshot()
                : [];
            if ($stateBefore !== [] && method_exists($gameService, 'isHandBootstrapped')) {
                $stateBefore['_handBootstrapped'] = $gameService->isHandBootstrapped();
            }
            $versionBefore = method_exists($gameService, 'getVersion')
                ? $gameService->getVersion()
                : 0;
    
            // Version check → force sync if client is stale
            $currentVersion = $gameService->getVersion();
            if ($clientVersion > 0 && $clientVersion !== $currentVersion) {
                $this->sendStateSync($from, $tableId, $seat, 'version_mismatch');
                return;
            }
    
            $startedTransaction = $this->beginTransactionIfNeeded();
    
            $result = $gameService->applyAction($seat, $actionStr, $amount);
            if (!($result['ok'] ?? false)) {
                $this->finishTransaction($startedTransaction, false);
                $gameService->restoreSnapshot($stateBefore, $versionBefore);
                $this->sendError($from, 'action_failed', (string)($result['message'] ?? ''));
                return;
            }

            // ------- Read matchEnd flags from GameService -------
            $handEnded   = $result['handEnded']   ?? false;
            $handSummary = $result['summary']     ?? null;
            $matchEnded  = $result['matchEnded']  ?? false;
            $winner      = $result['winner']      ?? null;
            $loser       = $result['loser']       ?? null;

            // If the entire MATCH is over (one player busted), end the match now
            if ($matchEnded) {
                if (!$this->hasValidMatchEndPlayers($winner, $loser, $tableId, 'Match end detected', $from)) {
                    $this->finishTransaction($startedTransaction, false);
                    $gameService->restoreSnapshot($stateBefore, $versionBefore);
                    return;
                }

                $this->commitMatchEnd(
                    $tableId,
                    $gameService->getGameId(),
                    $result,
                    $startedTransaction,
                    afterBroadcast: function () use ($tableId): void {
                        $presenceUsers = [];
                        foreach ($this->userConnections[$tableId] ?? [] as $uid => $conns) {
                            $presenceUsers[] = [
                                'user_id'  => (int)$uid,
                                'username' => !empty($conns)
                                    ? ($this->connInfo[$conns[0]->resourceId]['username'] ?? null)
                                    : null,
                            ];
                        }

                        $this->syncMatchEndPresence($presenceUsers, 'match end');
                    },
                    clearPendingDisconnects: true,
                );
                return; // DO NOT send further state; table is done
            }

            $this->finishTransaction($startedTransaction, true, 'Failed to commit game action');

            // If the hand ended, always broadcast the hand summary for the overlay
            if ($handEnded && $handSummary !== null) {
                $this->broadcast($tableId, [
                    'event'   => 'hand_end',
                    'summary' => $this->buildPublicHandSummary($handSummary, $tableId),
                ]);
            }
                
            // Normal continuing state: broadcast public diff + private state
            $stateToBroadcast = $result['state'] ?? $gameService->getSnapshot();
            $this->broadcastStateUpdate($tableId, $stateToBroadcast, $gameService->getVersion());
    
            // Non-looped test instances still rely on a synchronous fallback sweep.
            $this->flushPendingDisconnectsFallback();
    
        } catch (GameVersionConflictException $e) {
            $this->finishTransaction($startedTransaction ?? false, false);
            $this->restoreAfterFailedCommand($gameService, $stateBefore ?? [], $versionBefore ?? 0);
            $this->sendStateSync($from, $tableId, $seat, 'version_mismatch');
        } catch (\ValueError $e) {
            $this->finishTransaction($startedTransaction ?? false, false);
            $this->restoreAfterFailedCommand($gameService, $stateBefore ?? [], $versionBefore ?? 0);
            $this->sendError($from, 'invalid_action');
        } catch (\Throwable $e) {
            $this->finishTransaction($startedTransaction ?? false, false);
            $this->restoreAfterFailedCommand($gameService, $stateBefore ?? [], $versionBefore ?? 0);
            WebSocketLog::error('GameSocket', 'Action handling failed: ' . $e->getMessage());
            $this->sendError($from, 'server_error');
        }
    }
        
    private function handleNextHand(ConnectionInterface $from, array $data, array $info): void
    {
        $tableId = (int)$info['table_id'];
        $gameService = $this->gameServices[$tableId] ?? null;
    
        if (!$gameService) {
            $this->sendError($from, 'game_not_found');
            return;
        }
    
        try {
            $clientVersion = isset($data['game_version']) ? (int)$data['game_version'] : 0;
            if ($clientVersion > 0 && $clientVersion !== $gameService->getVersion()) {
                $this->sendStateSync($from, $tableId, (int)$info['seat'], 'version_mismatch');
                return;
            }

            $stateBefore = method_exists($gameService, 'getSnapshot')
                ? $gameService->getSnapshot()
                : [];
            if ($stateBefore !== [] && method_exists($gameService, 'isHandBootstrapped')) {
                $stateBefore['_handBootstrapped'] = $gameService->isHandBootstrapped();
            }
            $versionBefore = method_exists($gameService, 'getVersion')
                ? $gameService->getVersion()
                : 0;

            $startedTransaction = $this->beginTransactionIfNeeded();
    
            $result = $gameService->startHand(); // now may return matchEnded
    
            // ============================
            // 1. MATCH END CASE
            // ============================
            if (($result['matchEnded'] ?? false) === true) {
                $winner = $result['winner'] ?? null;
                $loser  = $result['loser']  ?? null;
                
                if (!$this->hasValidMatchEndPlayers($winner, $loser, $tableId, 'Next-hand match end', $from)) {
                    $this->finishTransaction($startedTransaction, false);
                    $gameService->restoreSnapshot($stateBefore, $versionBefore);
                    return;
                }
                
                $this->commitMatchEnd(
                    $tableId,
                    $gameService->getGameId(),
                    $result,
                    $startedTransaction,
                    afterBroadcast: function () use ($winner, $loser): void {
                        $this->syncMatchEndPresence([$winner, $loser], 'next-hand match end');
                    },
                );
    
                // Let frontend navigate away
                return;
            }

            $this->finishTransaction($startedTransaction, true, 'Failed to commit next hand');

            // ============================
            // 2. NORMAL NEW HAND START
            // ============================
            $state = $result['state'] ?? $gameService->getSnapshot();

            if ($gameService->getVersion() !== $versionBefore) {
                $this->broadcast($tableId, [
                    'type' => 'hand_start',
                    'state' => $this->buildPublicState($state, $tableId),
                    'version' => $gameService->getVersion(),
                ]);
            }

            $this->sendPrivateStateUpdates($tableId, $state);
    
        } catch (GameVersionConflictException $e) {
            $this->finishTransaction($startedTransaction ?? false, false);
            $this->restoreAfterFailedCommand($gameService, $stateBefore ?? [], $versionBefore ?? 0);
            $this->sendStateSync($from, $tableId, (int)$info['seat'], 'version_mismatch');
        } catch (\Throwable $e) {
            $this->finishTransaction($startedTransaction ?? false, false);
            $this->restoreAfterFailedCommand($gameService, $stateBefore ?? [], $versionBefore ?? 0);
            WebSocketLog::error('GameSocket', 'Next hand failed: ' . $e->getMessage());
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
        $gameId   = (int)$info['game_id'];
        $userId   = (int)$info['user_id'];
        $username = $info['username'] ?? "User#{$userId}";

        if ($gameId <= 0) {
            $this->sendError($from, 'game_not_found');
            return;
        }

        $msgId = db_insert_chat_message($this->pdo, 'game', $gameId, $userId, $text, null, $username);
        $message = db_get_chat_message_by_id($this->pdo, $msgId) ?? [
            'sender_username' => $username,
            'body' => $text,
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $this->broadcast($tableId, [
            'type' => 'CHAT',
            ...$this->buildChatMessagePayload($message),
        ]);

        // Non-looped test instances still rely on a synchronous fallback sweep.
        $this->flushPendingDisconnectsFallback();
    }


    private function hasValidMatchEndPlayers(
        mixed $winner,
        mixed $loser,
        int $tableId,
        string $context,
        ?ConnectionInterface $conn = null,
    ): bool {
        if (is_array($winner) && is_array($loser)) {
            return true;
        }

        WebSocketLog::error('GameSocket', "{$context} missing winner/loser for table #{$tableId}");

        if ($conn !== null) {
            $this->sendError($conn, 'server_error', 'Match end data invalid');
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // State synchronization
    // -------------------------------------------------------------------------

    /**
     * Rebuild game state from database (snapshot + actions).
     * HARDENED: Only touches GameService, never modifies connection tracking arrays.
     * This ensures other players' connections remain unaffected.
     */
    private function rebuildFromDatabase(int $tableId, int $gameId, GameService $gameService): bool
    {
        if (!empty($this->userConnections[$tableId])) {
            WebSocketLog::debug('GameSocket', "Aborting rebuild for table #{$tableId} because connections appeared");
            return false;
        }

        try {
            if ($gameService->restoreLatestSnapshot()) {
                WebSocketLog::debug('GameSocket', "Snapshot restored for table #{$tableId}");
                return true;
            }

            WebSocketLog::debug('GameSocket', "No snapshot found for game #{$gameId}; skipping rebuild");
            return false;
        } catch (\Throwable $e) {
            WebSocketLog::error('GameSocket', 'Rebuild failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function sendStateSync(ConnectionInterface $conn, int $tableId, int $seat, string $reason = 'connect'): void
    {
        $gameService = $this->gameServices[$tableId] ?? null;
        $gameId = $this->tableIdToGameId[$tableId] ?? ($gameService ? $gameService->getGameId() : null) ?? 0;

        if (!$gameService) {
            $this->sendJson($conn, [
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
            ]);
            return;
        }

        $snapshot = $gameService->getSnapshot();
        $version  = $gameService->getVersion();

        // Public state
        $this->sendJson($conn, [
            'type'    => 'STATE_SYNC',
            'reason'  => $reason,
            'game_id' => $gameId,
            'version' => $version,
            'state'   => $this->buildPublicState($snapshot, $tableId),
        ]);

        // Private state
        $this->sendJson($conn, [
            'type'    => 'STATE_PRIVATE',
            'game_id' => $gameId,
            'seat'    => $seat,
            'state'   => $this->buildPrivateState($snapshot, $seat, $tableId),
        ]);
    }

    private function beginTransactionIfNeeded(): bool
    {
        if ($this->pdo->inTransaction()) {
            return false;
        }

        $this->pdo->beginTransaction();
        return true;
    }

    private function finishTransaction(bool $started, bool $commit, string $failure = ''): void
    {
        if (!$started || !$this->pdo->inTransaction()) {
            return;
        }

        if (!$commit) {
            $this->pdo->rollBack();
            return;
        }

        if (!$this->pdo->commit()) {
            throw new RuntimeException($failure);
        }
    }

    /** @param array<string, mixed> $stateBefore */
    private function restoreAfterFailedCommand(
        object $gameService,
        array $stateBefore,
        int $versionBefore
    ): void {
        try {
            if (method_exists($gameService, 'restoreLatestSnapshot') && $gameService->restoreLatestSnapshot()) {
                return;
            }
        } catch (\Throwable $e) {
            WebSocketLog::warn('GameSocket', 'Authoritative restore failed: ' . $e->getMessage());
        }

        if ($stateBefore !== [] && method_exists($gameService, 'restoreSnapshot')) {
            $gameService->restoreSnapshot($stateBefore, $versionBefore);
        }
    }

    private function broadcastStateUpdate(int $tableId, array $state, int $version): void
    {
        $this->broadcast($tableId, [
            'type' => 'STATE_DIFF',
            'game_id' => $this->tableIdToGameId[$tableId] ?? 0,
            'version' => $version,
            'state' => $this->buildPublicState($state, $tableId),
        ]);
        $this->sendPrivateStateUpdates($tableId, $state);
    }

    private function sendPrivateStateUpdates(int $tableId, array $state): void
    {
        $gameId = $this->tableIdToGameId[$tableId] ?? 0;

        foreach ($this->userConnections[$tableId] ?? [] as $conns) {
            if (empty($conns)) {
                continue;
            }

            $firstRid = (int)$conns[0]->resourceId;
            $info = $this->connInfo[$firstRid] ?? null;
            if (!$info) {
                continue;
            }

            $seat = (int)$info['seat'];
            $message = [
                'type' => 'STATE_PRIVATE',
                'game_id' => $gameId,
                'seat' => $seat,
                'state' => $this->buildPrivateState($state, $seat, $tableId),
            ];

            foreach ($conns as $conn) {
                $this->sendJson($conn, $message);
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
                'user_id'         => $seatUserIds[$seat] ?? ($p['user_id'] ?? null),
                'handRank'        => $p['handRank'],
                'handDescription' => $p['handDescription'],
            ];
        }

        return $public;
    }

    private function buildPublicHandSummary(array $summary, int $tableId): array
    {
        if (!isset($summary['players']) || !is_array($summary['players'])) {
            return $summary;
        }

        $seatUsernames = [];
        if (isset($this->tableSeats[$tableId])) {
            foreach ($this->tableSeats[$tableId] as $seatNo => $data) {
                $seatUsernames[$seatNo] = $data['username'] ?? null;
            }
        }

        $enrichedPlayers = [];
        foreach ($summary['players'] as $seat => $player) {
            $seatNum = is_numeric($seat) ? (int)$seat : (int)($player['seat'] ?? $seat);
            $showCards = ($summary['reason'] ?? null) === 'showdown'
                && !($player['folded'] ?? false);
            $enrichedPlayers[$seat] = array_merge($player, [
                'username' => $seatUsernames[$seatNum] ?? "Seat{$seatNum}",
                'cards' => $showCards ? ($player['cards'] ?? []) : [],
            ]);
        }

        $summary['players'] = $enrichedPlayers;
        return $summary;
    }

    private function buildPrivateState(array $state, int $seat, int $tableId): array
    {
        $cards = [];
        $legal = [];

        if (isset($this->gameServices[$tableId])) {
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

        if (!$info) {
            return;
        }

        if ((int)$info['table_id'] !== $tableId) {
            WebSocketLog::warn('GameSocket', 'removeConnection called with mismatched table_id');
            unset($this->connInfo[$rid]);
            return;
        }

        $uid = (int)$info['user_id'];

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
        $now = self::nowMs();

        foreach ($this->pendingDisconnects as $tableId => $userTimers) {
            foreach ($userTimers as $uid => $timer) {
                $elapsed = $now - $timer['timestamp'];

                if ($elapsed >= self::DISCONNECT_DELAY_MS) {
                    if (!isset($this->userConnections[$tableId][$uid])) {
                        // User truly disconnected - broadcast away status
                        $this->broadcast($tableId, [
                            'type'     => 'PLAYER_AWAY',
                            'seat_no'  => $timer['seat'],
                            'username' => $timer['username'],
                        ]);
                        WebSocketLog::debug('GameSocket', "{$timer['username']} marked away on table #{$tableId}");
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
        $this->sendJson($conn, ['type' => 'error', 'error' => $error, 'message' => $message]);

        if (\in_array($error, ['missing_request', 'invalid_table_id', 'not_seated', 'unauthorized'], true)) {
            WebSocketJson::closeQuietly($conn);
        }
    }

    /** @param array<string, mixed> $payload */
    private function sendJson(ConnectionInterface $conn, array $payload): bool
    {
        return WebSocketJson::send($conn, $payload, static function (\Throwable $e): void {
            WebSocketLog::warn('GameSocket', 'Send failed: ' . $e->getMessage());
        });
    }

    /** @param array<string, mixed> $payload */
    private function encodeJson(array $payload, string $failureContext): ?string
    {
        try {
            return WebSocketJson::encode($payload);
        } catch (\Throwable $e) {
            WebSocketLog::error('GameSocket', $failureContext . ': ' . $e->getMessage());
            return null;
        }
    }

    /** @param array<string, mixed>|null $presenceUser */
    private function broadcastLobbyPresenceUpdate(?array $presenceUser): void
    {
        if ($presenceUser === null || $this->lobbySocket === null) {
            return;
        }

        $this->lobbySocket->broadcastPresenceUpdate($presenceUser);
    }

    /**
     * @param list<array{user_id:mixed, username?:mixed}> $users
     */
    private function syncMatchEndPresence(array $users, string $context): void
    {
        try {
            foreach ($users as $user) {
                $uid = (int) ($user['user_id'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }

                $username = $user['username'] ?? null;
                if (!is_string($username) || $username === '') {
                    $username = "User#{$uid}";
                }

                $presenceUser = $this->presenceService->syncOnlinePresence($uid, $username);
                $this->broadcastLobbyPresenceUpdate($presenceUser);

                WebSocketLog::debug('GameSocket', "Updated presence to online after {$context} for user {$uid}");
            }
        } catch (\Throwable $e) {
            WebSocketLog::warn('GameSocket', "Failed to update presence after {$context}: " . $e->getMessage());
        }
    }

    private function sendChatHistory(ConnectionInterface $conn, int $gameId): void
    {
        if ($gameId <= 0) {
            $this->sendError($conn, 'game_not_found');
            return;
        }

        $recent = db_get_recent_chat_messages($this->pdo, 'game', $gameId, self::CHAT_HISTORY_SIZE);
        $messages = array_map(fn(array $message): array => $this->buildChatMessagePayload($message), $recent);

        $this->sendJson($conn, [
            'type'     => 'CHAT_HISTORY',
            'messages' => $messages,
        ]);
    }

    /** @param array<string, mixed> $message */
    private function buildChatMessagePayload(array $message): array
    {
        $createdAt = (string) ($message['created_at'] ?? date('Y-m-d H:i:s'));

        return [
            'from' => escape_html((string) ($message['sender_username'] ?? 'Unknown')),
            'msg' => escape_html((string) ($message['body'] ?? '')),
            'time' => date('H:i', strtotime($createdAt)),
            'created_at' => $createdAt,
        ];
    }

    private function rateAllow(int $rid): bool
    {
        if (!isset($this->connInfo[$rid])) {
            return false;
        }

        $state = &$this->connInfo[$rid]['rate'];
        return TokenBucketRateLimiter::allow($state, self::RATE_TOKENS, self::RATE_REFILL_PER_S);
    }

    private function registerDisconnectSweep(LoopInterface $loop): void
    {
        $this->usesPeriodicDisconnectSweep = true;

        $loop->addPeriodicTimer(self::DISCONNECT_SWEEP_INTERVAL_S, function (): void {
            if ($this->pendingDisconnects === []) {
                return;
            }

            try {
                $this->processPendingDisconnects();
            } catch (\Throwable $e) {
                WebSocketLog::error('GameSocket', 'Disconnect sweep failed: ' . $e->getMessage());
            }
        });
    }

    private function flushPendingDisconnectsFallback(): void
    {
        if ($this->usesPeriodicDisconnectSweep || $this->pendingDisconnects === []) {
            return;
        }

        $this->processPendingDisconnects();
    }

    private function clearPendingDisconnect(int $tableId, int $userId): void
    {
        unset($this->pendingDisconnects[$tableId][$userId]);

        if (empty($this->pendingDisconnects[$tableId] ?? [])) {
            unset($this->pendingDisconnects[$tableId]);
        }
    }

    private function schedulePendingDisconnect(int $tableId, int $userId, int $seat, string $username): void
    {
        $timestamp = self::nowMs();

        $this->lastSeen[$tableId] ??= [];
        $this->lastSeen[$tableId][$userId] = $timestamp;

        $this->pendingDisconnects[$tableId] ??= [];
        $this->pendingDisconnects[$tableId][$userId] = [
            'timestamp' => $timestamp,
            'seat' => $seat,
            'username' => $username,
        ];
    }

    private static function nowMs(): float
    {
        return hrtime(true) / 1_000_000;
    }

    // -------------------------------------------------------------------------
    // Broadcasting
    // -------------------------------------------------------------------------

    private function broadcast(int $tableId, array $message): void
    {
        if (empty($this->userConnections[$tableId])) {
            return;
        }

        $json = $this->encodeJson($message, "Broadcast encoding failed for table {$tableId}");
        if ($json === null) {
            return;
        }

        $eventType = $message['event'] ?? $message['type'] ?? 'unknown';
        $sentCount = 0;
        $errorCount = 0;

        // MULTI-CONNECTION: Broadcast to all connections for all users at this table
        foreach ($this->userConnections[$tableId] as $uid => $conns) {
            foreach ($conns as $conn) {
                if (WebSocketJson::sendEncoded($conn, $json, static function (\Throwable $e) use ($tableId, $uid): void {
                    WebSocketLog::warn('GameSocket', "Broadcast failed for table {$tableId}, user {$uid}: " . $e->getMessage());
                })) {
                    $sentCount++;
                } else {
                    $errorCount++;
                }
            }
        }
        
        // Log if all broadcasts failed (critical for match_end)
        if ($errorCount > 0 && $sentCount === 0 && $eventType === 'match_end') {
            WebSocketLog::error('GameSocket', "All match_end broadcasts failed for table {$tableId}");
        }
    }

    // -------------------------------------------------------------------------
    // Game service management
    // -------------------------------------------------------------------------

    /**
     * Ensure a GameService instance exists for the specified table.
     * Reuses existing service if present; otherwise creates one.
     * Returns the current game_id.
     * 
     * HARDENED: Only touches $gameServices and $tableIdToGameId arrays.
     * Never modifies connection tracking arrays.
     */
    private function ensureGameService(int $tableId, int $dbGameId): int
    {
        if (
            isset($this->gameServices[$tableId])
            && isset($this->tableIdToGameId[$tableId])
            && $this->tableIdToGameId[$tableId] === $dbGameId
        ) {
            return $dbGameId;
        }

        $persistence = new GamePersistence($this->pdo);
        $table = db_get_table_by_id($this->pdo, $tableId);
        $smallBlind = (int)($table['small_blind'] ?? 10);
        $bigBlind = (int)($table['big_blind'] ?? 20);

        if ($table === null) {
            WebSocketLog::warn('GameSocket', "Table #{$tableId} not found while creating GameService; using default blinds");
        }

        $service = new GameService($persistence, $smallBlind, $bigBlind);
        $service->setGameId($dbGameId);
        $this->gameServices[$tableId] = $service;
        $this->tableIdToGameId[$tableId] = $dbGameId;

        // Restore persisted state before the first reconnecting socket is
        // registered. Otherwise the active connection prevents recovery and
        // ensureHandBootstrapped() replaces the active hand with a new one.
        if ($this->rebuildFromDatabase($tableId, $dbGameId, $service)) {
            $this->tableBootstrapped[$tableId] = true;
        }

        return $dbGameId;
    }

    /**
     * @param array<int|string, array<string, mixed>> $players
     * @return array<int, array{seat:int,user_id:int,cards:array,folded:bool,stack:int,bet:int}>
     */
    private function buildMatchEndPlayerData(array $players): array
    {
        $playerData = [];

        foreach ($players as $seat => $player) {
            $playerData[(int)$seat] = [
                'seat'    => (int)$seat,
                'user_id' => (int)($player['user_id'] ?? 0),
                'cards'   => $player['cards'] ?? [],
                'folded'  => (bool)($player['folded'] ?? false),
                'stack'   => (int)($player['stack'] ?? 0),
                'bet'     => (int)($player['bet'] ?? 0),
            ];
        }

        return $playerData;
    }

    /** @param array<string, mixed> $result */
    private function commitMatchEnd(
        int $tableId,
        ?int $gameId,
        array $result,
        bool $startedTransaction,
        ?callable $afterBroadcast = null,
        bool $deleteGame = true,
        bool $clearPendingDisconnects = false,
    ): void {
        $stateSnapshot = $result['state'] ?? $this->gameServices[$tableId]->getSnapshot();

        if ($deleteGame && $gameId) {
            if (!db_delete_snapshots($this->pdo, $gameId)) {
                throw new RuntimeException("Failed to delete snapshots for completed game {$gameId}");
            }
            if (!db_delete_game($this->pdo, $gameId)) {
                throw new RuntimeException("Failed to delete completed game {$gameId}");
            }
        }

        if (!db_clear_table_seats($this->pdo, $tableId)) {
            throw new RuntimeException("Failed to clear seats for completed table {$tableId}");
        }
        $this->finishTransaction($startedTransaction, true, 'Failed to commit match end');
        $this->publishMatchEnd(
            $tableId,
            $result['winner'],
            $result['loser'],
            $result['reason'] ?? null,
            $stateSnapshot,
            $afterBroadcast,
            $clearPendingDisconnects,
        );
    }

    /**
     * @param array<string, mixed> $winner
     * @param array<string, mixed> $loser
     * @param array<string, mixed> $stateSnapshot
     */
    private function publishMatchEnd(
        int $tableId,
        array $winner,
        array $loser,
        ?string $reason,
        array $stateSnapshot,
        ?callable $afterBroadcast = null,
        bool $clearPendingDisconnects = false,
    ): void {
        $board = $reason === 'forfeit' ? [] : ($stateSnapshot['board'] ?? []);
        $players = $this->buildMatchEndPlayerData($stateSnapshot['players'] ?? []);
        $handReason = $stateSnapshot['lastHandResult']['reason'] ?? null;
        foreach ($players as &$player) {
            if ($reason === 'forfeit' || $handReason !== 'showdown' || $player['folded']) {
                $player['cards'] = [];
            }
        }
        unset($player);

        $this->broadcast($tableId, [
            'event'   => 'match_end',
            'winner'  => $winner,
            'loser'   => $loser,
            'reason'  => $reason,
            'board'   => $board,
            'players' => $players,
        ]);

        if ($afterBroadcast !== null) {
            $afterBroadcast();
        }

        if ($clearPendingDisconnects) {
            unset($this->pendingDisconnects[$tableId], $this->lastSeen[$tableId]);
        }

        unset(
            $this->gameServices[$tableId],
            $this->tableIdToGameId[$tableId],
            $this->tableBootstrapped[$tableId]
        );

        $this->flushPendingDisconnectsFallback();
    }

    private function ensureHandBootstrapped(int $tableId, ?int $gameId, ?int $deckSeed = null): void
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
                'user_id' => (int)$r['user_id'],
                'stack' => 1000, // default starting stack
            ],
            $active
        );
    
        $svc->loadPlayers($players);
        $stateBefore = $svc->getSnapshot();
        $stateBefore['_handBootstrapped'] = $svc->isHandBootstrapped();
        $versionBefore = $svc->getVersion();
    
        // Try to start the first hand with its version and snapshot in one transaction.
        $startedTransaction = $this->beginTransactionIfNeeded();

        try {
            $result = $svc->startHand($deckSeed);

            // Match is already over before any hand starts.
            if (($result['matchEnded'] ?? false) === true) {
                $winner = $result['winner'] ?? null;
                $loser  = $result['loser'] ?? null;

                if (!$this->hasValidMatchEndPlayers($winner, $loser, $tableId, 'Bootstrapping match end')) {
                    $this->finishTransaction($startedTransaction, false);
                    $svc->restoreSnapshot($stateBefore, $versionBefore);
                    $this->tableBootstrapped[$tableId] = true;
                    return;
                }

                $this->commitMatchEnd(
                    $tableId,
                    $gameId,
                    $result,
                    $startedTransaction,
                    deleteGame: false,
                );
                return;
            }

            $this->finishTransaction($startedTransaction, true, 'Failed to commit hand bootstrap');

            $state = $result['state'] ?? $svc->getSnapshot();
            $this->broadcast($tableId, [
                'type' => 'hand_start',
                'state' => $this->buildPublicState($state, $tableId),
                'version' => $svc->getVersion(),
            ]);
            $this->tableBootstrapped[$tableId] = true;
        } catch (\Throwable $e) {
            $this->finishTransaction($startedTransaction, false);
            $this->restoreAfterFailedCommand($svc, $stateBefore, $versionBefore);
            throw $e;
        }
    }      
}
