<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AuditService.
 * 
 * Tests the audit logging service functionality including:
 *  - Sensitive data redaction
 *  - IP address hashing
 *  - Hash chain calculation
 *  - Error handling
 * 
 * @covers \AuditService
 * @covers ::log_audit_event
 */
final class AuditServiceTest extends TestCase
{
    private PDO $pdo;
    private bool $inTransaction = false;
    private ?string $originalTimezone = null;
    private int $originalErrmode = PDO::ERRMODE_EXCEPTION;
    private ?string $originalSqlMode = null;
    
    // Use a highly unlikely-to-collide test prefix
    private const TEST_PREFIX = '[TEST_AUDIT_';

    protected function setUp(): void
    {
        global $pdo;
        
        // Save original environment state
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        
        if (!isset($pdo) && isset($GLOBALS['pdo'])) {
            $pdo = $GLOBALS['pdo'];
        }
        
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            try {
                $DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
                $DB_NAME = getenv('DB_NAME') ?: 'pokergame';
                $DB_USER = getenv('DB_USER') ?: 'root';
                $DB_PASS = getenv('DB_PASS') ?: '';
                
                $pdo = new PDO(
                    "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
                    $DB_USER,
                    $DB_PASS,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            } catch (PDOException $e) {
                $this->markTestSkipped('Database connection failed: ' . $e->getMessage());
            }
        }
        
        $this->pdo = $pdo;
        $this->originalErrmode = $this->pdo->getAttribute(PDO::ATTR_ERRMODE);
        
        // Save original SQL mode
        $stmt = $this->pdo->query("SELECT @@sql_mode");
        $this->originalSqlMode = $stmt->fetchColumn();
        
        require_once __DIR__ . '/../../app/services/AuditService.php';
        require_once __DIR__ . '/../../app/db/audit_log.php';
        
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $this->pdo->exec("SET sql_mode = 'STRICT_ALL_TABLES'");
        $this->pdo->beginTransaction();
        $this->inTransaction = true;
    }
    
    protected function tearDown(): void
    {
        if ($this->inTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
            $this->inTransaction = false;
        }
        
        // Clean up test entries (only those with safe test prefix)
        $stmt = $this->pdo->prepare("DELETE FROM audit_log WHERE action LIKE ?");
        $stmt->execute([self::TEST_PREFIX . '%']);
        
        // Restore original environment state
        if ($this->originalTimezone !== null) {
            date_default_timezone_set($this->originalTimezone);
        }
        if (isset($this->pdo)) {
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, $this->originalErrmode);
            if ($this->originalSqlMode !== null) {
                $this->pdo->exec("SET sql_mode = '{$this->originalSqlMode}'");
            }
        }
        
        // Clean up global state
        $_SERVER = [];
        $_COOKIE = [];
        $_POST = [];
        $_GET = [];
    }
    
    /**
     * Helper: Assert verifyHashChain() returns valid structure and logical consistency
     */
    private function assertVerifyHashChainStructure(array $result): void
    {
        $this->assertIsArray($result, 'verifyHashChain() must return array');
        $this->assertArrayHasKey('valid', $result, 'verifyHashChain() must have "valid" key');
        $this->assertIsBool($result['valid'], '"valid" must be boolean');
        $this->assertArrayHasKey('broken_at', $result, 'verifyHashChain() must have "broken_at" key');
        $this->assertArrayHasKey('message', $result, 'verifyHashChain() must have "message" key');
        $this->assertIsString($result['message'], '"message" must be string');
        
        // Logical consistency: if valid=true, broken_at must be null
        if ($result['valid']) {
            $this->assertNull($result['broken_at'], 'No break expected in valid chain');
        }
    }
    
    /**
     * Helper: Safely decode JSON details with validation
     */
    private function decodeDetails(?string $detailsJson): array
    {
        if ($detailsJson === null) {
            return [];
        }
        
        $details = json_decode($detailsJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON in audit log details: ' . json_last_error_msg());
        }
        
        return is_array($details) ? $details : [];
    }

    public function testLogBasicEvent(): void
    {
        $service = new AuditService($this->pdo, false); // Disable hash chain for simplicity
        
        $eventId = $service->log([
            'action' => self::TEST_PREFIX . 'action]',
            'user_id' => 1,
            'details' => ['test' => 'data'],
        ]);
        
        $this->assertGreaterThan(0, $eventId);
        
        // Verify the entry was created
        $stmt = $this->pdo->prepare("SELECT * FROM audit_log WHERE id = :id");
        $stmt->execute(['id' => $eventId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($entry);
        $this->assertEquals(self::TEST_PREFIX . 'action]', $entry['action']);
        $this->assertEquals(1, (int)$entry['user_id']);
        $this->assertEquals('api', $entry['channel']); // Default
        $this->assertEquals('success', $entry['status']); // Default
        $this->assertEquals('info', $entry['severity']); // Default
        
        // Verify details JSON is valid
        $details = $this->decodeDetails($entry['details']);
        $this->assertIsArray($details);
        $this->assertEquals('data', $details['test']);
    }

    public function testLogRequiresAction(): void
    {
        $service = new AuditService($this->pdo, false);
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires "action" field');
        
        $service->log([]);
    }

    public function testLogRedactsSensitiveData(): void
    {
        $service = new AuditService($this->pdo, false);
        
        $eventId = $service->log([
            'action' => self::TEST_PREFIX . 'action]',
            'details' => [
                'password' => 'secret123',
                'password_hash' => '$2y$10$...',
                'token' => 'abc123',
                'api_key' => 'key123',
                'safe_field' => 'not_redacted',
            ],
        ]);
        
        $stmt = $this->pdo->prepare("SELECT details FROM audit_log WHERE id = :id");
        $stmt->execute(['id' => $eventId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $details = $this->decodeDetails($entry['details']);
        $this->assertIsArray($details, 'Audit entry details must decode as valid JSON');
        
        $this->assertEquals('[REDACTED]', $details['password']);
        $this->assertEquals('[REDACTED]', $details['password_hash']);
        $this->assertEquals('[REDACTED]', $details['token']);
        $this->assertEquals('[REDACTED]', $details['api_key']);
        $this->assertEquals('not_redacted', $details['safe_field']);
    }

    public function testLogHashesIpAddress(): void
    {
        $service = new AuditService($this->pdo, false);
        
        $ipAddress = '192.168.1.1';
        
        // Compute expected hash (future-proof: support salt/HMAC if configured)
        $salt = getenv('AUDIT_HASH_SALT');
        if ($salt) {
            $expectedHash = hash_hmac('sha256', $ipAddress, $salt);
        } else {
            $expectedHash = hash('sha256', $ipAddress);
        }
        
        $eventId = $service->log([
            'action' => self::TEST_PREFIX . 'action]',
            'ip_address' => $ipAddress,
        ]);
        
        $stmt = $this->pdo->prepare("SELECT ip_address, ip_hash FROM audit_log WHERE id = :id");
        $stmt->execute(['id' => $eventId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertEquals($ipAddress, $entry['ip_address']);
        $this->assertEquals($expectedHash, $entry['ip_hash']);
    }

    public function testLogCreatesHashChain(): void
    {
        $service = new AuditService($this->pdo, true); // Enable hash chain
        
        // First entry
        $eventId1 = $service->log([
            'action' => self::TEST_PREFIX . 'action1]',
        ]);
        
        // Second entry should have previous_hash
        $eventId2 = $service->log([
            'action' => self::TEST_PREFIX . 'action2]',
        ]);
        
        $stmt = $this->pdo->prepare("SELECT id, timestamp, action, previous_hash FROM audit_log WHERE id IN (?, ?) ORDER BY id");
        $stmt->execute([$eventId1, $eventId2]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $entry1 = $entries[0];
        $entry2 = $entries[1];
        
        // First entry may have previous_hash if there are existing entries in DB
        // What matters is that entry 2's previous_hash matches entry 1's computed hash
        // Use service method instead of duplicating hash logic
        $this->assertNotNull($entry2['previous_hash'], 'Second entry has hash of first');
        $expectedHash = $service->computeEntryHash($entry1);
        $this->assertEquals($expectedHash, $entry2['previous_hash'], 
            'Entry 2 previous_hash should match computed hash of entry 1');
    }

    public function testLogUsesUtcTimestamp(): void
    {
        $service = new AuditService($this->pdo, false);
        
        $before = gmdate('Y-m-d H:i:s');
        $eventId = $service->log([
            'action' => self::TEST_PREFIX . 'action]',
        ]);
        $after = gmdate('Y-m-d H:i:s');
        
        $stmt = $this->pdo->prepare("SELECT timestamp FROM audit_log WHERE id = :id");
        $stmt->execute(['id' => $eventId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertGreaterThanOrEqual($before, $entry['timestamp']);
        $this->assertLessThanOrEqual($after, $entry['timestamp']);
    }

    public function testLogGlobalHelperFunction(): void
    {
        require_once __DIR__ . '/../../app/services/AuditService.php';
        
        $eventId = log_audit_event($this->pdo, [
            'action' => self::TEST_PREFIX . 'helper]',
            'user_id' => 42,
        ]);
        
        $this->assertGreaterThan(0, $eventId);
        
        $stmt = $this->pdo->prepare("SELECT * FROM audit_log WHERE id = :id");
        $stmt->execute(['id' => $eventId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertEquals(self::TEST_PREFIX . 'helper]', $entry['action']);
        $this->assertEquals(42, (int)$entry['user_id']);
    }

    public function testVerifyHashChainWithValidChain(): void
    {
        $service = new AuditService($this->pdo, true);
        
        // Create a chain
        $service->log(['action' => self::TEST_PREFIX . 'action1]']);
        $service->log(['action' => self::TEST_PREFIX . 'action2]']);
        $service->log(['action' => self::TEST_PREFIX . 'action3]']);
        
        $result = $service->verifyHashChain();
        $this->assertVerifyHashChainStructure($result);
        
        $this->assertTrue($result['valid']);
        $this->assertNull($result['broken_at']);
    }

    public function testVerifyHashChainDetectsTampering(): void
    {
        $service = new AuditService($this->pdo, true);
        
        // Create a chain
        $id1 = $service->log(['action' => self::TEST_PREFIX . 'action1]']);
        $id2 = $service->log(['action' => self::TEST_PREFIX . 'action2]']);
        
        // Get entries for manual verification
        $stmt = $this->pdo->prepare("SELECT id, timestamp, action, previous_hash FROM audit_log WHERE id IN (?, ?) ORDER BY id");
        $stmt->execute([$id1, $id2]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Guard against non-numeric IDs
        if (!empty($entries) && !ctype_digit((string)$entries[0]['id'])) {
            $this->markTestSkipped('Non-numeric IDs detected; traversal logic must follow previous_hash links');
        }
        
        $entry1 = $entries[0];
        $entry2 = $entries[1];
        
        // Manually verify hash chain is correct before tampering (using service method)
        $expectedHash2 = $service->computeEntryHash($entry1);
        $this->assertEquals($expectedHash2, $entry2['previous_hash'], 'Entry 2 previous_hash should match entry 1 hash');
        
        // Verify chain is valid before tampering
        $result = $service->verifyHashChain();
        $this->assertVerifyHashChainStructure($result);
        $this->assertTrue($result['valid'], 'Hash chain should be valid before tampering');
        
        // Use savepoint for nested transaction
        if (!$this->pdo->inTransaction()) {
            $this->markTestSkipped('Cannot use savepoints outside transaction');
        }
        
        $this->pdo->exec("SAVEPOINT before_tamper");
        try {
            // Tamper with entry 1 (modify action)
            // This will cause entry 2's previous_hash to no longer match entry 1's hash
            $this->pdo->exec("UPDATE audit_log SET action = '" . self::TEST_PREFIX . "tampered]' WHERE id = {$id1}");
            
            $result = $service->verifyHashChain();
            $this->assertVerifyHashChainStructure($result);
            
            // The chain should be broken at entry 2 (which references tampered entry 1)
            $this->assertFalse($result['valid'], 'Hash chain should detect tampering');
            $this->assertNotNull($result['broken_at'], 'Should identify where chain is broken');
            $this->assertEquals($id2, $result['broken_at'], 'Chain should break at entry 2 (which references tampered entry 1)');
            $this->assertStringContainsString('Hash chain broken', $result['message']);
            
            // Manually verify: entry 2's previous_hash should NOT match tampered entry 1
            $stmt = $this->pdo->prepare("SELECT id, timestamp, action, previous_hash FROM audit_log WHERE id = ?");
            $stmt->execute([$id1]);
            $tamperedEntry1 = $stmt->fetch(PDO::FETCH_ASSOC);
            $tamperedHash1 = $service->computeEntryHash($tamperedEntry1);
            $this->assertNotEquals($entry2['previous_hash'], $tamperedHash1, 
                'Entry 2 previous_hash should NOT match tampered entry 1 hash');
        } finally {
            // Roll back to savepoint to keep DB pristine (only if still in transaction)
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->exec("ROLLBACK TO SAVEPOINT before_tamper");
                    $this->pdo->exec("RELEASE SAVEPOINT before_tamper");
                } catch (PDOException $e) {
                    // Savepoint may have been lost due to implicit commit
                    error_log('[AuditServiceTest] Savepoint rollback failed: ' . $e->getMessage());
                }
            }
        }
    }
    
    public function testInvalidJsonInDetailsThrows(): void
    {
        // Test that decodeDetails() properly throws on invalid JSON
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON');
        
        $this->decodeDetails('{"incomplete": true');
    }
}

