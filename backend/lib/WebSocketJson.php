<?php
declare(strict_types=1);

use Ratchet\ConnectionInterface;

final class WebSocketJson
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @param array<string, mixed> $payload
     */
    public static function encode(array $payload): string
    {
        return json_encode($payload, self::FLAGS);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function send(ConnectionInterface $conn, array $payload, ?callable $onFailure = null): bool
    {
        try {
            return self::sendEncoded($conn, self::encode($payload), $onFailure);
        } catch (\Throwable $e) {
            self::handleFailure($e, $onFailure);
            return false;
        }
    }

    public static function sendEncoded(ConnectionInterface $conn, string $payload, ?callable $onFailure = null): bool
    {
        try {
            $conn->send($payload);
            return true;
        } catch (\Throwable $e) {
            self::handleFailure($e, $onFailure);
            return false;
        }
    }

    public static function closeQuietly(ConnectionInterface $conn, ?callable $onFailure = null): void
    {
        try {
            $conn->close();
        } catch (\Throwable $e) {
            self::handleFailure($e, $onFailure);
        }
    }

    private static function handleFailure(\Throwable $e, ?callable $onFailure): void
    {
        if ($onFailure !== null) {
            $onFailure($e);
        }
    }
}