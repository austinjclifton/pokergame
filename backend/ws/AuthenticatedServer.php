<?php
declare(strict_types=1);

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Psr\Http\Message\RequestInterface;

require_once __DIR__ . '/../app/db/nonces.php';
require_once __DIR__ . '/../app/db/sessions.php';
require_once __DIR__ . '/../app/services/NonceService.php';
require_once __DIR__ . '/../lib/WebSocketLog.php';
require_once __DIR__ . '/../lib/WebSocketJson.php';
require_once __DIR__ . '/../lib/TokenBucketRateLimiter.php';

/**
 * AuthenticatedServer
 * -----------------------------------------------------------------------------
 * Production-quality decorator for Ratchet WebSocket components that provides
 * unified authentication and standardized user context.
 *
 * Every channel requires a short-lived, single-use ws_token and an explicitly
 * allowed browser Origin. Persistent session cookies are never accepted here.
 */
final class AuthenticatedServer implements MessageComponentInterface {
    /** @var array<string, array{ts:float,tokens:float}> */
    private static array $accountRateBuckets = [];

    /** @var array<string, array{capacity:float,refill:float}> */
    private const ACCOUNT_RATE_LIMITS = [
        'game' => ['capacity' => 20.0, 'refill' => 4.0],
        'lobby' => ['capacity' => 10.0, 'refill' => 3.0],
        'generic' => ['capacity' => 10.0, 'refill' => 2.0],
    ];

    private PDO $pdo;
    private MessageComponentInterface $inner;
    private string $channelType;
    /** @var list<string> */
    private array $allowedOrigins;

    /** @param list<string>|null $allowedOrigins */
    public function __construct(PDO $pdo, MessageComponentInterface $inner, string $channelType = 'generic', ?array $allowedOrigins = null) {
        $this->pdo = $pdo;
        $this->inner = $inner;
        $this->channelType = $channelType;
        if ($allowedOrigins === null) {
            $configured = (string) (getenv('WS_ALLOWED_ORIGINS') ?: getenv('APP_ORIGIN') ?: '');
            $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $configured))));
        }
        if ($allowedOrigins === []) {
            throw new InvalidArgumentException('At least one WebSocket Origin must be configured');
        }
        $this->allowedOrigins = $allowedOrigins;
    }

    /** Parse query string from PSR-7 Request */
    private function parseQuery(RequestInterface $req): array {
        $query = $req->getUri()->getQuery() ?? '';
        parse_str($query, $params);
        return is_array($params) ? $params : [];
    }

    private function hasAllowedOrigin(RequestInterface $req): bool {
        $origins = $req->getHeader('Origin');
        if (count($origins) !== 1) {
            return false;
        }
        $origin = trim($origins[0]);
        foreach ($this->allowedOrigins as $allowed) {
            if (hash_equals($allowed, $origin)) {
                return true;
            }
        }
        return false;
    }

    /** Validate short-lived ws_token via DB lookup */
    private function validateToken(string $token): ?array {
        if ($token === '') return null;

        try {
            $result = nonce_consume_ws_token($this->pdo, $token);
            if (!$result) return null;

            return [
                'user_id' => (int)$result['user_id'],
                'username' => (string)$result['username'],
                'session_id' => (int)$result['session_id'],
            ];
        } catch (Throwable $e) {
            WebSocketLog::error('AuthenticatedServer', 'Token validation failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Authenticate a connection with its single-use bearer token. */
    private function authenticate(RequestInterface $req): ?array {
        $query = $this->parseQuery($req);
        $token = isset($query['token']) ? trim((string)$query['token']) : '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        $ctx = $this->validateToken($token);
        if (!$ctx) {
            return null;
        }
        return [
            'user_id' => $ctx['user_id'],
            'username' => $ctx['username'],
            'session_id' => $ctx['session_id'],
            'channel' => $this->channelType,
        ];
    }

    public function onOpen(ConnectionInterface $conn): void {
        try {
            $req = $conn->httpRequest ?? null;
            if (!$req instanceof RequestInterface) {
                $this->rejectUnauthorized($conn);
                return;
            }

            if (!$this->hasAllowedOrigin($req)) {
                WebSocketLog::warn('AuthenticatedServer', 'Rejected WebSocket connection from disallowed Origin');
                $this->rejectUnauthorized($conn);
                return;
            }

            $userCtx = $this->authenticate($req);

            if (!$userCtx) {
                $this->rejectUnauthorized($conn);
                return;
            }

            $conn->userCtx = $userCtx;

            // Forward to inner socket lifecycle
            $this->inner->onOpen($conn);

            if (method_exists($this->inner, 'onAuthenticated')) {
                try {
                    $this->inner->onAuthenticated($conn);
                } catch (Throwable $e) {
                    WebSocketLog::error('AuthenticatedServer', 'onAuthenticated callback failed: ' . $e->getMessage());
                    $this->sendErrorAndClose($conn, 'connection_failed');
                }
            }

        } catch (Throwable $e) {
            WebSocketLog::error('AuthenticatedServer', 'onOpen failed: ' . $e->getMessage());
            $this->sendErrorAndClose($conn, 'server_error');
        }
    }

    public function onMessage(ConnectionInterface $from, $msg): void {
        if (!isset($from->userCtx) || !is_array($from->userCtx)) {
            $this->rejectUnauthorized($from);
            return;
        }

        if (!$this->accountRateAllow($from->userCtx)) {
            $this->sendError($from, 'rate_limited');
            return;
        }

        if (!db_is_session_valid($this->pdo, (int) ($from->userCtx['session_id'] ?? 0))) {
            $this->rejectUnauthorized($from);
            return;
        }

        try {
            $this->inner->onMessage($from, $msg);
        } catch (Throwable $e) {
            WebSocketLog::error('AuthenticatedServer', 'Message forwarding failed: ' . $e->getMessage());
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        if (isset($conn->userCtx)) {
            try {
                $this->inner->onClose($conn);
            } catch (Throwable $e) {
                WebSocketLog::warn('AuthenticatedServer', 'onClose forwarding failed: ' . $e->getMessage());
            }
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void {
        $userId = $conn->userCtx['user_id'] ?? 'unknown';
        WebSocketLog::error('AuthenticatedServer', 'Transport error for user ' . $userId . ': ' . $e->getMessage());
        $this->sendError($conn, 'server_error');

        try {
            $this->inner->onError($conn, $e);
        } catch (Throwable $innerError) {
            WebSocketLog::error('AuthenticatedServer', 'onError forwarding failed: ' . $innerError->getMessage());
        }

        WebSocketJson::closeQuietly($conn);
    }

    private function rejectUnauthorized(ConnectionInterface $conn): void {
        $this->sendErrorAndClose($conn, 'unauthorized');
    }

    /** @param array<string, mixed> $userCtx */
    private function accountRateAllow(array $userCtx): bool {
        $userId = (int) ($userCtx['user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        $limits = self::ACCOUNT_RATE_LIMITS[$this->channelType]
            ?? self::ACCOUNT_RATE_LIMITS['generic'];
        $key = $this->channelType . ':' . $userId;
        $state = self::$accountRateBuckets[$key] ?? null;
        $allowed = TokenBucketRateLimiter::allow(
            $state,
            $limits['capacity'],
            $limits['refill'],
        );
        self::$accountRateBuckets[$key] = $state;

        return $allowed;
    }

    private function sendErrorAndClose(ConnectionInterface $conn, string $error): void {
        $this->sendError($conn, $error);
        WebSocketJson::closeQuietly($conn);
    }

    private function sendError(ConnectionInterface $conn, string $error): bool {
        return $this->sendJson($conn, ['type' => 'error', 'error' => $error]);
    }

    /** @param array<string, mixed> $payload */
    private function sendJson(ConnectionInterface $conn, array $payload): bool {
        return WebSocketJson::send($conn, $payload, static function (Throwable $e): void {
            WebSocketLog::warn('AuthenticatedServer', 'Send failed: ' . $e->getMessage());
        });
    }
}
