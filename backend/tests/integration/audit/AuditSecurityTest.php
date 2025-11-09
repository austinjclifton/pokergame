<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Security tests for audit logging system.
 * 
 * Tests tamper prevention and data protection:
 *  - Hash chain tamper detection
 *  - Sensitive data redaction
 *  - IP address hashing
 *  - Append-only design verification
 * 
 * @covers \AuditService
 */
final class AuditSecurityTest extends TestCase
{
    private PDO $pdo;
    private bool $inTransaction = false;

    private ?string $originalTimezone = null;
    private int $originalErrmode = PDO::ERRMODE_EXCEPTION;
    private ?string $originalSqlMode = null;
    
    // Use a highly unlikely-to-collide test prefix (UUID-like pattern)
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
        
        require_once __DIR__ . '/../../../app/services/AuditService.php';
        require_once __DIR__ . '/../../../app/db/audit_log.php';
        
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
        
        // Clean up test entries (only those with __test__ prefix)
        $this->cleanupTestEntries();
        
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
        
        // Clean up global state that might affect other tests
        $_SERVER = [];
        $_COOKIE = [];
        $_POST = [];
        $_GET = [];
    }
    
    /**
     * Helper: Delete test entries (only those with safe test prefix)
     */
    private function cleanupTestEntries(): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM audit_log WHERE action LIKE ?");
        $stmt->execute([self::TEST_PREFIX . '%']);
    }
    
    /**
     * Helper: Create a test chain and return entries in chain order
     * 
     * Note: Currently uses ID ordering since we're in a transaction and IDs are sequential.
     * For future-proofing with non-sequential IDs (UUIDs, sharded inserts), we'd traverse
     * by following previous_hash links starting from the entry with null previous_hash.
     */
    private function makeTestChain(AuditService $service, int $count = 3): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $service->log(['action' => self::TEST_PREFIX . "chain.entry{$i}]"]);
        }
        
        // Get entries by ID (in transaction, IDs are sequential)
        // Check if IDs are numeric - if not, we'd need hash-based traversal
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, timestamp, action, previous_hash FROM audit_log WHERE id IN ($placeholders) ORDER BY id");
        $stmt->execute($ids);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Guard against non-numeric IDs
        if (!empty($entries) && !ctype_digit((string)$entries[0]['id'])) {
            $this->markTestSkipped('Non-numeric IDs detected; traversal logic must follow previous_hash links');
        }
        
        return $entries;
    }
    
    /**
     * Independent hash computation for cross-verification (doesn't use AuditService)
     * This provides a secondary check to ensure hash chain logic is correct.
     * 
     * @param array{id: int|string, timestamp: string, action: string, previous_hash?: string|null} $entry
     * @return string SHA-256 hash
     */
    private function independentlyComputeEntryHash(array $entry): string
    {
        // This is an independent implementation that should match AuditService::computeEntryHash
        // If they diverge, we'll catch it in tests
        $hashData = sprintf(
            '%s|%s|%s|%s',
            (string)$entry['id'],
            $entry['timestamp'],
            $entry['action'],
            $entry['previous_hash'] ?? ''
        );
        return hash('sha256', $hashData);
    }
    
    /**
     * Verify chain integrity independently (cross-check against service method)
     */
    private function independentlyVerifyChainIntegrity(array $entries, AuditService $service): void
    {
        if (empty($entries)) {
            return;
        }
        
        $previousEntry = null;
        foreach ($entries as $entry) {
            if ($previousEntry === null) {
                $previousEntry = $entry;
                continue;
            }
            
            // Compute hash using both methods and verify they match
            $serviceHash = $service->computeEntryHash($previousEntry);
            $independentHash = $this->independentlyComputeEntryHash($previousEntry);
            
            $this->assertEquals($serviceHash, $independentHash, 
                'Service and independent hash computation should match');
            
            // Verify entry's previous_hash matches computed hash
            $this->assertEquals($serviceHash, $entry['previous_hash'],
                "Entry {$entry['id']} previous_hash should match computed hash of entry {$previousEntry['id']}");
            
            $previousEntry = $entry;
        }
    }
    
    /**
     * Assert chain integrity before tampering (DRY helper)
     */
    private function assertChainIntegrityBeforeTamper(array $chainEntries, AuditService $service): void
    {
        $this->assertGreaterThanOrEqual(2, count($chainEntries), 'Need at least 2 entries for chain test');
        
        // Verify using service method
        foreach ($chainEntries as $idx => $entry) {
            if ($idx === 0) {
                continue; // Skip first entry
            }
            $previousEntry = $chainEntries[$idx - 1];
            $expectedHash = $service->computeEntryHash($previousEntry);
            $this->assertEquals($expectedHash, $entry['previous_hash'],
                "Entry {$entry['id']} previous_hash should match entry {$previousEntry['id']} hash");
        }
        
        // Cross-verify using independent computation
        $this->independentlyVerifyChainIntegrity($chainEntries, $service);
        
        // Verify chain is valid
        $result = $service->verifyHashChain();
        $this->assertVerifyHashChainStructure($result);
        $this->assertTrue($result['valid'], 'Hash chain should be valid before tampering');
        $this->assertNull($result['broken_at'], 'No break expected in valid chain');
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

    public function testSensitiveDataIsRedacted(): void
    {
        $service = new AuditService($this->pdo, false);
        
        $sensitiveData = [
            'password' => 'MySecretPassword123!',
            'password_hash' => '$2y$10$abcdefghijklmnopqrstuv',
            'token' => 'secret_token_abc123',
            'refresh_token' => 'refresh_secret_xyz789',
            'api_key' => 'sk_live_1234567890',
            'secret' => 'my_secret_value',
            'nonce' => 'random_nonce_value',
            'safe_data' => 'this_is_safe',
        ];
        
        $eventId = $service->log([
            'action' => self::TEST_PREFIX . 'sensitive]',
            'details' => $sensitiveData,
        ]);
        
        $stmt = $this->pdo->prepare("SELECT details FROM audit_log WHERE id = :id");
        $stmt->execute(['id' => $eventId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $details = $this->decodeDetails($entry['details']);
        $this->assertIsArray($details, 'Audit entry details must decode as valid JSON');
        
        // All sensitive fields should be redacted
        $this->assertEquals('[REDACTED]', $details['password']);
        $this->assertEquals('[REDACTED]', $details['password_hash']);
        $this->assertEquals('[REDACTED]', $details['token']);
        $this->assertEquals('[REDACTED]', $details['refresh_token']);
        $this->assertEquals('[REDACTED]', $details['api_key']);
        $this->assertEquals('[REDACTED]', $details['secret']);
        $this->assertEquals('[REDACTED]', $details['nonce']);
        
        // Safe data should remain
        $this->assertEquals('this_is_safe', $details['safe_data']);
    }

    public function testSensitiveDataRedactionIsRecursive(): void
    {
        $service = new AuditService($this->pdo, false);
        
        $nestedData = [
            'user' => [
                'username' => 'testuser',
                'password' => 'secret123',
                'profile' => [
                    'email' => 'test@example.com',
                    'api_key' => 'key123',
                ],
            ],
        ];
        
        $eventId = $service->log([
            'action' => self::TEST_PREFIX . 'nested]',
            'details' => $nestedData,
        ]);
        
        $stmt = $this->pdo->prepare("SELECT details FROM audit_log WHERE id = :id");
        $stmt->execute(['id' => $eventId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $details = $this->decodeDetails($entry['details']);
        $this->assertIsArray($details, 'Audit entry details must decode as valid JSON');
        
        $this->assertEquals('[REDACTED]', $details['user']['password']);
        $this->assertEquals('[REDACTED]', $details['user']['profile']['api_key']);
        $this->assertEquals('testuser', $details['user']['username']);
        $this->assertEquals('test@example.com', $details['user']['profile']['email']);
    }

    public function testIpAddressIsHashed(): void
    {
        $service = new AuditService($this->pdo, false);
        
        $ipAddress = '192.168.1.50';
        
        // Compute expected hash (future-proof: support salt/HMAC if configured)
        $salt = getenv('AUDIT_HASH_SALT');
        if ($salt) {
            $expectedHash = hash_hmac('sha256', $ipAddress, $salt);
        } else {
            $expectedHash = hash('sha256', $ipAddress);
        }
        
        $eventId = $service->log([
            'action' => self::TEST_PREFIX . 'ip]',
            'ip_address' => $ipAddress,
        ]);
        
        $stmt = $this->pdo->prepare("SELECT ip_address, ip_hash FROM audit_log WHERE id = :id");
        $stmt->execute(['id' => $eventId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // IP hash should match
        $this->assertEquals($expectedHash, $entry['ip_hash']);
        
        // Original IP may be stored for debugging (depending on privacy policy)
        // In production, you might want to set ip_address to NULL after hashing
    }

    public function testHashChainDetectsTampering(): void
    {
        $service = new AuditService($this->pdo, true);
        
        // Create a test chain using helper
        $chainEntries = $this->makeTestChain($service, 3);
        $this->assertCount(3, $chainEntries, 'Should have 3 chain entries');
        
        $entry1 = $chainEntries[0];
        $entry2 = $chainEntries[1];
        $entry3 = $chainEntries[2];
        
        // Verify chain integrity before tampering (DRY helper)
        $this->assertChainIntegrityBeforeTamper($chainEntries, $service);
        
        // Use savepoint for nested transaction (MySQL doesn't support true nested transactions)
        // Guard against transaction state issues
        if (!$this->pdo->inTransaction()) {
            $this->markTestSkipped('Cannot use savepoints outside transaction');
        }
        
        $this->pdo->exec("SAVEPOINT before_tamper");
        try {
            // Tamper with entry 2 (modify the action)
            // This will cause entry 3's previous_hash to no longer match entry 2's hash
            $this->pdo->exec("UPDATE audit_log SET action = '" . self::TEST_PREFIX . "TAMPERED]' WHERE id = {$entry2['id']}");
            
            // Verify chain should detect the tampering
            $result = $service->verifyHashChain();
            $this->assertVerifyHashChainStructure($result);
            
            $this->assertFalse($result['valid'], 'Hash chain should be invalid after tampering');
            $this->assertNotNull($result['broken_at'], 'Should identify where chain is broken');
            $this->assertEquals($entry3['id'], $result['broken_at'], 'Chain should break at entry 3 (which references tampered entry 2)');
            $this->assertStringContainsString('Hash chain broken', $result['message']);
            
            // Manually verify: entry 3's previous_hash should NOT match tampered entry 2
            $stmt = $this->pdo->prepare("SELECT id, timestamp, action, previous_hash FROM audit_log WHERE id = ?");
            $stmt->execute([$entry2['id']]);
            $tamperedEntry2 = $stmt->fetch(PDO::FETCH_ASSOC);
            $tamperedHash2 = $service->computeEntryHash($tamperedEntry2);
            $this->assertNotEquals($entry3['previous_hash'], $tamperedHash2, 
                'Entry 3 previous_hash should NOT match tampered entry 2 hash');
        } finally {
            // Roll back to savepoint to keep DB pristine (only if still in transaction)
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->exec("ROLLBACK TO SAVEPOINT before_tamper");
                    $this->pdo->exec("RELEASE SAVEPOINT before_tamper");
                } catch (PDOException $e) {
                    // Savepoint may have been lost due to implicit commit - log but continue
                    error_log('[AuditSecurityTest] Savepoint rollback failed: ' . $e->getMessage());
                }
            }
        }
    }

    public function testHashChainPreventsDeletion(): void
    {
        $service = new AuditService($this->pdo, true);
        
        // Create a test chain using helper
        $chainEntries = $this->makeTestChain($service, 3);
        $this->assertCount(3, $chainEntries, 'Should have 3 chain entries');
        
        $entry1 = $chainEntries[0];
        $entry2 = $chainEntries[1];
        $entry3 = $chainEntries[2];
        
        // Verify chain integrity before deletion (DRY helper)
        $this->assertChainIntegrityBeforeTamper($chainEntries, $service);
        
        // Use savepoint for nested transaction
        if (!$this->pdo->inTransaction()) {
            $this->markTestSkipped('Cannot use savepoints outside transaction');
        }
        
        $this->pdo->exec("SAVEPOINT before_deletion");
        try {
            // Attempt to delete entry 2 (simulating tampering)
            $this->pdo->exec("DELETE FROM audit_log WHERE id = {$entry2['id']}");
            
            // Verify chain should detect the break
            // Entry 3's previous_hash points to entry 2, which no longer exists
            // The hash chain verification will try to verify entry 3's previous_hash
            // against entry 1 (now the previous entry), and it won't match
            $result = $service->verifyHashChain();
            $this->assertVerifyHashChainStructure($result);
            
            $this->assertFalse($result['valid'], 'Hash chain should be invalid after deletion');
            $this->assertNotNull($result['broken_at'], 'Should identify where chain is broken');
            $this->assertEquals($entry3['id'], $result['broken_at'], 'Chain should break at entry 3 (which references deleted entry 2)');
            $this->assertStringContainsString('Hash chain broken', $result['message']);
            
            // Manually verify: entry 3's previous_hash should NOT match entry 1 (since entry 2 was deleted)
            $stmt = $this->pdo->prepare("SELECT id, timestamp, action, previous_hash FROM audit_log WHERE id = ?");
            $stmt->execute([$entry1['id']]);
            $remainingEntry1 = $stmt->fetch(PDO::FETCH_ASSOC);
            $hash1 = $service->computeEntryHash($remainingEntry1);
            $this->assertNotEquals($entry3['previous_hash'], $hash1, 
                'Entry 3 previous_hash should NOT match entry 1 hash (entry 2 was deleted)');
        } finally {
            // Roll back to savepoint to keep DB pristine (only if still in transaction)
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->exec("ROLLBACK TO SAVEPOINT before_deletion");
                    $this->pdo->exec("RELEASE SAVEPOINT before_deletion");
                } catch (PDOException $e) {
                    // Savepoint may have been lost due to implicit commit
                    error_log('[AuditSecurityTest] Savepoint rollback failed: ' . $e->getMessage());
                }
            }
        }
    }

    public function testAuditLogUpdateIsAllowedAndDetectable(): void
    {
        // This test verifies that updating an audit log entry is detectable via hash chain
        // NOTE: This test documents that updates are ALLOWED (not prevented) - they are only detectable
        // In production, you should prevent updates via database triggers or write-only user
        
        // If immutability is required, fail if updates succeed
        $requireImmutability = getenv('REQUIRE_AUDIT_IMMUTABILITY') === 'true';
        
        $service = new AuditService($this->pdo, true);
        
        // Create a chain so we can verify updates break it
        $id1 = $service->log(['action' => self::TEST_PREFIX . 'immutability.original]']);
        $id2 = $service->log(['action' => self::TEST_PREFIX . 'immutability.second]']);
        
        // Get original entry
        $stmt = $this->pdo->prepare("SELECT id, timestamp, action, previous_hash FROM audit_log WHERE id = ?");
        $stmt->execute([$id1]);
        $originalEntry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verify chain is valid before update
        $result = $service->verifyHashChain();
        $this->assertVerifyHashChainStructure($result);
        $this->assertTrue($result['valid'], 'Hash chain should be valid before update');
        
        // Use savepoint for nested transaction
        if (!$this->pdo->inTransaction()) {
            $this->markTestSkipped('Cannot use savepoints outside transaction');
        }
        
        $this->pdo->exec("SAVEPOINT before_update");
        try {
            // Attempt to update (this should break the hash chain)
            $stmt = $this->pdo->prepare("UPDATE audit_log SET action = ? WHERE id = ?");
            $stmt->execute([self::TEST_PREFIX . 'immutability.modified]', $id1]);
            
            // Verify the update succeeded
            $stmt = $this->pdo->prepare("SELECT action FROM audit_log WHERE id = :id");
            $stmt->execute(['id' => $id1]);
            $entry = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If immutability is required, fail the test
            if ($requireImmutability) {
                $this->fail('Audit log UPDATE succeeded — table must be immutable (REQUIRE_AUDIT_IMMUTABILITY=true)');
            }
            
            $this->assertEquals(self::TEST_PREFIX . 'immutability.modified]', $entry['action'], 
                'Update succeeded (but should be prevented in production)');
            
            // Verify hash chain detects the break
            $result = $service->verifyHashChain();
            $this->assertVerifyHashChainStructure($result);
            $this->assertFalse($result['valid'], 'Hash chain should be invalid after update');
            $this->assertEquals($id2, $result['broken_at'], 'Chain should break at entry 2');
        } finally {
            // Roll back to savepoint to keep DB pristine (only if still in transaction)
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->exec("ROLLBACK TO SAVEPOINT before_update");
                    $this->pdo->exec("RELEASE SAVEPOINT before_update");
                } catch (PDOException $e) {
                    // Savepoint may have been lost due to implicit commit
                    error_log('[AuditSecurityTest] Savepoint rollback failed: ' . $e->getMessage());
                }
            }
        }
        
        // Note: In a production system, you should:
        // 1. Use a write-only database user for audit logs (no UPDATE/DELETE permissions)
        // 2. Add database triggers to prevent UPDATE/DELETE on audit_log table
        // 3. Set REQUIRE_AUDIT_IMMUTABILITY=true in CI/production to enforce immutability
    }

    public function testUnknownIpAddressIsHandled(): void
    {
        $service = new AuditService($this->pdo, false);
        
        $eventId = $service->log([
            'action' => self::TEST_PREFIX . 'unknown_ip]',
            'ip_address' => 'unknown',
        ]);
        
        $stmt = $this->pdo->prepare("SELECT ip_hash FROM audit_log WHERE id = :id");
        $stmt->execute(['id' => $eventId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 'unknown' should not be hashed
        $this->assertNull($entry['ip_hash']);
    }

    public function testNullIpAddressIsHandled(): void
    {
        $service = new AuditService($this->pdo, false);
        
        $eventId = $service->log([
            'action' => self::TEST_PREFIX . 'null_ip]',
            'ip_address' => null,
        ]);
        
        $stmt = $this->pdo->prepare("SELECT ip_hash FROM audit_log WHERE id = :id");
        $stmt->execute(['id' => $eventId]);
        $entry = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNull($entry['ip_hash']);
    }
    
    public function testInvalidJsonInDetailsThrows(): void
    {
        // Test that decodeDetails() properly throws on invalid JSON
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid JSON');
        
        $this->decodeDetails('{"incomplete": true');
    }
}

