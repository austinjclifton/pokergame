<?php
declare(strict_types=1);

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class WebSocketTestConnection implements ConnectionInterface
{
    public ?array $userCtx;
    public array $sentMessages = [];
    public bool $isClosed = false;

    public function __construct(
        public int $resourceId,
        public ?RequestInterface $httpRequest = null,
        ?array $userCtx = null,
    ) {
        $this->userCtx = $userCtx;
    }

    public function send($data): void
    {
        if ($this->isClosed) {
            throw new RuntimeException('Cannot send message on closed connection');
        }

        $this->sentMessages[] = $data;
    }

    public function close(): void
    {
        $this->isClosed = true;
    }
}

final class WebSocketTestRequest implements RequestInterface
{
    private UriInterface $uri;

    /** @var array<string, array<int, string>> */
    private array $headers;

    /**
     * @param array<string, array<int, string>> $headers
     */
    public function __construct(string $path = '/socket', string $queryString = '', array $headers = [])
    {
        $normalizedHeaders = [];
        foreach ($headers as $name => $values) {
            $normalizedHeaders[strtolower($name)] = $values;
        }
        $this->headers = $normalizedHeaders;

        $this->uri = new class($path, $queryString) implements UriInterface {
            public function __construct(
                private string $path,
                private string $queryString,
            ) {
            }

            public function getQuery(): string { return $this->queryString; }
            public function getScheme(): string { return 'ws'; }
            public function getAuthority(): string { return ''; }
            public function getUserInfo(): string { return ''; }
            public function getHost(): string { return ''; }
            public function getPort(): ?int { return null; }
            public function getPath(): string { return $this->path; }
            public function getFragment(): string { return ''; }
            public function withScheme(string $scheme): UriInterface { return $this; }
            public function withUserInfo(string $user, ?string $password = null): UriInterface { return $this; }
            public function withHost(string $host): UriInterface { return $this; }
            public function withPort(?int $port): UriInterface { return $this; }
            public function withPath(string $path): UriInterface { return $this; }
            public function withQuery(string $query): UriInterface { return $this; }
            public function withFragment(string $fragment): UriInterface { return $this; }
            public function __toString(): string { return ''; }
        };
    }

    public function getRequestTarget(): string { return ''; }
    public function withRequestTarget($requestTarget): RequestInterface { return $this; }
    public function getMethod(): string { return 'GET'; }
    public function withMethod($method): RequestInterface { return $this; }
    public function getUri(): UriInterface { return $this->uri; }
    public function withUri(UriInterface $uri, $preserveHost = false): RequestInterface { return $this; }
    public function getProtocolVersion(): string { return '1.1'; }
    public function withProtocolVersion($version): RequestInterface { return $this; }
    public function getHeaders(): array { return $this->headers; }
    public function hasHeader($name): bool { return isset($this->headers[strtolower((string) $name)]); }
    public function getHeader($name): array { return $this->headers[strtolower((string) $name)] ?? []; }
    public function getHeaderLine($name): string { return implode(', ', $this->getHeader($name)); }
    public function withHeader($name, $value): RequestInterface { return $this; }
    public function withAddedHeader($name, $value): RequestInterface { return $this; }
    public function withoutHeader($name): RequestInterface { return $this; }
    public function getBody(): \Psr\Http\Message\StreamInterface
    {
        return new class implements \Psr\Http\Message\StreamInterface {
            public function __toString(): string { return ''; }
            public function close(): void {}
            public function detach() { return null; }
            public function getSize(): ?int { return null; }
            public function tell(): int { return 0; }
            public function eof(): bool { return true; }
            public function isSeekable(): bool { return false; }
            public function seek($offset, $whence = SEEK_SET): void {}
            public function rewind(): void {}
            public function isWritable(): bool { return false; }
            public function write($string): int { return 0; }
            public function isReadable(): bool { return false; }
            public function read($length): string { return ''; }
            public function getContents(): string { return ''; }
            public function getMetadata($key = null) { return null; }
        };
    }
    public function withBody(\Psr\Http\Message\StreamInterface $body): RequestInterface { return $this; }
}

final class WebSocketTestTimer implements TimerInterface
{
    public function __construct(
        private float $interval,
        private $callback,
        private bool $periodic,
    ) {
    }

    public function getInterval(): float
    {
        return $this->interval;
    }

    public function getCallback(): callable
    {
        return $this->callback;
    }

    public function isPeriodic(): bool
    {
        return $this->periodic;
    }
}

final class WebSocketTestLoop implements LoopInterface
{
    /** @var list<WebSocketTestTimer> */
    public array $periodicTimers = [];

    public function addReadStream($stream, $listener)
    {
    }

    public function addWriteStream($stream, $listener)
    {
    }

    public function removeReadStream($stream)
    {
    }

    public function removeWriteStream($stream)
    {
    }

    public function addTimer($interval, $callback)
    {
        return new WebSocketTestTimer((float) $interval, $callback, false);
    }

    public function addPeriodicTimer($interval, $callback)
    {
        $timer = new WebSocketTestTimer((float) $interval, $callback, true);
        $this->periodicTimers[] = $timer;
        return $timer;
    }

    public function cancelTimer(TimerInterface $timer)
    {
        $this->periodicTimers = array_values(array_filter(
            $this->periodicTimers,
            static fn(WebSocketTestTimer $candidate): bool => $candidate !== $timer
        ));
    }

    public function futureTick($listener)
    {
    }

    public function addSignal($signal, $listener)
    {
    }

    public function removeSignal($signal, $listener)
    {
    }

    public function run()
    {
    }

    public function stop()
    {
    }

    public function runPeriodicTimers(): void
    {
        foreach ($this->periodicTimers as $timer) {
            ($timer->getCallback())($timer);
        }
    }
}