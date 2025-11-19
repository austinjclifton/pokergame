<?php
// backend/ws/server.php
// -----------------------------------------------------------------------------
// WebSocket Server Bootstrap (Ratchet)
//
// This file is the ENTRY POINT for the entire real-time system.
// When you run `php backend/ws/server.php`, it starts a Ratchet event loop
// that listens on TCP (ws://) and routes authenticated connections
// to domain-specific socket handlers, such as LobbySocket.
//
// Layers:
//   Client  →  Ratchet App  →  AuthenticatedServer  →  LobbySocket
// -----------------------------------------------------------------------------

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

use Ratchet\App as RatchetApp;
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Psr\Http\Message\RequestInterface;

require_once __DIR__ . '/../vendor/autoload.php';

// -----------------------------------------------------------------------------
// Dependency wiring (manual until Composer autoloading is set up)
// -----------------------------------------------------------------------------
$backendRoot = dirname(__DIR__);
require_once $backendRoot . '/config/db.php';               // Provides $pdo
require_once $backendRoot . '/app/services/AuthService.php';
require_once $backendRoot . '/app/db/nonces.php';           // db_consume_ws_nonce()
require_once $backendRoot . '/app/db/sessions.php';         // db_get_session_with_user()
require_once __DIR__ . '/AuthenticatedServer.php';
require_once __DIR__ . '/LobbySocket.php';
require_once __DIR__ . '/GameSocket.php';

// -----------------------------------------------------------------------------
// 🧩 Utility helpers for the WebSocket handshake
// -----------------------------------------------------------------------------

/**
 * Extract query params from the initial HTTP upgrade request.
 */
function ws_parse_query(RequestInterface $req): array {
    parse_str($req->getUri()->getQuery() ?? '', $out);
    return is_array($out) ? $out : [];
}

/**
 * Extract a named cookie from the WebSocket handshake headers.
 */
function ws_get_cookie(RequestInterface $req, string $name): ?string {
    foreach ($req->getHeader('Cookie') as $hdr) {
        foreach (explode(';', $hdr) as $pair) {
            [$k, $v] = array_map('trim', explode('=', $pair, 2) + [null, null]);
            if ($k === $name && $v !== null) {
                return urldecode($v);
            }
        }
    }
    return null;
}

/**
 * Unified authentication step for all WebSocket routes.
 *
 * Tries:
 *   1. Short-lived ws_token (preferred)
 *   2. Fallback to persistent session cookie
 *
 * Returns ['user_id'=>int,'session_id'=>int] or null on failure.
 */
function ws_auth(PDO $pdo, RequestInterface $req): ?array {
    $query  = ws_parse_query($req);
    $token  = isset($query['token']) ? trim((string)$query['token']) : '';
    $cookie = ws_get_cookie($req, 'session_id');

    // Preferred: single-use token
    if ($token !== '') {
        $ctx = db_consume_ws_nonce($pdo, $token);
        if ($ctx) return [
            'user_id'    => $ctx['user_id'],
            'session_id' => $ctx['session_id'],
        ];
        return null; // invalid or expired token
    }

    // Fallback: validate session cookie
    if ($cookie) {
        try {
            $user = auth_require_session($pdo);
            return [
                'user_id'    => (int)$user['id'],
                'session_id' => (int)$user['session_id'],
            ];
        } catch (RuntimeException $e) {
            return null;
        }
    }

    return null;
}

// -----------------------------------------------------------------------------
// Server bootstrap
// -----------------------------------------------------------------------------
$WS_HOST = getenv('WS_HOST') ?: '127.0.0.1';
$WS_PORT = (int)(getenv('WS_PORT') ?: 8080);

echo "[WS] Listening on {$WS_HOST}:{$WS_PORT} (routes: /lobby, /game)\n";

// Instantiate route handlers
$lobby = new LobbySocket($pdo);
$game = new GameSocket($pdo);

// Configure routes with channel-specific AuthenticatedServer wrappers
$app = new RatchetApp($WS_HOST, $WS_PORT, '0.0.0.0');
$app->route('/lobby', new AuthenticatedServer($pdo, $lobby, 'lobby'), ['*']);
$app->route('/game', new AuthenticatedServer($pdo, $game, 'game'), ['*']);

// Run the event loop indefinitely
$app->run();

/*
Future TODOs:
--------------
- Add ReactPHP periodic timers for heartbeat/cleanup.
- Replace manual requires with Composer PSR-4 autoloading.
- Add TLS proxy (wss://) for production.
- Add /status route for monitoring connected clients.
- Support multiple rooms/channels dynamically (future enhancement).
*/
