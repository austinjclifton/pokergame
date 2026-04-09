<?php
declare(strict_types=1);

final class WebSocketLog
{
    /** @var array<string, int> */
    private const LEVELS = [
        'debug' => 10,
        'info' => 20,
        'warn' => 30,
        'error' => 40,
    ];

    private static ?int $threshold = null;

    public static function debug(string $component, string $message): void
    {
        self::write('debug', $component, $message);
    }

    public static function info(string $component, string $message): void
    {
        self::write('info', $component, $message);
    }

    public static function warn(string $component, string $message): void
    {
        self::write('warn', $component, $message);
    }

    public static function error(string $component, string $message): void
    {
        self::write('error', $component, $message);
    }

    private static function write(string $level, string $component, string $message): void
    {
        if (!self::shouldLog($level)) {
            return;
        }

        error_log(sprintf('[%s][%s] %s', $component, strtoupper($level), $message));
    }

    private static function shouldLog(string $level): bool
    {
        return self::LEVELS[$level] >= self::threshold();
    }

    private static function threshold(): int
    {
        if (self::$threshold !== null) {
            return self::$threshold;
        }

        $configured = strtolower(trim((string) (getenv('WS_LOG_LEVEL') ?: 'info')));
        self::$threshold = self::LEVELS[$configured] ?? self::LEVELS['info'];

        return self::$threshold;
    }
}