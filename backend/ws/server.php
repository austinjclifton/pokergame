<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

use Ratchet\App as RatchetApp;
use React\EventLoop\Factory as LoopFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$backendRoot = dirname(__DIR__);
require_once $backendRoot . '/config/db.php';
require_once $backendRoot . '/lib/WebSocketLog.php';
require_once __DIR__ . '/AuthenticatedServer.php';
require_once __DIR__ . '/LobbySocket.php';
require_once __DIR__ . '/GameSocket.php';

// -----------------------------------------------------------
// Environment detection
// -----------------------------------------------------------

// Local if the runtime hostname matches a local dev machine.
$hostname = gethostname() ?: '';
$IS_LOCAL = (
    str_contains($hostname, 'local') ||
    str_contains($hostname, 'MacBook') ||
    str_contains($hostname, 'mbp') ||
    str_contains($hostname, 'Mac') ||
    php_sapi_name() === 'cli-server'
);

// VM host for HAProxy routing
$VM_HOST = 'pokergame.webdev.gccis.rit.edu';

// Bind host for WebSocket server process
$WS_HOST = '0.0.0.0';            // Listen everywhere
$WS_PORT = (int) (getenv('WS_PORT') ?: 8080);

// Host header Ratchet expects
$APP_HOST = $IS_LOCAL ? 'localhost' : $VM_HOST;

WebSocketLog::info('WebSocketServer', 'Mode: ' . ($IS_LOCAL ? 'LOCAL' : 'VM'));
WebSocketLog::info('WebSocketServer', "Listening on {$WS_HOST}:{$WS_PORT}");
WebSocketLog::info('WebSocketServer', "Expecting Host header: {$APP_HOST}");

$loop = LoopFactory::create();
$lobby = new LobbySocket($pdo);
$game = new GameSocket($pdo, $lobby, $loop);

WebSocketLog::info('WebSocketServer', 'Constructing Ratchet app');

// IMPORTANT:
//   LOCAL → accept Host: localhost
//   VM    → accept Host: pokergame.webdev.gccis.rit.edu
$app = new RatchetApp($APP_HOST, $WS_PORT, $WS_HOST, $loop);

WebSocketLog::info('WebSocketServer', 'Adding routes');

// Internal routes (HAProxy rewrites /ws/lobby → /lobby on VM)
$app->route('/lobby', new AuthenticatedServer($pdo, $lobby, 'lobby'), ['*']);
$app->route('/game', new AuthenticatedServer($pdo, $game, 'game'), ['*']);

WebSocketLog::info('WebSocketServer', 'Routes registered; starting event loop');

$app->run();
