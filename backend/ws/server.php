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
 * Parse query string from WS request
 */
function ws_parse_query(RequestInterface $req): array
{
    parse_str($req->getUri()->getQuery() ?? '', $out);
    return is_array($out) ? $out : [];
}

/**
 * Extract cookie from WS request
 */
function ws_get_cookie(RequestInterface $req, string $name): ?string
{
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
 * Unified WebSocket authentication for local + VM
 */
function ws_auth(PDO $pdo, RequestInterface $req): ?array
{
    $query = ws_parse_query($req);
    $token = trim((string) ($query['token'] ?? ''));
    $cookie = ws_get_cookie($req, 'session_id');

    // Short-lived WS token
    if ($token !== '') {
        $ctx = db_consume_ws_nonce($pdo, $token);
        if ($ctx) {
            return [
                'user_id' => $ctx['user_id'],
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
                'user_id' => (int) $user['id'],
                'session_id' => (int) $user['session_id'],
            ];
        } catch (RuntimeException $e) {
            return null;
        }
    }

    return null;
}

// -----------------------------------------------------------
// Environment detection
// -----------------------------------------------------------

// Local if: CLI / localhost / 127.0.0.1
$IS_LOCAL = (
    php_sapi_name() === 'cli-server' ||
    str_contains(gethostname(), 'local') ||
    isset($argv) ||
    in_array($_SERVER['HOSTNAME'] ?? '', ['localhost', '127.0.0.1'])
);

// VM host for HAProxy routing
$VM_HOST = 'pokergame.webdev.gccis.rit.edu';

// Bind host for WebSocket server process
$WS_HOST = '0.0.0.0';            // Listen everywhere
$WS_PORT = (int) (getenv('WS_PORT') ?: 8080);

// Host header Ratchet expects
$APP_HOST = $IS_LOCAL ? 'localhost' : $VM_HOST;

echo "[WS] Mode: " . ($IS_LOCAL ? "LOCAL" : "VM") . "\n";
echo "[WS] Listening on {$WS_HOST}:{$WS_PORT}\n";
echo "[WS] Expecting Host header: {$APP_HOST}\n";

$lobby = new LobbySocket($pdo);
$game = new GameSocket($pdo);

echo "[WS] Constructing Ratchet App...\n";

// IMPORTANT:
//   LOCAL → accept Host: localhost
//   VM    → accept Host: pokergame.webdev.gccis.rit.edu
$app = new RatchetApp($APP_HOST, $WS_PORT, $WS_HOST);

echo "[WS] Adding routes...\n";

// Internal routes (HAProxy rewrites /ws/lobby → /lobby on VM)
$app->route('/lobby', new AuthenticatedServer($pdo, $lobby, 'lobby'), ['*']);
$app->route('/game', new AuthenticatedServer($pdo, $game, 'game'), ['*']);

echo "[WS] Routes registered. Starting...\n";

$app->run();
