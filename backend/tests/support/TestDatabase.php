<?php
declare(strict_types=1);

/**
 * Explicit, fail-closed integration-test database configuration.
 *
 * TEST_DB_* names intentionally differ from application DB_* names so a
 * developer's normal database settings can never become an implicit test
 * target. The integration bootstrap maps the validated values to DB_* only
 * after the safety checks pass, for endpoint subprocess compatibility.
 */
final class TestDatabaseConfig
{
    /**
     * @param array<string, string> $environment
     * @return array{host:string,port:int,name:string,user:string,password:string}
     */
    public static function fromArray(array $environment): array
    {
        $required = [
            'TEST_DB_HOST',
            'TEST_DB_PORT',
            'TEST_DB_NAME',
            'TEST_DB_USER',
            'TEST_DB_PASS',
        ];

        foreach ($required as $key) {
            if (!array_key_exists($key, $environment)) {
                throw new RuntimeException("Missing required integration-test setting: {$key}");
            }
        }

        $host = trim($environment['TEST_DB_HOST']);
        $portRaw = trim($environment['TEST_DB_PORT']);
        $name = trim($environment['TEST_DB_NAME']);
        $user = trim($environment['TEST_DB_USER']);

        if ($host === '' || $name === '' || $user === '') {
            throw new RuntimeException('TEST_DB_HOST, TEST_DB_NAME, and TEST_DB_USER must not be empty');
        }

        if (!ctype_digit($portRaw) || (int) $portRaw < 1 || (int) $portRaw > 65535) {
            throw new RuntimeException('TEST_DB_PORT must be an integer between 1 and 65535');
        }

        if (!preg_match('/^[A-Za-z0-9_]+_test$/', $name)) {
            throw new RuntimeException('TEST_DB_NAME must contain only letters, numbers, and underscores and end in _test');
        }

        return [
            'host' => $host,
            'port' => (int) $portRaw,
            'name' => $name,
            'user' => $user,
            'password' => $environment['TEST_DB_PASS'],
        ];
    }

    /**
     * @return array{host:string,port:int,name:string,user:string,password:string}
     */
    public static function fromEnvironment(): array
    {
        $environment = [];
        foreach (['TEST_DB_HOST', 'TEST_DB_PORT', 'TEST_DB_NAME', 'TEST_DB_USER', 'TEST_DB_PASS'] as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $environment[$key] = $value;
            }
        }

        return self::fromArray($environment);
    }

    /**
     * @param array{host:string,port:int,name:string,user:string,password:string} $config
     */
    public static function connect(array $config): PDO
    {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['host'],
                $config['port'],
                $config['name'],
            ),
            $config['user'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ],
        );

        self::assertSafeConnection($pdo, $config['name']);
        return $pdo;
    }

    public static function assertSafeConnection(PDO $pdo, ?string $expectedName = null): string
    {
        $actualName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($actualName) || !preg_match('/^[A-Za-z0-9_]+_test$/', $actualName)) {
            throw new RuntimeException('Refusing integration tests: the active database does not end in _test');
        }

        if ($expectedName !== null && !hash_equals($expectedName, $actualName)) {
            throw new RuntimeException("Refusing integration tests: connected to {$actualName}, expected {$expectedName}");
        }

        return $actualName;
    }

    /**
     * Expose the already validated test settings to application bootstrap code
     * and endpoint subprocesses. No fallback values are introduced here.
     *
     * @param array{host:string,port:int,name:string,user:string,password:string} $config
     */
    public static function publishForApplication(array $config): void
    {
        $mapping = [
            'APP_ENV' => 'testing',
            'DB_HOST' => $config['host'],
            'DB_PORT' => (string) $config['port'],
            'DB_NAME' => $config['name'],
            'DB_USER' => $config['user'],
            'DB_PASS' => $config['password'],
        ];

        foreach ($mapping as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

function test_database_pdo(): PDO
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Integration-test PDO was not initialized; use phpunit.integration.xml');
    }

    TestDatabaseConfig::assertSafeConnection($pdo, (string) getenv('TEST_DB_NAME'));
    return $pdo;
}
