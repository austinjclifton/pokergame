<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

use Ratchet\App as RatchetApp;
use Psr\Http\Message\RequestInterface;

require_once __DIR__ . '/../vendor/autoload.php';

$backendRoot = dirname(__DIR__);
require_once $backendRoot . '/config/db.php';
require_once $backendRoot . '/app/services/AuthService.php';
require_once $backendRoot . '/app/db/nonces.php';
require_once $backendRoot . '/app/db/sessions.php';
require_once __DIR__ . '/AuthenticatedServer.php';
require_once __DIR__ . '/LobbySocket.php';
require_once __DIR__ . '/GameSocket.php';

/**
 * Parse query string from the WS upgrade request.
 */
function ws_parse_query(RequestInterface $req): array {
    parse_str($req->getUri()->getQuery() ?? '', $out);
    return is_array($out) ? $out : [];
}

/**
 * Extract a cookie from the upgrade request.
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
 * Unified WebSocket authentication.
 */
function ws_auth(PDO $pdo, RequestInterface $req): ?array {
    $query  = ws_parse_query($req);
    $token  = trim((string)($query['token'] ?? ''));
    $cookie = ws_get_cookie($req, 'session_id');

    // Preferred short-lived token
    if ($token !== '') {
        $ctx = db_consume_ws_nonce($pdo, $token);
        if ($ctx) {
            return [
                'user_id'    => $ctx['user_id'],
                'session_id' => $ctx['session_id'],
            ];
        }
        return null;
    }

    // Fallback session cookie
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

$WS_HOST = '0.0.0.0';
$WS_PORT = (int)(getenv('WS_PORT') ?: 8080);

echo "[WS] Listening on {$WS_HOST}:{$WS_PORT} (internal routes: /lobby, /game)\n";

$lobby = new LobbySocket($pdo);
$game  = new GameSocket($pdo);

echo "Constructing Ratchet App…\n";
$app = new RatchetApp('pokergame.webdev.gccis.rit.edu', $WS_PORT, '0.0.0.0');

echo "Adding internal routes…\n";

// IMPORTANT: These are *internal* routes.
// HAProxy rewrites /ws/lobby → /lobby, and /ws/game → /game
$app->route('/lobby', new AuthenticatedServer($pdo, $lobby, 'lobby'), ['*']);
$app->route('/game',  new AuthenticatedServer($pdo, $game,  'game'), ['*']);

echo "Routes registered. Starting server…\n";

$app->run();
