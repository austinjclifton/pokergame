<?php
declare(strict_types=1);

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Psr\Http\Message\RequestInterface;

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
            error_log('[AuthenticatedServer] Token validation error: ' . $e->getMessage());
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
            error_log('[AuthenticatedServer] Session validation error: ' . $e->getMessage());
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
            error_log('[AuthenticatedServer] Missing ws_token for game socket');
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
                $conn->send(json_encode(['type' => 'error', 'error' => 'unauthorized']));
                $conn->close();
                return;
            }

            $userCtx = $this->authenticate($req);

            if (!$userCtx) {
                $conn->send(json_encode(['type' => 'error', 'error' => 'unauthorized']));
                $conn->close();
                return;
            }

            $conn->userCtx = $userCtx;

            // Forward to inner socket lifecycle
            $this->inner->onOpen($conn);

            if (method_exists($this->inner, 'onAuthenticated')) {
                try {
                    $this->inner->onAuthenticated($conn);
                } catch (Throwable $e) {
                    error_log('[AuthenticatedServer] onAuthenticated callback error: ' . $e->getMessage());
                }
            }

        } catch (Throwable $e) {
            error_log('[AuthenticatedServer] onOpen error: ' . $e->getMessage());
            try {
                $conn->send(json_encode(['type' => 'error', 'error' => 'server_error']));
            } catch (Throwable) {}
            try { $conn->close(); } catch (Throwable) {}
        }
    }

    public function onMessage(ConnectionInterface $from, $msg): void {
        if (!isset($from->userCtx) || !is_array($from->userCtx)) {
            try {
                $from->send(json_encode(['type' => 'error', 'error' => 'unauthorized']));
            } catch (Throwable) {}
            try { $from->close(); } catch (Throwable) {}
            return;
        }

        try {
            $this->inner->onMessage($from, $msg);
        } catch (Throwable $e) {
            error_log('[AuthenticatedServer] Error forwarding message: ' . $e->getMessage());
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        if (isset($conn->userCtx)) {
            try {
                $this->inner->onClose($conn);
            } catch (Throwable $e) {
                error_log('[AuthenticatedServer] onClose error: ' . $e->getMessage());
            }
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void {
        $userId = $conn->userCtx['user_id'] ?? 'unknown';
        error_log('[AuthenticatedServer] Transport error for user ' . $userId . ': ' . $e->getMessage());
        try {
            $conn->send(json_encode(['type' => 'error', 'error' => 'server_error']));
        } catch (Throwable) {}

        try {
            $this->inner->onError($conn, $e);
        } catch (Throwable $innerError) {
            error_log('[AuthenticatedServer] Error forwarding onError: ' . $innerError->getMessage());
        }

        try { $conn->close(); } catch (Throwable) {}
    }
}
