<?php
declare(strict_types=1);

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Psr\Http\Message\RequestInterface;

require_once __DIR__ . '/../app/db/nonces.php';
require_once __DIR__ . '/../app/db/sessions.php';
require_once __DIR__ . '/../lib/WebSocketLog.php';
require_once __DIR__ . '/../lib/WebSocketJson.php';

/**
 * AuthenticatedServer
 * -----------------------------------------------------------------------------
 * Production-quality decorator for Ratchet WebSocket components that provides
 * unified authentication and standardized user context.
 *
 * Channel policy:
 *   - "game"  → requires ws_token (no session fallback)
 *   - others  → allow ws_token or session cookie fallback
 */
final class AuthenticatedServer implements MessageComponentInterface {
    private PDO $pdo;
    private MessageComponentInterface $inner;
    private string $channelType;

    public function __construct(PDO $pdo, MessageComponentInterface $inner, string $channelType = 'generic') {
        $this->pdo = $pdo;
        $this->inner = $inner;
        $this->channelType = $channelType;
    }

    /** Parse query string from PSR-7 Request */
    private function parseQuery(RequestInterface $req): array {
        $query = $req->getUri()->getQuery() ?? '';
        parse_str($query, $params);
        return is_array($params) ? $params : [];
    }

    /** Extract cookie by name */
    private function getCookie(RequestInterface $req, string $name): ?string {
        foreach ($req->getHeader('Cookie') as $header) {
            foreach (explode(';', $header) as $pair) {
                $parts = array_map('trim', explode('=', $pair, 2) + [null, null]);
                if ($parts[0] === $name && $parts[1] !== null) {
                    return urldecode($parts[1]);
                }
            }
        }
        return null;
    }

    /** Validate short-lived ws_token via DB lookup */
    private function validateToken(string $token): ?array {
        if ($token === '') return null;

        try {
            $result = db_consume_ws_nonce($this->pdo, $token);
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

    /** Validate persistent session cookie */
    private function validateSession(string $sessionId): ?array {
        $sid = (int)$sessionId;
        if ($sid <= 0) return null;

        try {
            $session = db_get_session_with_user($this->pdo, $sid);
            if (!$session) return null;

            return [
                'user_id' => (int)$session['user_id'],
                'username' => (string)$session['username'],
                'session_id' => (int)$session['session_id'],
            ];
        } catch (Throwable $e) {
            WebSocketLog::error('AuthenticatedServer', 'Session validation failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Authenticate connection.
     * Game sockets must use ws_token — no fallback allowed.
     */
    private function authenticate(RequestInterface $req): ?array {
        $query = $this->parseQuery($req);
        $token = isset($query['token']) ? trim((string)$query['token']) : '';
        $sessionCookie = $this->getCookie($req, 'session_id');

        // Always prefer token if provided
        if ($token !== '') {
            $ctx = $this->validateToken($token);
            if ($ctx) {
                return [
                    'user_id' => $ctx['user_id'],
                    'username' => $ctx['username'],
                    'session_id' => $ctx['session_id'],
                    'token' => $token,
                    'channel' => $this->channelType,
                ];
            }
            // Invalid token — reject regardless of fallback policy
            return null;
        }

        // 🔒 Require token for all "game" sockets
        if ($this->channelType === 'game') {
            WebSocketLog::debug('AuthenticatedServer', 'Rejected game socket without ws_token');
            return null;
        }

        // Fallback allowed only for non-game channels (e.g. lobby)
        if ($sessionCookie !== null) {
            $ctx = $this->validateSession($sessionCookie);
            if ($ctx) {
                return [
                    'user_id' => $ctx['user_id'],
                    'username' => $ctx['username'],
                    'session_id' => $ctx['session_id'],
                    'token' => '',
                    'channel' => $this->channelType,
                ];
            }
        }

        return null;
    }

    public function onOpen(ConnectionInterface $conn): void {
        try {
            $req = $conn->httpRequest ?? null;
            if (!$req instanceof RequestInterface) {
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
