<?php
declare(strict_types=1);

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * AuthenticatedServer
 * -----------------------------------------------------------------------------
 * This class acts as a protective wrapper ("decorator") around a WebSocket
 * endpoint such as LobbySocket.
 *
 * Its main responsibility is to ensure that every incoming connection and
 * message is from an authenticated user before any of the underlying
 * socket logic is executed.
 *
 * It implements Ratchet's MessageComponentInterface, so Ratchet can treat
 * it exactly like any other socket handler. However, before delegating to
 * the real socket, this layer performs authentication and attaches
 * user context information to the connection.
 *
 * Usage example:
 *     $app->route('/lobby', new AuthenticatedServer($pdo, $lobby), ['*']);
 *
 * This means that every new WebSocket connection to /lobby must first pass
 * through this decorator for authentication before reaching LobbySocket.
 */
final class AuthenticatedServer implements MessageComponentInterface {
    /** Database connection used for authentication lookups. */
    private PDO $pdo;

    /** The "inner" socket (e.g., LobbySocket) that handles real logic. */
    private MessageComponentInterface $inner;
    
    /** Track connections per IP for rate limiting */
    private static array $ipConnections = [];
    
    /** Track connections per user for rate limiting */
    private static array $userConnections = [];
    
    /** Maximum connections per IP */
    private const MAX_CONNECTIONS_PER_IP = 10;
    
    /** Maximum connections per user */
    private const MAX_CONNECTIONS_PER_USER = 5;
    
    /** Maximum total connections */
    private const MAX_TOTAL_CONNECTIONS = 1000;
    
    /** Current total connection count */
    private static int $totalConnections = 0;

    /**
     * Constructor
     *
     * @param PDO $pdo Database handle for verifying tokens or sessions.
     * @param MessageComponentInterface $inner The actual socket that will receive
     *        events once authentication is successful.
     */
    public function __construct(PDO $pdo, MessageComponentInterface $inner) {
        $this->pdo   = $pdo;
        $this->inner = $inner;
    }

    /**
     * Get client IP from WebSocket connection or handshake request.
     */
    private function getClientIp(ConnectionInterface $conn, RequestInterface $req): string {
        // Check for proxy headers first (most reliable for production behind reverse proxy)
        $headers = [
            'X-Forwarded-For',
            'X-Real-IP',
            'Client-IP',
        ];
        
        foreach ($headers as $header) {
            $value = $req->getHeaderLine($header);
            if ($value !== '') {
                $ips = explode(',', $value);
                $ip = trim($ips[0]);
                // First try to validate as public IP (preferred for production)
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
                // If not public, still accept if it's a valid IP (for testing/development)
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        // Fallback: get from connection's remote address (Ratchet sets this)
        // Ratchet's IoServer sets $conn->remoteAddress property
        if (isset($conn->remoteAddress) && is_string($conn->remoteAddress)) {
            // Extract IP from address (format: "127.0.0.1:12345" or "tcp://127.0.0.1:12345")
            $address = $conn->remoteAddress;
            // Remove protocol prefix if present
            $address = preg_replace('/^[^:]+:\/\//', '', $address);
            // Extract IP (before colon)
            $parts = explode(':', $address);
            $ip = $parts[0] ?? '';
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        
        // Try getRemoteAddress() method if available (React Socket interface)
        if (method_exists($conn, 'getRemoteAddress')) {
            $address = $conn->getRemoteAddress();
            if ($address !== null) {
                // Parse URI format: "tcp://127.0.0.1:12345"
                $host = parse_url($address, PHP_URL_HOST);
                if ($host !== null) {
                    $ip = trim($host, '[]'); // Remove brackets for IPv6
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }
        
        // Last resort: use placeholder (shouldn't happen in normal operation)
        error_log('[WS] Could not determine client IP for connection');
        return '0.0.0.0';
    }
    
    /**
     * Check connection limits (IP, user, total).
     * 
     * @return array ['allowed' => bool, 'reason' => string|null]
     */
    private function checkConnectionLimits(string $ip, int $userId): array {
        // Check total connection limit
        if (self::$totalConnections >= self::MAX_TOTAL_CONNECTIONS) {
            return ['allowed' => false, 'reason' => 'server_at_capacity'];
        }
        
        // Check IP-based limit
        $ipCount = self::$ipConnections[$ip] ?? 0;
        if ($ipCount >= self::MAX_CONNECTIONS_PER_IP) {
            return ['allowed' => false, 'reason' => 'ip_connection_limit_exceeded'];
        }
        
        // Check user-based limit
        $userCount = self::$userConnections[$userId] ?? 0;
        if ($userCount >= self::MAX_CONNECTIONS_PER_USER) {
            return ['allowed' => false, 'reason' => 'user_connection_limit_exceeded'];
        }
        
        return ['allowed' => true, 'reason' => null];
    }
    
    /**
     * Increment connection counts.
     */
    private function incrementConnectionCounts(string $ip, int $userId): void {
        self::$ipConnections[$ip] = (self::$ipConnections[$ip] ?? 0) + 1;
        self::$userConnections[$userId] = (self::$userConnections[$userId] ?? 0) + 1;
        self::$totalConnections++;
    }
    
    /**
     * Decrement connection counts.
     */
    private function decrementConnectionCounts(string $ip, int $userId): void {
        if (isset(self::$ipConnections[$ip]) && self::$ipConnections[$ip] > 0) {
            self::$ipConnections[$ip]--;
            if (self::$ipConnections[$ip] <= 0) {
                unset(self::$ipConnections[$ip]);
            }
        }
        
        if (isset(self::$userConnections[$userId]) && self::$userConnections[$userId] > 0) {
            self::$userConnections[$userId]--;
            if (self::$userConnections[$userId] <= 0) {
                unset(self::$userConnections[$userId]);
            }
        }
        
        if (self::$totalConnections > 0) {
            self::$totalConnections--;
        }
    }
    
    /**
     * onOpen()
     * -------------------------------------------------------------------------
     * Called by Ratchet when a client first establishes a WebSocket connection.
     * This method intercepts the handshake, extracts the HTTP request object,
     * and runs authentication before letting the connection proceed.
     *
     * Steps:
     *   1. Retrieve the PSR-7 Request object from the connection.
     *   2. Parse query parameters and cookies.
     *   3. Validate credentials using ws_auth().
     *   4. Check connection limits (IP, user, total).
     *   5. If authentication passes, attach 'userCtx' to the connection object
     *      and delegate to the inner socket's onOpen().
     *   6. If authentication fails, send an error message and close the socket.
     */
    public function onOpen(ConnectionInterface $conn): void {
        try {
            /** @var RequestInterface|null $req The HTTP upgrade request from the browser. */
            $req = $conn->httpRequest ?? null;
            if (!$req instanceof RequestInterface) {
                // Defensive check: if Ratchet didn't store the handshake request.
                error_log('[WS] Missing handshake request');
                $conn->close();
                return;
            }

            // Run the authentication routine defined in server.php helpers.
            $ctx = ws_auth($this->pdo, $req);

            if (!$ctx) {
                // Authentication failed: inform client and terminate connection.
                $conn->send(json_encode([
                    'type'    => 'error',
                    'message' => 'Unauthorized WebSocket connection.'
                ]));
                $conn->close();
                return;
            }
            
            // Get client IP and check connection limits
            $ip = $this->getClientIp($conn, $req);
            $userId = (int)$ctx['user_id'];
            $limits = $this->checkConnectionLimits($ip, $userId);
            
            if (!$limits['allowed']) {
                // Connection limit exceeded
                $conn->send(json_encode([
                    'type'    => 'error',
                    'message' => 'Connection limit exceeded: ' . $limits['reason']
                ]));
                $conn->close();
                error_log("[WS] Connection rejected: IP={$ip}, User={$userId}, Reason={$limits['reason']}");
                return;
            }
            
            // Increment connection counts
            $this->incrementConnectionCounts($ip, $userId);
            
            // Store IP and user ID in connection for cleanup on close
            $conn->ipAddress = $ip;
            $conn->userId = $userId;

            // Authentication succeeded.
            // Attach user context to the connection so downstream handlers
            // (LobbySocket, etc.) can access it.
            // Example: $conn->userCtx['user_id']
            $conn->userCtx = $ctx;

            // Pass the event down to the actual socket logic.
            $this->inner->onOpen($conn);

        } catch (Throwable $e) {
            // Catch any unexpected runtime or logic errors to prevent the
            // connection from hanging in an inconsistent state.
            error_log('[WS] onOpen error: ' . $e->getMessage());
            $conn->send(json_encode(['type' => 'error', 'message' => 'server_error']));
            $conn->close();
        }
    }

    /**
     * onMessage()
     * -------------------------------------------------------------------------
     * Called whenever a connected client sends a message.
     *
     * This method first checks whether the connection was authenticated
     * (i.e., whether 'userCtx' was attached during onOpen()).
     *
     * If not authenticated, it immediately closes the connection.
     * If authenticated, it delegates the message to the inner socket handler.
     *
     * @param ConnectionInterface $from The connection that sent the message.
     * @param string $msg The raw message payload from the client.
     */
    public function onMessage(ConnectionInterface $from, $msg): void {
        // Reject messages from unauthorized connections.
        if (!isset($from->userCtx)) {
            $from->send(json_encode(['type' => 'error', 'message' => 'unauthorized']));
            $from->close();
            return;
        }

        // Forward the message to the inner socket (e.g., LobbySocket).
        $this->inner->onMessage($from, $msg);
    }

    /**
     * onClose()
     * -------------------------------------------------------------------------
     * Called when a client disconnects, either intentionally or due to network loss.
     *
     * Only delegates to the inner socket if the connection was authenticated,
     * because unauthenticated connections never reached the main socket layer.
     *
     * This allows LobbySocket to handle user cleanup logic,
     * such as marking the user offline or removing them from a room list.
     */
    public function onClose(ConnectionInterface $conn): void {
        // Decrement connection counts if connection was tracked
        if (isset($conn->ipAddress) && isset($conn->userId)) {
            $this->decrementConnectionCounts($conn->ipAddress, $conn->userId);
        }
        
        if (isset($conn->userCtx)) {
            $this->inner->onClose($conn);
        }
    }

    /**
     * onError()
     * -------------------------------------------------------------------------
     * Called whenever an exception occurs during any of the above phases.
     *
     * This method:
     *   - Logs the error message for server debugging.
     *   - Sends a generic 'server_error' message to the client.
     *   - Delegates the error to the inner socket for any additional handling.
     *   - Closes the connection to prevent lingering corrupted state.
     *
     * @param ConnectionInterface $conn The connection where the error occurred.
     * @param Exception $e The thrown exception instance.
     */
    public function onError(ConnectionInterface $conn, \Exception $e): void {
        error_log('[WS] transport error: ' . $e->getMessage());

        // Notify client that something went wrong on the server side.
        $conn->send(json_encode(['type' => 'error', 'message' => 'server_error']));

        // Allow inner socket to handle any domain-specific cleanup if needed.
        $this->inner->onError($conn, $e);

        // Always close the connection to prevent half-open sockets.
        $conn->close();
    }
}
