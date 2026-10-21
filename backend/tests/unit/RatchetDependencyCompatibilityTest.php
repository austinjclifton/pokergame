<?php
declare(strict_types=1);

use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServerInterface;
use Ratchet\Http\Router;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class RatchetDependencyCompatibilityTest extends TestCase
{
    public function testRfc6455HandshakeStillAcceptsAValidUpgradeRequest(): void
    {
        $request = new ServerRequest(
            'GET',
            'ws://localhost/game',
            [
                'Host' => 'localhost',
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
                'Sec-WebSocket-Version' => '13',
            ],
            null,
            '1.1',
        );

        $response = (new ServerNegotiator(new RequestVerifier()))->handshake($request);

        $this->assertSame(101, $response->getStatusCode());
        $this->assertSame('websocket', $response->getHeaderLine('Upgrade'));
        $this->assertSame('Upgrade', $response->getHeaderLine('Connection'));
        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $response->getHeaderLine('Sec-WebSocket-Accept'));
    }

    public function testRatchetRouterStillMatchesGameRouteAndPreservesQueryParameters(): void
    {
        $controller = new class implements HttpServerInterface {
            public ?RequestInterface $openedRequest = null;

            public function onOpen(ConnectionInterface $conn, ?RequestInterface $request = null): void
            {
                $this->openedRequest = $request;
            }

            public function onMessage(ConnectionInterface $from, $msg): void {}
            public function onClose(ConnectionInterface $conn): void {}
            public function onError(ConnectionInterface $conn, Exception $e): void {}
        };

        $routes = new RouteCollection();
        $routes->add('game', new Route(
            '/game',
            ['_controller' => $controller],
            [],
            [],
            'localhost',
            [],
            ['GET'],
        ));

        $router = new Router(new UrlMatcher($routes, new RequestContext()));
        $connection = new class implements ConnectionInterface {
            public HttpServerInterface $controller;
            public function send($data): void {}
            public function close(): void {}
        };
        $request = new ServerRequest('GET', 'ws://localhost/game?token=abc123&table_id=7');

        $router->onOpen($connection, $request);

        $this->assertSame($controller, $connection->controller);
        $this->assertNotNull($controller->openedRequest);
        parse_str($controller->openedRequest->getUri()->getQuery(), $query);
        $this->assertSame(['token' => 'abc123', 'table_id' => '7'], $query);
    }
}
