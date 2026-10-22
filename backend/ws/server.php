<?php
declare(strict_types=1);

use Ratchet\App as RatchetApp;
use React\EventLoop\Factory as LoopFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$backendRoot = dirname(__DIR__);
require_once $backendRoot . '/config/db.php';
require_once $backendRoot . '/lib/WebSocketLog.php';
require_once __DIR__ . '/AuthenticatedServer.php';
require_once __DIR__ . '/LobbySocket.php';
require_once __DIR__ . '/GameSocket.php';

$wsHost = trim((string) (getenv('WS_HOST') ?: '127.0.0.1'));
$wsPortRaw = trim((string) (getenv('WS_PORT') ?: '8080'));
$wsAppHost = trim((string) (getenv('WS_APP_HOST') ?: 'localhost'));

if ($wsHost === '' || $wsAppHost === '') {
    throw new RuntimeException('WS_HOST and WS_APP_HOST must not be empty');
}
if (filter_var($wsPortRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
    throw new RuntimeException('WS_PORT must be an integer between 1 and 65535');
}
$wsPort = (int) $wsPortRaw;

WebSocketLog::info('WebSocketServer', "Listening on {$wsHost}:{$wsPort}");
WebSocketLog::info('WebSocketServer', "Accepting Host header: {$wsAppHost}");

$loop = LoopFactory::create();
$lobby = new LobbySocket($pdo);
$game = new GameSocket($pdo, $lobby, $loop);

WebSocketLog::info('WebSocketServer', 'Constructing Ratchet app');

$app = new RatchetApp($wsAppHost, $wsPort, $wsHost, $loop);

WebSocketLog::info('WebSocketServer', 'Adding routes');

$app->route('/lobby', new AuthenticatedServer($pdo, $lobby, 'lobby'), ['*']);
$app->route('/game', new AuthenticatedServer($pdo, $game, 'game'), ['*']);

WebSocketLog::info('WebSocketServer', 'Routes registered; starting event loop');

$app->run();
