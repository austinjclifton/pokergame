<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/TestDatabase.php';

final class TestDatabaseConfigTest extends TestCase
{
    /** @return array<string, string> */
    private function validEnvironment(): array
    {
        return [
            'TEST_DB_HOST' => '127.0.0.1',
            'TEST_DB_PORT' => '3306',
            'TEST_DB_NAME' => 'pokergame_test',
            'TEST_DB_USER' => 'pokergame_test_user',
            'TEST_DB_PASS' => '',
        ];
    }

    public function testAcceptsExplicitTestDatabaseConfiguration(): void
    {
        $config = TestDatabaseConfig::fromArray($this->validEnvironment());

        $this->assertSame('pokergame_test', $config['name']);
        $this->assertSame(3306, $config['port']);
        $this->assertSame('', $config['password']);
    }

    public function testRejectsMissingConfigurationInsteadOfFallingBack(): void
    {
        $environment = $this->validEnvironment();
        unset($environment['TEST_DB_USER']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TEST_DB_USER');
        TestDatabaseConfig::fromArray($environment);
    }

    public function testRejectsDevelopmentDatabaseName(): void
    {
        $environment = $this->validEnvironment();
        $environment['TEST_DB_NAME'] = 'pokergame';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('end in _test');
        TestDatabaseConfig::fromArray($environment);
    }

    public function testRejectsAmbiguousDatabaseName(): void
    {
        $environment = $this->validEnvironment();
        $environment['TEST_DB_NAME'] = 'pokergame_test_backup';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('end in _test');
        TestDatabaseConfig::fromArray($environment);
    }

    public function testRequiresExplicitPasswordEvenWhenItIsEmpty(): void
    {
        $environment = $this->validEnvironment();
        unset($environment['TEST_DB_PASS']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TEST_DB_PASS');
        TestDatabaseConfig::fromArray($environment);
    }
}
