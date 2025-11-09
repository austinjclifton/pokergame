<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Base class for database integration tests
 * 
 * Provides common setup, teardown, and helper methods for all database
 * integration tests. Ensures consistent transaction isolation and test
 * data management across all DB test suites.
 * 
 * All database integration tests should extend this class.
 */
abstract class BaseDBIntegrationTest extends TestCase
{
    protected PDO $pdo;
    protected bool $inTransaction = false;

    /**
     * Set up database connection and test environment
     * 
     * Establishes PDO connection, disables foreign key checks, and starts
     * a transaction for test isolation. Each test runs in its own transaction
     * that is rolled back in tearDown().
     */
    protected function setUp(): void
    {
        // Access the global PDO connection (from bootstrap.php -> config/db.php)
        global $pdo;
        
        // If not available via global, try $GLOBALS array
        if (!isset($pdo) && isset($GLOBALS['pdo'])) {
            $pdo = $GLOBALS['pdo'];
        }
        
        // If still not available, create connection directly (fallback)
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

        // Load required database functions (implemented by subclasses)
        $this->loadDatabaseFunctions();

        // Disable foreign key checks for tests
        // This is safe because we rollback the transaction after each test
        $this->pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        
        // Start a transaction for test isolation
        // Each test will run in its own transaction and rollback in tearDown
        // This ensures test data doesn't persist between tests
        $this->pdo->beginTransaction();
        $this->inTransaction = true;
    }

    /**
     * Clean up test transaction and reset state
     * 
     * Rolls back the transaction to ensure test data doesn't persist
     * between tests. This ensures each test starts with a clean state.
     */
    protected function tearDown(): void
    {
        // Rollback transaction to clean up test data
        // This ensures each test starts with a clean state
        if ($this->inTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
            $this->inTransaction = false;
        }
    }

    /**
     * Load database functions required for tests
     * 
     * Subclasses should override this method to require_once the appropriate
     * database function files (e.g., app/db/users.php, app/db/sessions.php).
     */
    abstract protected function loadDatabaseFunctions(): void;

    // ============================================================================
    // COMMON HELPER METHODS
    // ============================================================================

    /**
     * Create a test user and return user ID
     * 
     * @param string $username Username for the test user
     * @param string|null $email Optional email (auto-generated if null)
     * @return int User ID
     */
    protected function createTestUser(string $username, ?string $email = null): int
    {
        $email = $email ?? ($username . '_' . time() . '@test.com');
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO users (username, email, password_hash)
            VALUES (:username, :email, :password_hash)
        ");
        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);
        
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Create a test session and return session ID
     * 
     * @param int $userId User ID to create session for
     * @param string|null $expiresAt Optional expiration time (default: +30 days)
     * @param string $ipHash IP hash (default: test-ip-hash)
     * @param string $userAgent User agent string (default: PHPUnit Test)
     * @return int Session ID
     */
    protected function createTestSession(
        int $userId,
        ?string $expiresAt = null,
        string $ipHash = 'test-ip-hash',
        string $userAgent = 'PHPUnit Test'
    ): int {
        if ($expiresAt === null) {
            // Default: expire in 30 days
            $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        }
        
        require_once __DIR__ . '/../../../app/db/sessions.php';
        return db_insert_session($this->pdo, $userId, $ipHash, $userAgent, $expiresAt);
    }

    /**
     * Get user from database by ID
     * 
     * @param int $userId User ID
     * @return array|null User record or null if not found
     */
    protected function getUser(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get session from database by ID
     * 
     * @param int $sessionId Session ID
     * @return array|null Session record or null if not found
     */
    protected function getSession(int $sessionId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM sessions WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $sessionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get challenge from database by ID
     * 
     * @param int $challengeId Challenge ID
     * @return array|null Challenge record or null if not found
     */
    protected function getChallenge(int $challengeId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM game_challenges WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $challengeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get presence record from database by user ID
     * 
     * @param int $userId User ID
     * @return array|null Presence record or null if not found
     */
    protected function getPresence(int $userId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM user_lobby_presence WHERE user_id = :uid LIMIT 1
        ");
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get subscription from database by connection ID
     * 
     * @param string $connectionId Connection ID
     * @return array|null Subscription record or null if not found
     */
    protected function getSubscription(string $connectionId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM ws_subscriptions WHERE connection_id = :conn_id LIMIT 1
        ");
        $stmt->execute(['conn_id' => $connectionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ============================================================================
    // TIMESTAMP ASSERTION HELPERS
    // ============================================================================

    /**
     * Assert that a timestamp column is recent (within tolerance)
     * 
     * Verifies that a timestamp in the database is within the specified
     * number of seconds from the current time. Useful for testing that
     * created_at, updated_at, etc. are set correctly.
     * 
     * Note: Table and column names are validated against a whitelist to prevent SQL injection.
     * Only alphanumeric characters, underscores, and hyphens are allowed.
     * 
     * @param string $table Table name (must be alphanumeric with underscores/hyphens only)
     * @param string $column Timestamp column name (must be alphanumeric with underscores/hyphens only)
     * @param int|string $idValue ID value for WHERE clause
     * @param string $idColumn ID column name (default: 'id', must be alphanumeric with underscores/hyphens only)
     * @param int $toleranceSeconds Maximum seconds ago the timestamp can be (default: 60)
     * @param string $message Optional assertion message
     */
    protected function assertRecentTimestamp(
        string $table,
        string $column,
        $idValue,
        string $idColumn = 'id',
        int $toleranceSeconds = 60,
        string $message = ''
    ): void {
        // Validate table and column names to prevent SQL injection
        // Only allow alphanumeric, underscores, and hyphens
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $table)) {
            throw new InvalidArgumentException("Invalid table name: {$table}");
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $column)) {
            throw new InvalidArgumentException("Invalid column name: {$column}");
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $idColumn)) {
            throw new InvalidArgumentException("Invalid id column name: {$idColumn}");
        }
        
        $stmt = $this->pdo->prepare("
            SELECT TIMESTAMPDIFF(SECOND, `{$column}`, NOW()) as seconds_ago
            FROM `{$table}`
            WHERE `{$idColumn}` = :id
        ");
        $stmt->execute(['id' => $idValue]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($result, $message ?: "Timestamp should exist for {$table}.{$idColumn} = {$idValue}");
        
        $secondsAgo = (int)$result['seconds_ago'];
        $this->assertGreaterThanOrEqual(0, $secondsAgo, 
            $message ?: "Timestamp should not be in the future (got {$secondsAgo} seconds ago)");
        $this->assertLessThanOrEqual($toleranceSeconds, $secondsAgo, 
            $message ?: "Timestamp should be recent (got {$secondsAgo} seconds ago, max {$toleranceSeconds})");
    }

    /**
     * Assert that a timestamp column value is recent (within tolerance)
     * 
     * Verifies that a provided timestamp string is within the specified
     * number of seconds from the current time.
     * 
     * @param string $timestamp Timestamp string (e.g., '2024-01-01 12:00:00')
     * @param int $toleranceSeconds Maximum seconds ago the timestamp can be (default: 60)
     * @param string $message Optional assertion message
     */
    protected function assertTimestampIsRecent(
        string $timestamp,
        int $toleranceSeconds = 60,
        string $message = ''
    ): void {
        $stmt = $this->pdo->prepare("
            SELECT TIMESTAMPDIFF(SECOND, :timestamp, NOW()) as seconds_ago
        ");
        $stmt->execute(['timestamp' => $timestamp]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->assertNotFalse($result, $message ?: "Should be able to compare timestamp");
        
        $secondsAgo = (int)$result['seconds_ago'];
        $this->assertGreaterThanOrEqual(0, $secondsAgo, 
            $message ?: "Timestamp should not be in the future (got {$secondsAgo} seconds ago)");
        $this->assertLessThanOrEqual($toleranceSeconds, $secondsAgo, 
            $message ?: "Timestamp should be recent (got {$secondsAgo} seconds ago, max {$toleranceSeconds})");
    }
}

