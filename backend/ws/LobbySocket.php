<?php
// backend/ws/LobbySocket.php
// -----------------------------------------------------------------------------
// Lobby WebSocket (hybrid, presence-aware)
// Responsibilities:
//   • Manage live connections for chat + challenges
//   • Announce user join/leave based on presence transitions (not mere reconnects)
//   • Read/write presence via SocketPresenceService
//   • No polling required on the client
// -----------------------------------------------------------------------------

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

require_once __DIR__ . '/../app/services/SocketPresenceService.php';
require_once __DIR__ . '/../app/services/SubscriptionService.php';
require_once __DIR__ . '/../app/services/ChallengeService.php';
require_once __DIR__ . '/../app/services/AuditService.php';
require_once __DIR__ . '/../app/db/chat_messages.php';
require_once __DIR__ . '/../app/db/challenges.php';
require_once __DIR__ . '/../app/db/users.php';
require_once __DIR__ . '/../app/db/table_seats.php';
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../lib/TokenBucketRateLimiter.php';
require_once __DIR__ . '/../lib/WebSocketLog.php';
require_once __DIR__ . '/../lib/WebSocketJson.php';

class LobbySocket implements MessageComponentInterface
{
    /** @var \SplObjectStorage<ConnectionInterface, null> */
    protected \SplObjectStorage $clients;
    protected PDO $pdo;
    protected SocketPresenceService $presenceService;
    protected SubscriptionService $subscriptionService;
    protected ChallengeService $challengeService;

    /**
     * Per-connection metadata indexed by resourceId:
     *   [rid => ['user_id'=>int,'username'=>string,'session_id'=>int,'rate'=>[...]]]
     * @var array<int, array<string, mixed>>
     */
    protected array $connInfo = [];

    /**
     * Track recent disconnects to detect quick reconnects (page refreshes)
     * [user_id => ['timestamp' => float, 'username' => string]]
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
    private const QUICK_RECONNECT_WINDOW_S = 5.0;

    public function __construct(PDO $pdo)
    {
        $this->clients             = new \SplObjectStorage;
        $this->pdo                 = $pdo;
        $this->presenceService     = new SocketPresenceService($pdo);
        $this->subscriptionService = new SubscriptionService($pdo);
        $this->challengeService    = new ChallengeService($pdo);
        WebSocketLog::debug('LobbySocket', 'Initialized (presence-aware)');
    }

    // -------------------------------------------------------------------------
    // Connection lifecycle
    // -------------------------------------------------------------------------
    public function onOpen(ConnectionInterface $conn)
    {
        try {
            $this->clients->attach($conn);

            if (!isset($conn->userCtx) || !is_array($conn->userCtx)) {
                $this->sendError($conn, 'unauthorized');
                $this->clients->detach($conn);
                WebSocketJson::closeQuietly($conn);
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
                'rate'       => TokenBucketRateLimiter::seed(self::RATE_TOKENS),
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
                WebSocketLog::warn('LobbySocket', 'Audit logging failed on connect: ' . $e->getMessage());
            }

            $presenceUser = $this->presenceService->syncLobbyConnection($uid, $uname);

            $recent = db_get_recent_chat_messages($this->pdo, 'lobby', 0, self::JOIN_HISTORY_SIZE);
            $this->sendJson($conn, [
                'type' => 'history',
                'messages' => array_map(fn($m) => [
                    'from' => escape_html($m['sender_username']),
                    'msg'  => escape_html($m['body']),
                    'time' => date('H:i', strtotime($m['created_at'])),
                    'created_at' => $m['created_at'], // Include full timestamp for 12-hour filtering
                ], $recent),
            ]);

            $usersWithTables = $this->presenceService->listVisibleUsers();
            
            $this->sendJson($conn, [
                'type'  => 'online_users',
                'users' => $usersWithTables,
            ]);

            $hasOtherConnections = $this->hasOtherActiveConnections($uid, $rid);
            $isQuickReconnect = $this->consumeQuickReconnect($uid);
            $this->flushExpiredDisconnectAnnouncements($uid);

            $this->broadcastExcept($conn, [
                'type'   => 'presence',
                'event'  => 'join',
                'user'   => $presenceUser,
                'online' => count($usersWithTables),
            ]);
            
            // Show join chat message when user doesn't have other connections and it's not a quick reconnect
            if (!$hasOtherConnections && !$isQuickReconnect) {
                $this->broadcastLobbyJoin($uname, $conn);
            }

            WebSocketLog::debug('LobbySocket', "{$uname} connected");

        } catch (\Throwable $e) {
            WebSocketLog::error('LobbySocket', 'onOpen failed: ' . $e->getMessage());
            $this->sendError($conn, 'connection_failed');
            $this->clients->detach($conn);
            WebSocketJson::closeQuietly($conn);
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
            $this->sendError($from, 'unauthorized');
            WebSocketJson::closeQuietly($from);
            return;
        }

        if (!is_string($msg) || strlen($msg) > self::MAX_MSG_BYTES) {
            $this->sendError($from, 'payload_too_large');
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
                WebSocketLog::warn('LobbySocket', 'Audit logging failed on rate limit: ' . $e->getMessage());
            }
            $this->sendError($from, 'rate_limited');
            return;
        }

        $data = json_decode($msg, true);
        if (!is_array($data) || !isset($data['type'])) {
            $this->sendError($from, 'invalid_payload');
            return;
        }

        switch ($data['type']) {
            case 'ping':
                $this->handlePing($from, $rid, (int) $info['user_id']);
                break;

            case 'chat':
                $this->handleChat($from, $data, $info);
                break;

            case 'logout':
                $this->handleLogout($from, $rid, (int) $info['user_id'], (string) $info['username']);
                break;

            case 'challenge':
                $this->handleChallenge($from, $data, (int) $info['user_id'], (string) $info['username']);
                break;

            case 'challenge_response':
                $this->handleChallengeResponse($from, $data, (int) $info['user_id'], (string) $info['username']);
                break;

            case 'challenge_cancel':
                $this->handleChallengeCancel($from, $data, (int) $info['user_id'], (string) $info['username']);
                break;

            default:
                $this->sendError($from, 'unknown_type', ['got' => $data['type']]);
        }
    }

    private function handlePing(ConnectionInterface $conn, int $rid, int $userId): void
    {
        $this->subscriptionService->ping((string) $rid);
        $this->presenceService->updateHeartbeat($userId);
        $this->sendJson($conn, ['type' => 'pong']);
    }

    /** @param array<string, mixed> $data
     *  @param array<string, mixed> $info
     */
    private function handleChat(ConnectionInterface $conn, array $data, array $info): void
    {
        $userId = (int) $info['user_id'];
        $username = (string) $info['username'];
        $text = trim(mb_substr((string) ($data['msg'] ?? ''), 0, self::CHAT_MAX_CHARS));

        if ($text === '') {
            $this->sendError($conn, 'empty_message');
            return;
        }

        $messageId = db_insert_chat_message($this->pdo, 'lobby', 0, $userId, $text, null, $username);

        try {
            log_audit_event($this->pdo, [
                'user_id' => $userId,
                'session_id' => $info['session_id'],
                'action' => 'chat.send',
                'entity_type' => 'chat_message',
                'entity_id' => $messageId,
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
            WebSocketLog::warn('LobbySocket', 'Audit logging failed on chat: ' . $e->getMessage());
        }

        $statement = $this->pdo->prepare("SELECT created_at FROM chat_messages WHERE id = :id LIMIT 1");
        $statement->execute(['id' => $messageId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $createdAt = $row ? $row['created_at'] : date('Y-m-d H:i:s');
        $timestamp = strtotime($createdAt);

        $this->broadcast([
            'type' => 'chat',
            'from' => escape_html($username),
            'msg' => escape_html($text),
            'time' => date('H:i', $timestamp),
            'created_at' => $createdAt,
        ]);
    }

    private function handleLogout(ConnectionInterface $conn, int $rid, int $userId, string $username): void
    {
        $this->explicitLogouts[$userId] = true;
        unset($this->recentDisconnects[$userId]);

        $becameOffline = $this->presenceService->markOffline($userId);
        if ($becameOffline) {
            $this->broadcastLobbyLeave($userId, $username, $conn);
        }

        $this->subscriptionService->disconnect((string) $rid);
        WebSocketJson::closeQuietly($conn);
    }

    /** @param array<string, mixed> $data */
    private function handleChallenge(ConnectionInterface $conn, array $data, int $userId, string $username): void
    {
        $targetUserId = (int) ($data['to_user_id'] ?? 0);
        if ($targetUserId <= 0) {
            $this->sendError($conn, 'invalid_target');
            return;
        }

        $targetUser = db_get_user_by_id($this->pdo, $targetUserId);
        if (!$targetUser) {
            $this->sendError($conn, 'user_not_found');
            return;
        }

        $result = $this->challengeService->send($userId, $targetUser['username']);
        if (!$result['ok']) {
            $this->sendError($conn, (string) ($result['message'] ?? 'Failed to send challenge'));
            return;
        }

        $escapedTargetUsername = escape_html($targetUser['username']);
        $challengeId = (int) ($result['challenge_id'] ?? 0);

        $this->sendToUser($targetUserId, [
            'type' => 'challenge',
            'from' => ['id' => $userId, 'username' => escape_html($username)],
            'challenge_id' => $challengeId,
        ]);

        $this->sendJson($conn, [
            'type' => 'challenge_sent',
            'to' => ['id' => $targetUserId, 'username' => $escapedTargetUsername],
            'challenge_id' => $challengeId,
        ]);
        $this->sendSystemChat($conn, "✅ Challenge sent to {$escapedTargetUsername}.");

        WebSocketLog::debug('LobbySocket', "{$username} challenged {$escapedTargetUsername}");
    }

    /** @param array<string, mixed> $data */
    private function handleChallengeResponse(ConnectionInterface $conn, array $data, int $userId, string $username): void
    {
        $challengeId = (int) ($data['challenge_id'] ?? 0);
        $action = trim((string) ($data['action'] ?? ''));
        if ($challengeId <= 0 || !in_array($action, ['accept', 'decline'], true)) {
            $this->sendError($conn, 'invalid_challenge_response');
            return;
        }

        $challenge = db_get_challenge_for_accept($this->pdo, $challengeId);
        if (!$challenge) {
            $this->sendError($conn, 'challenge_not_found');
            return;
        }

        $fromUserId = (int) $challenge['from_user_id'];
        $toUserId = (int) $challenge['to_user_id'];
        $fromUsername = db_get_username_by_id($this->pdo, $fromUserId) ?? "User#$fromUserId";
        $escapedFromUsername = escape_html($fromUsername);

        $result = $action === 'accept'
            ? $this->challengeService->accept($challengeId, $userId)
            : $this->challengeService->decline($challengeId, $userId);

        if (!$result['ok']) {
            $this->sendError($conn, (string) $result['message']);
            WebSocketLog::debug('LobbySocket', 'Challenge response rejected: ' . ($result['message'] ?? 'Unknown error'));
            return;
        }

        if ($action === 'decline') {
            $this->sendSystemChat($conn, "❌ You declined the challenge from {$escapedFromUsername}.");
        }

        $this->broadcast([
            'type' => 'challenge_response',
            'challenge_id' => $challengeId,
            'action' => $action,
            'from' => ['id' => $userId, 'username' => escape_html($username)],
            'table_id' => $result['table_id'] ?? null,
        ]);

        $this->sendToUser($fromUserId, [
            'type' => 'challenge_resolved',
            'challenge_id' => $challengeId,
            'action' => $action,
        ]);
        $this->sendToUser($toUserId, [
            'type' => 'challenge_resolved',
            'challenge_id' => $challengeId,
            'action' => $action,
        ]);

        if ($action === 'accept') {
            $tableId = isset($result['table_id']) ? (int) $result['table_id'] : null;
            $gameId = isset($result['game_id']) ? (int) $result['game_id'] : null;

            if ($tableId && $gameId) {
                $escapedUsername = escape_html($username);
                $gameStartMessage = [
                    'type' => 'GAME_START',
                    'table_id' => $tableId,
                    'game_id' => $gameId,
                    'message' => 'Challenge accepted! Starting game...',
                ];

                $this->sendToUser($fromUserId, $gameStartMessage);
                $this->sendToUser($toUserId, $gameStartMessage);

                $this->sendSystemChatToUser($fromUserId, "✅ {$escapedUsername} accepted your challenge! Starting game...");

                $this->sendSystemChat($conn, "✅ You accepted the challenge from {$escapedFromUsername}! Starting game...");

                WebSocketLog::debug('LobbySocket', "Challenge #{$challengeId} accepted; created table #{$tableId}, game #{$gameId} for users {$fromUserId} and {$toUserId}");
                return;
            }

            WebSocketLog::error('LobbySocket', 'Challenge accepted but missing table_id or game_id. Result: ' . json_encode($result));
            return;
        }

        if ($fromUserId !== $userId) {
            $escapedUsername = escape_html($username);
            $this->sendSystemChatToUser($fromUserId, "❌ {$escapedUsername} declined your challenge.");
            WebSocketLog::debug('LobbySocket', "{$username} declined challenge from {$escapedFromUsername}");
        }
    }

    /** @param array<string, mixed> $data */
    private function handleChallengeCancel(ConnectionInterface $conn, array $data, int $userId, string $username): void
    {
        $challengeId = (int) ($data['challenge_id'] ?? 0);
        if ($challengeId <= 0) {
            $this->sendError($conn, 'invalid_challenge_id');
            return;
        }

        $challenge = db_get_challenge_for_accept($this->pdo, $challengeId);
        if (!$challenge) {
            $this->sendError($conn, 'challenge_not_found');
            return;
        }

        $fromUserId = (int) $challenge['from_user_id'];
        $toUserId = (int) $challenge['to_user_id'];
        $toUsername = db_get_username_by_id($this->pdo, $toUserId) ?? "User#$toUserId";
        $escapedToUsername = escape_html($toUsername);

        $result = $this->challengeService->cancel($challengeId, $userId);
        if (!$result['ok']) {
            $this->sendError($conn, (string) $result['message']);
            return;
        }

        $this->sendSystemChat($conn, "❌ You canceled your challenge to {$escapedToUsername}.");

        $this->sendSystemChatToUser($toUserId, "❌ {$username} canceled their challenge to you.");

        $this->broadcast([
            'type' => 'challenge_cancel',
            'challenge_id' => $challengeId,
            'from' => ['id' => $userId, 'username' => escape_html($username)],
        ]);

        $this->sendToUser($fromUserId, [
            'type' => 'challenge_resolved',
            'challenge_id' => $challengeId,
            'action' => 'cancelled',
        ]);
        $this->sendToUser($toUserId, [
            'type' => 'challenge_resolved',
            'challenge_id' => $challengeId,
            'action' => 'cancelled',
        ]);

        WebSocketLog::debug('LobbySocket', "{$username} canceled challenge to {$escapedToUsername}");
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

        // Check if user has other active connections BEFORE removing this one.
        $hasOtherConnections = $this->hasOtherActiveConnections($uid, $rid);

        // Now remove this connection
        $this->clients->detach($conn);
        unset($this->connInfo[$rid]);

        try {
            $this->subscriptionService->disconnect((string)$rid);
            
            // Check if this was an explicit logout - if so, don't track in recentDisconnects
            $wasExplicitLogout = $this->consumeExplicitLogout($uid);
            
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
                    WebSocketLog::warn('LobbySocket', 'Audit logging failed on disconnect: ' . $e->getMessage());
                }
                
                // Track disconnect time if it was NOT an explicit logout (to detect quick reconnects)
                if (!$wasExplicitLogout) {
                    $this->recentDisconnects[$uid] = [
                        'timestamp' => self::monotonicNow(),
                        'username' => $uname,
                    ];
                }
                
                $this->presenceService->markOffline($uid);
            }

        } catch (\Throwable $e) {
            WebSocketLog::warn('LobbySocket', 'Cleanup failed: ' . $e->getMessage());
        }

        WebSocketLog::debug('LobbySocket', "{$uname} disconnected");
    }

    private function hasOtherActiveConnections(int $userId, int $excludeRid): bool
    {
        foreach ($this->connInfo as $otherRid => $otherInfo) {
            if ((int) $otherInfo['user_id'] === $userId && $otherRid !== $excludeRid) {
                return true;
            }
        }

        return false;
    }

    private function consumeQuickReconnect(int $userId): bool
    {
        $disconnectInfo = $this->recentDisconnects[$userId] ?? null;
        if ($disconnectInfo === null) {
            return false;
        }

        unset($this->recentDisconnects[$userId]);
        return (self::monotonicNow() - (float) ($disconnectInfo['timestamp'] ?? 0.0)) < self::QUICK_RECONNECT_WINDOW_S;
    }

    private function consumeExplicitLogout(int $userId): bool
    {
        if (!isset($this->explicitLogouts[$userId])) {
            return false;
        }

        unset($this->explicitLogouts[$userId]);
        return true;
    }

    private function flushExpiredDisconnectAnnouncements(?int $skipUserId = null): void
    {
        $now = self::monotonicNow();

        foreach ($this->recentDisconnects as $userId => $info) {
            if ($skipUserId !== null && $userId === $skipUserId) {
                continue;
            }

            if (($now - (float) ($info['timestamp'] ?? 0.0)) < self::QUICK_RECONNECT_WINDOW_S) {
                continue;
            }

            $this->broadcastLobbyLeave($userId, (string) $info['username']);
            unset($this->recentDisconnects[$userId]);
        }
    }

    private function broadcastLobbyJoin(string $username, ConnectionInterface $except): void
    {
        $escapedUsername = escape_html($username);
        $this->broadcastSystemChatExcept($except, "🟢 {$escapedUsername} joined the lobby.");
    }

    private function broadcastLobbyLeave(int $userId, string $username, ?ConnectionInterface $except = null): void
    {
        $escapedUsername = escape_html($username);
        $chatMessage = $this->buildSystemChatPayload("🔴 {$escapedUsername} left the lobby.");

        $presenceMessage = [
            'type'  => 'presence',
            'event' => 'leave',
            'user'  => ['id' => $userId, 'username' => $escapedUsername],
        ];

        if ($except === null) {
            $this->broadcast($chatMessage);
            $this->broadcast($presenceMessage);
            return;
        }

        $this->broadcastExcept($except, $chatMessage);
        $this->broadcastExcept($except, $presenceMessage);
    }

    // -------------------------------------------------------------------------
    // Error + utilities
    // -------------------------------------------------------------------------
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        WebSocketLog::error('LobbySocket', 'Transport error: ' . $e->getMessage());
        $this->sendError($conn, 'server_error');
        WebSocketJson::closeQuietly($conn);
    }

    /** Broadcast JSON to all connected lobby clients. */
    private function broadcast(array $data): void
    {
        $json = $this->encodeJson($data, 'Broadcast encoding failed');
        if ($json === null) {
            return;
        }

        foreach ($this->clients as $client) {
            WebSocketJson::sendEncoded($client, $json, static function (\Throwable $e): void {
                WebSocketLog::warn('LobbySocket', 'Broadcast failed: ' . $e->getMessage());
            });
        }
    }

    /**
     * Public method to broadcast presence updates from GameSocket.
     * Called when a user's presence status changes (e.g., goes in_game).
     */
    public function broadcastPresenceUpdate(array $user): void
    {
        $this->broadcast([
            'type'  => 'presence',
            'event' => 'update',
            'user'  => $user,
        ]);
    }

    /** Broadcast JSON to all connected lobby clients except the excluded connection. */
    private function broadcastExcept(ConnectionInterface $except, array $data): void
    {
        $json = $this->encodeJson($data, 'Broadcast encoding failed');
        if ($json === null) {
            return;
        }

        foreach ($this->clients as $client) {
            if ($client === $except) continue;
            WebSocketJson::sendEncoded($client, $json, static function (\Throwable $e): void {
                WebSocketLog::warn('LobbySocket', 'Broadcast failed: ' . $e->getMessage());
            });
        }
    }

    private function sendSystemChat(ConnectionInterface $conn, string $message): void
    {
        $this->sendJson($conn, $this->buildSystemChatPayload($message));
    }

    private function sendSystemChatToUser(int $userId, string $message): void
    {
        $this->sendToUser($userId, $this->buildSystemChatPayload($message));
    }

    private function broadcastSystemChatExcept(ConnectionInterface $except, string $message): void
    {
        $this->broadcastExcept($except, $this->buildSystemChatPayload($message));
    }

    /** @return array<string, mixed> */
    private function buildSystemChatPayload(string $message): array
    {
        return [
            'type' => 'chat',
            'system' => true,
            'msg' => $message,
            'time' => date('H:i'),
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** Send JSON to a single user by user_id. */
    private function sendToUser(int $userId, array $data): void
    {
        $json = $this->encodeJson($data, 'Send-to-user encoding failed');
        if ($json === null) {
            return;
        }

        foreach ($this->clients as $client) {
            $rid = (int)$client->resourceId;
            if (isset($this->connInfo[$rid]) && $this->connInfo[$rid]['user_id'] === $userId) {
                WebSocketJson::sendEncoded($client, $json, static function (\Throwable $e): void {
                    WebSocketLog::warn('LobbySocket', 'Send to user failed: ' . $e->getMessage());
                });
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function sendJson(ConnectionInterface $conn, array $data): bool
    {
        return WebSocketJson::send($conn, $data);
    }

    /** @param array<string, mixed> $extra */
    private function sendError(ConnectionInterface $conn, string $error, array $extra = []): bool
    {
        return $this->sendJson($conn, ['type' => 'error', 'error' => $error] + $extra);
    }

    /** @param array<string, mixed> $data */
    private function encodeJson(array $data, string $failureContext): ?string
    {
        try {
            return WebSocketJson::encode($data);
        } catch (\Throwable $e) {
            WebSocketLog::error('LobbySocket', $failureContext . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Simple token bucket rate limiter per connection. */
    private function rateAllow(int $rid): bool
    {
        if (!isset($this->connInfo[$rid])) {
            return false;
        }

        $state = &$this->connInfo[$rid]['rate'];
        return TokenBucketRateLimiter::allow($state, self::RATE_TOKENS, self::RATE_REFILL_PER_S);
    }

    private static function monotonicNow(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
