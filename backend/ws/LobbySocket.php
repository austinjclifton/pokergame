<?php
// backend/ws/LobbySocket.php
// -----------------------------------------------------------------------------
// Lobby WebSocket (hybrid, presence-aware)
// Responsibilities:
//   • Manage live connections for chat + challenges
//   • Announce user join/leave based on presence transitions (not mere reconnects)
//   • Read/write presence via PresenceService (DAL-backed)
//   • No polling required on the client
// -----------------------------------------------------------------------------

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require_once __DIR__ . '/../app/services/PresenceService.php';
require_once __DIR__ . '/../app/services/SubscriptionService.php';
require_once __DIR__ . '/../app/services/ChallengeService.php';
require_once __DIR__ . '/../app/services/AuditService.php';
require_once __DIR__ . '/../app/db/chat_messages.php';
require_once __DIR__ . '/../app/db/users.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/security.php';

class LobbySocket implements MessageComponentInterface
{
    /** @var \SplObjectStorage<ConnectionInterface, null> */
    protected \SplObjectStorage $clients;
    protected PDO $pdo;
    protected PresenceService $presenceService;
    protected SubscriptionService $subscriptionService;
    protected ChallengeService $challengeService;

    /**
     * Per-connection metadata indexed by resourceId:
     *   [rid => ['user_id'=>int,'username'=>string,'session_id'=>int,'rate'=>[...]]]
     * @var array<int, array<string, mixed>>
     */
    protected array $connInfo = [];

    /**
     * Track when users disconnect to detect reconnects (page refreshes)
     * [user_id => ['time' => timestamp, 'username' => string]]
     * @var array<int, array>
     */
    protected array $recentDisconnects = [];

    /**
     * Track users who explicitly logged out (so we don't treat their reconnection as a quick reconnect)
     * [user_id => true]
     * @var array<int, bool>
     */
    protected array $explicitLogouts = [];

    private const MAX_MSG_BYTES     = 2048;
    private const CHAT_MAX_CHARS    = 500;
    private const RATE_TOKENS       = 5.0;
    private const RATE_REFILL_PER_S = 1.5;
    private const JOIN_HISTORY_SIZE = 20;

    public function __construct(PDO $pdo)
    {
        $this->clients             = new \SplObjectStorage;
        $this->pdo                 = $pdo;
        $this->presenceService     = new PresenceService($pdo);
        $this->subscriptionService = new SubscriptionService($pdo);
        $this->challengeService    = new ChallengeService($pdo);
        echo "💬 LobbySocket initialized (presence-aware)\n";
    }

    // -------------------------------------------------------------------------
    // Connection lifecycle
    // -------------------------------------------------------------------------
    public function onOpen(ConnectionInterface $conn)
    {
        try {
            $this->clients->attach($conn);

            if (!isset($conn->userCtx) || !is_array($conn->userCtx)) {
                $conn->send(json_encode(['type' => 'error', 'error' => 'unauthorized']));
                $this->clients->detach($conn);
                $conn->close();
                return;
            }

            $uid   = (int)$conn->userCtx['user_id'];
            $sid   = (int)$conn->userCtx['session_id'];
            $uname = db_get_username_by_id($this->pdo, $uid) ?? "User#$uid";
            $rid   = (int)$conn->resourceId;

            $this->connInfo[$rid] = [
                'user_id'    => $uid,
                'username'   => $uname,
                'session_id' => $sid,
                'rate'       => ['ts' => time(), 'tokens' => self::RATE_TOKENS],
            ];

            $this->subscriptionService->register($uid, (string)$rid, 'lobby', 0);

            // Audit log: WebSocket connection
            try {
                log_audit_event($this->pdo, [
                    'user_id' => $uid,
                    'session_id' => $sid,
                    'action' => 'websocket.connect',
                    'entity_type' => 'websocket_connection',
                    'details' => [
                        'connection_id' => (string)$rid,
                        'channel_type' => 'lobby',
                    ],
                    'channel' => 'websocket',
                    'status' => 'success',
                    'severity' => 'info',
                ]);
            } catch (\Throwable $e) {
                error_log('[LobbySocket] Audit logging failed: ' . $e->getMessage());
            }

            // Check presence before marking online to detect stale records
            require_once __DIR__ . '/../app/db/presence.php';
            $presenceBefore = db_get_user_presence($this->pdo, $uid);
            
            // Check if user's presence record is stale (last seen > 2 minutes ago) OR missing
            $isStalePresence = false;
            if (!$presenceBefore) {
                $isStalePresence = true;
            } elseif ($presenceBefore['status'] === 'online') {
                $lastSeen = strtotime($presenceBefore['last_seen_at']);
                $secondsSinceLastSeen = time() - $lastSeen;
                if ($secondsSinceLastSeen > 120) { // 2 minutes
                    $isStalePresence = true;
                }
            }
            
            $becameOnline = $this->presenceService->markOnline($uid, $uname);
            $shouldTreatAsNewLogin = $becameOnline || $isStalePresence;

            $recent = db_get_recent_chat_messages($this->pdo, 'lobby', 0, self::JOIN_HISTORY_SIZE);
            $conn->send(json_encode([
                'type' => 'history',
                'messages' => array_map(fn($m) => [
                    'from' => escape_html($m['sender_username']),
                    'msg'  => escape_html($m['body']),
                    'time' => date('H:i', strtotime($m['created_at'])),
                    'created_at' => $m['created_at'], // Include full timestamp for 12-hour filtering
                ], $recent),
            ]));

            // Get all users with any status (online, in_game, etc.) for the player list
            $stmt = $this->pdo->query("
                SELECT user_id, user_username, status, last_seen_at
                FROM user_lobby_presence
                WHERE status IN ('online', 'in_game')
                ORDER BY user_username
            ");
            $allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $conn->send(json_encode([
                'type'  => 'online_users',
                'users' => array_map(fn($u) => [
                    'id'       => (int)$u['user_id'],
                    'username' => escape_html($u['user_username']),
                    'status'   => $u['status'] ?? 'online',
                ], $allUsers),
            ]));

            // Check if user has other active connections
            $hasOtherConnections = false;
            foreach ($this->connInfo as $otherRid => $otherInfo) {
                if ($otherInfo['user_id'] === $uid && $otherRid !== $rid) {
                    $hasOtherConnections = true;
                    break;
                }
            }
            
            // Check if this is a quick reconnect (page refresh) - within 5 seconds
            $isQuickReconnect = false;
            $wasInRecentDisconnects = isset($this->recentDisconnects[$uid]);
            
            if ($wasInRecentDisconnects) {
                $disconnectInfo = $this->recentDisconnects[$uid];
                $secondsSinceDisconnect = time() - $disconnectInfo['time'];
                if ($secondsSinceDisconnect < 5) {
                    $isQuickReconnect = true;
                    unset($this->recentDisconnects[$uid]);
                } else {
                    unset($this->recentDisconnects[$uid]);
                }
            }
            
            // Clean up old disconnect records (older than 5 seconds) and send delayed leave messages
            $now = time();
            foreach ($this->recentDisconnects as $oldUid => $oldInfo) {
                if ($oldUid === $uid) continue;
                $secondsSince = $now - $oldInfo['time'];
                if ($secondsSince >= 5) {
                    $escapedOldUsername = escape_html($oldInfo['username']);
                    $this->broadcast([
                        'type'   => 'chat',
                        'system' => true,
                        'msg'    => "🔴 {$escapedOldUsername} left the lobby.",
                        'time'   => date('H:i'),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $this->broadcast([
                        'type'   => 'presence',
                        'event'  => 'leave',
                        'user'   => ['id' => $oldUid, 'username' => $escapedOldUsername],
                    ]);
                    unset($this->recentDisconnects[$oldUid]);
                }
            }
            
            // Always broadcast presence event when user connects (for player list updates)
            $this->broadcastExcept($conn, [
                'type'   => 'presence',
                'event'  => 'join',
                'user'   => ['id' => $uid, 'username' => escape_html($uname)],
                'online' => count($allUsers),
            ]);
            
            // Show join chat message when user doesn't have other connections and it's not a quick reconnect
            $shouldShowJoinMessage = !$hasOtherConnections && !$isQuickReconnect;
            
            if ($shouldShowJoinMessage) {
                $escapedUname = escape_html($uname);
                $this->broadcastExcept($conn, [
                    'type'   => 'chat',
                    'system' => true,
                    'msg'    => "🟢 {$escapedUname} joined the lobby.",
                    'time'   => date('H:i'),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            echo "🟢 {$uname} connected\n";

        } catch (\Throwable $e) {
            error_log("[WS:onOpen] " . $e->getMessage());
            $conn->send(json_encode(['type' => 'error', 'error' => 'connection_failed']));
            $this->clients->detach($conn);
            $conn->close();
        }
    }

    // -------------------------------------------------------------------------
    // Incoming messages
    // -------------------------------------------------------------------------
    public function onMessage(ConnectionInterface $from, $msg)
    {
        $rid  = (int)$from->resourceId;
        $info = $this->connInfo[$rid] ?? null;

        if (!$info) {
            $from->send(json_encode(['type' => 'error', 'error' => 'unauthorized']));
            $from->close();
            return;
        }

        if (!is_string($msg) || strlen($msg) > self::MAX_MSG_BYTES) {
            $from->send(json_encode(['type' => 'error', 'error' => 'payload_too_large']));
            return;
        }

        if (!$this->rateAllow($rid)) {
            // Audit log: rate limit exceeded
            try {
                log_audit_event($this->pdo, [
                    'user_id' => $info['user_id'] ?? null,
                    'action' => 'rate_limit.exceeded',
                    'details' => [
                        'channel' => 'websocket',
                        'connection_id' => (string)$rid,
                    ],
                    'channel' => 'websocket',
                    'status' => 'failure',
                    'severity' => 'warn',
                ]);
            } catch (\Throwable $e) {
                error_log('[LobbySocket] Audit logging failed: ' . $e->getMessage());
            }
            $from->send(json_encode(['type' => 'error', 'error' => 'rate_limited']));
            return;
        }

        $data = json_decode($msg, true);
        if (!is_array($data) || !isset($data['type'])) {
            $from->send(json_encode(['type' => 'error', 'error' => 'invalid_payload']));
            return;
        }

        $type  = $data['type'];
        $uid   = (int)$info['user_id'];
        $uname = $info['username'];

        switch ($type) {
            // Keep socket + presence heartbeat fresh
            case 'ping':
                $this->subscriptionService->ping((string)$rid);
                $this->presenceService->updateHeartbeat($uid);
                $from->send(json_encode(['type' => 'pong']));
                break;

            // Public chat in the lobby
            case 'chat':
                $text = trim(mb_substr((string)($data['msg'] ?? ''), 0, self::CHAT_MAX_CHARS));
                if ($text === '') {
                    $from->send(json_encode(['type' => 'error', 'error' => 'empty_message']));
                    break;
                }
                $msgId = db_insert_chat_message($this->pdo, 'lobby', 0, $uid, $text, null, $uname);
                
                // Audit log: chat message sent
                try {
                    log_audit_event($this->pdo, [
                        'user_id' => $uid,
                        'session_id' => $info['session_id'],
                        'action' => 'chat.send',
                        'entity_type' => 'chat_message',
                        'entity_id' => $msgId,
                        'details' => [
                            'channel_type' => 'lobby',
                            'channel_id' => 0,
                            'message_length' => mb_strlen($text),
                        ],
                        'channel' => 'websocket',
                        'status' => 'success',
                        'severity' => 'info',
                    ]);
                } catch (\Throwable $e) {
                    error_log('[LobbySocket] Audit logging failed: ' . $e->getMessage());
                }
                
                // Fetch the actual created_at timestamp from the database to ensure it matches
                // what will be returned in history queries (MySQL's CURRENT_TIMESTAMP)
                $stmt = $this->pdo->prepare("SELECT created_at FROM chat_messages WHERE id = :id LIMIT 1");
                $stmt->execute(['id' => $msgId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $createdAt = $row ? $row['created_at'] : date('Y-m-d H:i:s');
                
                // Format time from the database timestamp
                $timestamp = strtotime($createdAt);
                $timeStr = date('H:i', $timestamp);
                
                // Broadcast with timestamp for 12-hour filtering
                $this->broadcast([
                    'type' => 'chat',
                    'from' => escape_html($uname),
                    'msg'  => escape_html($text),
                    'time' => $timeStr,
                    'created_at' => $createdAt, // Use actual database timestamp
                ]);
                break;

            // Explicit logout from client → announce & close socket
            case 'logout':
                // Mark this as an explicit logout so onClose knows not to add to recentDisconnects
                $this->explicitLogouts[$uid] = true;
                
                // Clear from recentDisconnects so they can log back in without being treated as a quick reconnect
                if (isset($this->recentDisconnects[$uid])) {
                    unset($this->recentDisconnects[$uid]);
                }
                
                // Mark offline first
                $becameOffline = $this->presenceService->markOffline($uid);
                if ($becameOffline) {
                    // Broadcast to other clients (but not the leaving user)
                    $escapedUname = escape_html($uname);
                    $this->broadcastExcept($from, [
                        'type'   => 'chat',
                        'system' => true,
                        'msg'    => "🔴 {$escapedUname} left the lobby.",
                        'time'   => date('H:i'),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $this->broadcastExcept($from, [
                        'type'  => 'presence',
                        'event' => 'leave',
                        'user'  => ['id' => $uid, 'username' => $escapedUname],
                    ]);
                }
                // Disconnect subscription before closing
                $this->subscriptionService->disconnect((string)$rid);
                $from->close();
                break;

            // Send a challenge to another user
            case 'challenge':
                $toUserId = (int)($data['to_user_id'] ?? 0);
                if ($toUserId <= 0) {
                    $from->send(json_encode(['type' => 'error', 'error' => 'invalid_target']));
                    break;
                }
                $targetUser = db_get_user_by_id($this->pdo, $toUserId);
                if (!$targetUser) {
                    $from->send(json_encode(['type' => 'error', 'error' => 'user_not_found']));
                    break;
                }
                $result = $this->challengeService->send($uid, $targetUser['username']);
                if (!$result['ok']) {
                    $from->send(json_encode(['type' => 'error', 'error' => $result['message']]));
                    break;
                }
                $escapedTargetUsername = escape_html($targetUser['username']);
                $this->sendToUser($toUserId, [
                    'type'         => 'challenge',
                    'from'         => ['id' => $uid, 'username' => escape_html($uname)],
                    'challenge_id' => $result['challenge_id'],
                ]);
                // Send confirmation to sender
                $from->send(json_encode([
                    'type' => 'challenge_sent',
                    'to'   => ['id' => $toUserId, 'username' => $escapedTargetUsername],
                ]));
                // Send chat notification to sender
                $from->send(json_encode([
                    'type'   => 'chat',
                    'system' => true,
                    'msg'    => "✅ Challenge sent to {$escapedTargetUsername}.",
                    'time'   => date('H:i'),
                    'created_at' => date('Y-m-d H:i:s'),
                ]));
                echo "🎮 {$uname} challenged {$escapedTargetUsername}\n";
                break;

            // Accept/decline a challenge
            case 'challenge_response':
                $challengeId = (int)($data['challenge_id'] ?? 0);
                $action      = trim((string)($data['action'] ?? ''));
                if ($challengeId <= 0 || !in_array($action, ['accept', 'decline'], true)) {
                    $from->send(json_encode(['type' => 'error', 'error' => 'invalid_challenge_response']));
                    break;
                }
                
                // Get challenge details to know who sent it
                require_once __DIR__ . '/../app/db/challenges.php';
                $challenge = db_get_challenge_for_accept($this->pdo, $challengeId);
                if (!$challenge) {
                    $from->send(json_encode(['type' => 'error', 'error' => 'challenge_not_found']));
                    break;
                }
                
                $fromUserId = (int)$challenge['from_user_id'];
                $toUserId = (int)$challenge['to_user_id'];
                $fromUsername = db_get_username_by_id($this->pdo, $fromUserId) ?? "User#$fromUserId";
                $escapedFromUsername = escape_html($fromUsername);
                
                $result = $action === 'accept'
                    ? $this->challengeService->accept($challengeId, $uid)
                    : $this->challengeService->decline($challengeId, $uid);

                if (!$result['ok']) {
                    $from->send(json_encode(['type' => 'error', 'error' => $result['message']]));
                    break;
                }

                // Send chat notification to the person who responded
                if ($action === 'decline') {
                    $from->send(json_encode([
                        'type'   => 'chat',
                        'system' => true,
                        'msg'    => "❌ You declined the challenge from {$escapedFromUsername}.",
                        'time'   => date('H:i'),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]));
                }

                // Broadcast challenge response to all (for challenge list updates)
                $this->broadcast([
                    'type'         => 'challenge_response',
                    'challenge_id' => $challengeId,
                    'action'       => $action,
                    'from'         => ['id' => $uid, 'username' => escape_html($uname)],
                    'game_id'      => $result['game_id'] ?? null,
                ]);
                
                // Send chat notification to the original challenger if declined
                if ($action === 'decline' && $fromUserId !== $uid) {
                    $escapedUname = escape_html($uname);
                    $this->sendToUser($fromUserId, [
                        'type'   => 'chat',
                        'system' => true,
                        'msg'    => "❌ {$escapedUname} declined your challenge.",
                        'time'   => date('H:i'),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                
                if ($action === 'accept') {
                    echo "✅ {$uname} accepted challenge from {$escapedFromUsername}\n";
                } else {
                    echo "❌ {$uname} declined challenge from {$escapedFromUsername}\n";
                }
                break;

            // Cancel a challenge (sender cancels their own challenge)
            case 'challenge_cancel':
                $challengeId = (int)($data['challenge_id'] ?? 0);
                if ($challengeId <= 0) {
                    $from->send(json_encode(['type' => 'error', 'error' => 'invalid_challenge_id']));
                    break;
                }
                
                require_once __DIR__ . '/../app/db/challenges.php';
                $challenge = db_get_challenge_for_accept($this->pdo, $challengeId);
                if (!$challenge) {
                    $from->send(json_encode(['type' => 'error', 'error' => 'challenge_not_found']));
                    break;
                }
                
                $toUserId = (int)$challenge['to_user_id'];
                $toUsername = db_get_username_by_id($this->pdo, $toUserId) ?? "User#$toUserId";
                $escapedToUsername = escape_html($toUsername);
                
                $result = $this->challengeService->cancel($challengeId, $uid);
                if (!$result['ok']) {
                    $from->send(json_encode(['type' => 'error', 'error' => $result['message']]));
                    break;
                }

                // Send chat notification to the person who canceled
                $from->send(json_encode([
                    'type'   => 'chat',
                    'system' => true,
                    'msg'    => "❌ You canceled your challenge to {$escapedToUsername}.",
                    'time'   => date('H:i'),
                    'created_at' => date('Y-m-d H:i:s'),
                ]));

                // Notify the target user that the challenge was canceled
                $this->sendToUser($toUserId, [
                    'type'   => 'chat',
                    'system' => true,
                    'msg'    => "❌ {$uname} canceled their challenge to you.",
                    'time'   => date('H:i'),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                // Broadcast challenge cancel to all (for challenge list updates)
                $this->broadcast([
                    'type'         => 'challenge_cancel',
                    'challenge_id' => $challengeId,
                    'from'         => ['id' => $uid, 'username' => escape_html($uname)],
                ]);
                echo "❌ {$uname} canceled challenge to {$escapedToUsername}\n";
                break;

            default:
                $from->send(json_encode(['type' => 'error', 'error' => 'unknown_type', 'got' => $type]));
        }
    }

    // -------------------------------------------------------------------------
    // Disconnection handling
    // -------------------------------------------------------------------------
    public function onClose(ConnectionInterface $conn)
    {
        $rid  = (int)$conn->resourceId;
        $info = $this->connInfo[$rid] ?? null;

        if (!$info) {
            $this->clients->detach($conn);
            return;
        }

        $uid = (int)$info['user_id'];
        $uname = $info['username'];

        // Check if user has other active connections BEFORE removing this one
        // This prevents "left/joined" spam on page refresh
        $hasOtherConnections = false;
        foreach ($this->connInfo as $otherRid => $otherInfo) {
            if ($otherInfo['user_id'] === $uid && $otherRid !== $rid) {
                $hasOtherConnections = true;
                break;
            }
        }

        // Now remove this connection
        $this->clients->detach($conn);
        unset($this->connInfo[$rid]);

        try {
            $this->subscriptionService->disconnect((string)$rid);
            
            // Check if this was an explicit logout - if so, don't track in recentDisconnects
            $wasExplicitLogout = isset($this->explicitLogouts[$uid]);
            if ($wasExplicitLogout) {
                unset($this->explicitLogouts[$uid]);
            }
            
            // Only mark offline if this was their last connection
            if (!$hasOtherConnections) {
                // Audit log: WebSocket disconnection
                try {
                    log_audit_event($this->pdo, [
                        'user_id' => $uid,
                        'action' => 'websocket.disconnect',
                        'entity_type' => 'websocket_connection',
                        'details' => [
                            'connection_id' => (string)$rid,
                            'was_explicit_logout' => $wasExplicitLogout ?? false,
                        ],
                        'channel' => 'websocket',
                        'status' => 'success',
                        'severity' => 'info',
                    ]);
                } catch (\Throwable $e) {
                    error_log('[LobbySocket] Audit logging failed: ' . $e->getMessage());
                }
                
                // Track disconnect time if it was NOT an explicit logout (to detect quick reconnects)
                if (!$wasExplicitLogout) {
                    $this->recentDisconnects[$uid] = [
                        'time' => time(),
                        'username' => $uname,
                    ];
                }
                
                $this->presenceService->markOffline($uid);
            }

        } catch (\Throwable $e) {
            error_log("LobbySocket cleanup: " . $e->getMessage());
        }

        echo "🔴 {$uname} disconnected\n";
    }

    // -------------------------------------------------------------------------
    // Error + utilities
    // -------------------------------------------------------------------------
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        error_log("LobbySocket error: " . $e->getMessage());
        $conn->send(json_encode(['type' => 'error', 'error' => 'server_error']));
        $conn->close();
    }

    /** Broadcast JSON to all connected lobby clients. */
    private function broadcast(array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ($this->clients as $client) {
            try {
                $client->send($json);
            } catch (\Throwable $e) {
                error_log("Broadcast failed: " . $e->getMessage());
            }
        }
    }

    /** Broadcast JSON to all connected lobby clients except the excluded connection. */
    private function broadcastExcept(ConnectionInterface $except, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ($this->clients as $client) {
            if ($client === $except) continue;
            try {
                $client->send($json);
            } catch (\Throwable $e) {
                error_log("Broadcast failed: " . $e->getMessage());
            }
        }
    }

    /** Send JSON to a single user by user_id. */
    private function sendToUser(int $userId, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ($this->clients as $client) {
            $rid = (int)$client->resourceId;
            if (isset($this->connInfo[$rid]) && $this->connInfo[$rid]['user_id'] === $userId) {
                try {
                    $client->send($json);
                } catch (\Throwable $e) {
                    error_log("Send to user failed: " . $e->getMessage());
                }
            }
        }
    }

    /** Simple token bucket rate limiter per connection. */
    private function rateAllow(int $rid): bool
    {
        if (!isset($this->connInfo[$rid]['rate'])) {
            $this->connInfo[$rid]['rate'] = ['ts' => time(), 'tokens' => self::RATE_TOKENS];
            return true;
        }

        $now     = microtime(true);
        $state   = &$this->connInfo[$rid]['rate'];
        $elapsed = max(0.0, $now - $state['ts']);
        $state['ts'] = $now;
        $state['tokens'] = min(self::RATE_TOKENS, $state['tokens'] + $elapsed * self::RATE_REFILL_PER_S);
        if ($state['tokens'] < 1.0) return false;
        $state['tokens'] -= 1.0;
        return true;
    }
}
