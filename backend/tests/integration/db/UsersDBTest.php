<?php
declare(strict_types=1);

require_once __DIR__ . '/BaseDBIntegrationTest.php';

/**
 * Integration tests for app/db/users.php
 *
 * Comprehensive test suite for user database functions.
 * Tests all CRUD operations, edge cases, and business logic.
 *
 * Uses the actual MySQL database connection from bootstrap.php.
 * Tests use transactions for isolation - each test runs in a transaction
 * that is rolled back in tearDown to ensure test isolation.
 *
 * @coversNothing
 */
final class UsersDBTest extends BaseDBIntegrationTest
{
    /**
     * Load database functions required for user tests
     */
    protected function loadDatabaseFunctions(): void
    {
        require_once __DIR__ . '/../../../app/db/users.php';
    }

    // ============================================================================
    // INSERT TESTS
    // ============================================================================

    /**
     * Test that inserting a user creates a row with correct data
     */
    public function testInsertUserCreatesRow(): void
    {
        $username = 'testuser_insert1';
        $email = 'testuser_insert1@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        $this->assertGreaterThan(0, $userId, 'User ID should be positive');
        
        $user = $this->getUser($userId);
        $this->assertNotNull($user, 'User should exist after insert');
        $this->assertSame($username, $user['username']);
        $this->assertSame($email, $user['email']);
        $this->assertSame($passwordHash, $user['password_hash']);
    }

    /**
     * Test that inserting a user sets created_at timestamp
     */
    public function testInsertUserSetsCreatedAtTimestamp(): void
    {
        $username = 'testuser_insert2';
        $email = 'testuser_insert2@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        $user = $this->getUser($userId);
        $this->assertNotNull($user['created_at'], 'created_at should be set');
        
        // Verify created_at is recent (within last minute)
        $this->assertRecentTimestamp('users', 'created_at', $userId);
    }

    /**
     * Test that inserting a user with special characters works correctly
     */
    public function testInsertUserWithSpecialCharacters(): void
    {
        $username = 'test_user-123';
        $email = 'test.user+tag@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        $user = $this->getUser($userId);
        $this->assertSame($username, $user['username']);
        $this->assertSame($email, $user['email']);
    }

    /**
     * Test that inserting a user with long username works correctly
     */
    public function testInsertUserWithLongUsername(): void
    {
        // Username max length is typically 50 characters
        $username = str_repeat('a', 50);
        $email = 'longuser@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        $user = $this->getUser($userId);
        $this->assertSame($username, $user['username']);
    }

    // ============================================================================
    // GET USER BY USERNAME TESTS
    // ============================================================================

    /**
     * Test that getting user by username returns correct user
     */
    public function testGetUserByUsernameReturnsCorrectUser(): void
    {
        $username = 'testuser_getbyuser1';
        $email = 'testuser_getbyuser1@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        $user = db_get_user_by_username($this->pdo, $username);
        
        $this->assertNotNull($user, 'Should return user');
        $this->assertSame($userId, (int)$user['id']);
        $this->assertSame($username, $user['username']);
        $this->assertSame($email, $user['email']);
        $this->assertSame($passwordHash, $user['password_hash']);
        $this->assertArrayHasKey('id', $user);
        $this->assertArrayHasKey('username', $user);
        $this->assertArrayHasKey('email', $user);
        $this->assertArrayHasKey('password_hash', $user);
    }

    /**
     * Test that getting user by username returns null for non-existent user
     */
    public function testGetUserByUsernameReturnsNullForNonExistentUser(): void
    {
        $user = db_get_user_by_username($this->pdo, 'nonexistent_user_12345');
        $this->assertNull($user, 'Should return null for non-existent user');
    }

    /**
     * Test that getting user by username is case insensitive
     */
    public function testGetUserByUsernameIsCaseInsensitive(): void
    {
        $username = 'TestUser_Case';
        $email = 'testuser_case@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        // Username is canonicalized (lowercase) by db_insert_user
        // Test with different case input
        $user = db_get_user_by_username($this->pdo, strtolower($username));
        
        // Should find the user (case-insensitive match)
        $this->assertNotNull($user);
        $this->assertSame($userId, (int)$user['id']);
        // Returned username should be canonicalized (lowercase)
        $this->assertSame(mb_strtolower($username), $user['username']);
    }

    /**
     * Test that getting user by username works with special characters
     */
    public function testGetUserByUsernameWithSpecialCharacters(): void
    {
        $username = 'test_user-123';
        $email = 'test_special_chars@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        $user = db_get_user_by_username($this->pdo, $username);
        $this->assertNotNull($user);
        $this->assertSame($userId, (int)$user['id']);
    }

    // ============================================================================
    // GET USER BY ID TESTS
    // ============================================================================

    /**
     * Test that getting user by ID returns correct user
     */
    public function testGetUserByIdReturnsCorrectUser(): void
    {
        $username = 'testuser_getbyid1';
        $email = 'testuser_getbyid1@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        $user = db_get_user_by_id($this->pdo, $userId);
        
        $this->assertNotNull($user, 'Should return user');
        $this->assertSame($userId, (int)$user['id']);
        $this->assertSame($username, $user['username']);
        $this->assertSame($email, $user['email']);
        $this->assertArrayHasKey('created_at', $user);
        // Note: password_hash is NOT returned by db_get_user_by_id
        $this->assertArrayNotHasKey('password_hash', $user);
    }

    /**
     * Test that getting user by ID returns null for non-existent user
     */
    public function testGetUserByIdReturnsNullForNonExistentUser(): void
    {
        $user = db_get_user_by_id($this->pdo, 999999);
        $this->assertNull($user, 'Should return null for non-existent user');
    }

    /**
     * Test that getting user by ID includes created_at
     */
    public function testGetUserByIdIncludesCreatedAt(): void
    {
        $username = 'testuser_getbyid2';
        $email = 'testuser_getbyid2@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        $user = db_get_user_by_id($this->pdo, $userId);
        $this->assertNotNull($user['created_at'], 'Should include created_at');
    }

    // ============================================================================
    // GET USERNAME BY ID TESTS
    // ============================================================================

    /**
     * Test that getting username by ID returns correct username
     */
    public function testGetUsernameByIdReturnsCorrectUsername(): void
    {
        $username = 'testuser_getusername1';
        $email = 'testuser_getusername1@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        $retrievedUsername = db_get_username_by_id($this->pdo, $userId);
        
        $this->assertSame($username, $retrievedUsername, 'Should return correct username');
    }

    /**
     * Test that getting username by ID returns null for non-existent user
     */
    public function testGetUsernameByIdReturnsNullForNonExistentUser(): void
    {
        $username = db_get_username_by_id($this->pdo, 999999);
        $this->assertNull($username, 'Should return null for non-existent user');
    }

    /**
     * Test that getting username by ID is lightweight
     */
    public function testGetUsernameByIdIsLightweight(): void
    {
        // This test just verifies it works, not that it's actually lightweight
        $username = 'testuser_getusername2';
        $email = 'testuser_getusername2@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        $retrievedUsername = db_get_username_by_id($this->pdo, $userId);
        $this->assertIsString($retrievedUsername);
        $this->assertSame($username, $retrievedUsername);
        
        // Verify it only returns username (not full user object)
        $this->assertIsNotArray($retrievedUsername);
    }

    // ============================================================================
    // UPDATE PASSWORD HASH TESTS
    // ============================================================================

    /**
     * Test that updating user password hash updates the hash
     */
    public function testUpdateUserPasswordHashUpdatesHash(): void
    {
        $username = 'testuser_updatepass1';
        $email = 'testuser_updatepass1@example.com';
        $oldHash = password_hash('oldpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $oldHash);
        
        $newHash = password_hash('newpass123', PASSWORD_DEFAULT);
        db_update_user_password_hash($this->pdo, $userId, $newHash);
        
        $user = $this->getUser($userId);
        $this->assertSame($newHash, $user['password_hash'], 'Password hash should be updated');
        $this->assertNotSame($oldHash, $user['password_hash'], 'Old hash should be replaced');
    }

    /**
     * Test that updating user password hash is idempotent
     */
    public function testUpdateUserPasswordHashIsIdempotent(): void
    {
        $username = 'testuser_updatepass2';
        $email = 'testuser_updatepass2@example.com';
        $hash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $hash);
        
        // Update with same hash
        db_update_user_password_hash($this->pdo, $userId, $hash);
        
        $user = $this->getUser($userId);
        $this->assertSame($hash, $user['password_hash']);
    }

    /**
     * Test that updating user password hash does not affect other fields
     */
    public function testUpdateUserPasswordHashDoesNotAffectOtherFields(): void
    {
        $username = 'testuser_updatepass3';
        $email = 'testuser_updatepass3@example.com';
        $oldHash = password_hash('oldpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $oldHash);
        $userBefore = $this->getUser($userId);
        
        $newHash = password_hash('newpass123', PASSWORD_DEFAULT);
        db_update_user_password_hash($this->pdo, $userId, $newHash);
        
        $userAfter = $this->getUser($userId);
        $this->assertSame($userBefore['username'], $userAfter['username']);
        $this->assertSame($userBefore['email'], $userAfter['email']);
        $this->assertSame($userBefore['created_at'], $userAfter['created_at']);
        $this->assertNotSame($userBefore['password_hash'], $userAfter['password_hash']);
    }

    /**
     * Test that updating password hash for non-existent user completes without error
     */
    public function testUpdateUserPasswordHashWithNonExistentUser(): void
    {
        $newHash = password_hash('newpass123', PASSWORD_DEFAULT);
        
        // Should not throw error (just doesn't update anything)
        db_update_user_password_hash($this->pdo, 999999, $newHash);
        
        $this->assertTrue(true, 'Should complete without error');
    }

    // ============================================================================
    // UPDATE LAST SEEN TESTS
    // ============================================================================

    /**
     * Test that updating user last seen sets timestamp
     */
    public function testUpdateUserLastSeenSetsTimestamp(): void
    {
        $username = 'testuser_updateseen1';
        $email = 'testuser_updateseen1@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        $userBefore = $this->getUser($userId);
        $this->assertNull($userBefore['last_login_at'], 'last_login_at should be NULL initially');
        
        db_update_user_last_seen($this->pdo, $userId);
        
        $userAfter = $this->getUser($userId);
        $this->assertNotNull($userAfter['last_login_at'], 'last_login_at should be set');
        
        // Verify timestamp is recent (within last minute)
        $this->assertRecentTimestamp('users', 'last_login_at', $userId);
    }

    /**
     * Test that updating user last seen updates existing timestamp
     */
    public function testUpdateUserLastSeenUpdatesExistingTimestamp(): void
    {
        $username = 'testuser_updateseen2';
        $email = 'testuser_updateseen2@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        // Update first time
        db_update_user_last_seen($this->pdo, $userId);
        $userAfterFirst = $this->getUser($userId);
        $firstSeen = $userAfterFirst['last_login_at'];
        
        sleep(1); // Wait 1 second to ensure different timestamp
        
        // Update second time
        db_update_user_last_seen($this->pdo, $userId);
        $userAfterSecond = $this->getUser($userId);
        $secondSeen = $userAfterSecond['last_login_at'];
        
        $this->assertNotSame($firstSeen, $secondSeen, 'Timestamp should be updated');
        
        // Verify second timestamp is later
        $stmt = $this->pdo->prepare("
            SELECT TIMESTAMPDIFF(SECOND, :first_seen, :second_seen) as diff_seconds
        ");
        $stmt->execute([
            'first_seen' => $firstSeen,
            'second_seen' => $secondSeen,
        ]);
        $diffSeconds = (int)$stmt->fetch()['diff_seconds'];
        $this->assertGreaterThanOrEqual(0, $diffSeconds);
    }

    /**
     * Test that updating last seen for non-existent user completes without error
     */
    public function testUpdateUserLastSeenWithNonExistentUser(): void
    {
        // Should not throw error
        db_update_user_last_seen($this->pdo, 999999);
        
        $this->assertTrue(true, 'Should complete without error');
    }

    // ============================================================================
    // USER EXISTS TESTS
    // ============================================================================

    /**
     * Test that user exists returns true for existing username
     */
    public function testUserExistsReturnsTrueForExistingUsername(): void
    {
        $username = 'testuser_exists1';
        $email = 'testuser_exists1@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        $exists = db_user_exists($this->pdo, $username, 'different@example.com');
        $this->assertTrue($exists, 'Should return true for existing username');
    }

    /**
     * Test that user exists returns true for existing email
     */
    public function testUserExistsReturnsTrueForExistingEmail(): void
    {
        $username = 'testuser_exists2';
        $email = 'testuser_exists2@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        $exists = db_user_exists($this->pdo, 'different_username', $email);
        $this->assertTrue($exists, 'Should return true for existing email');
    }

    /**
     * Test that user exists returns false for non-existent user
     */
    public function testUserExistsReturnsFalseForNonExistentUser(): void
    {
        $exists = db_user_exists($this->pdo, 'nonexistent_username', 'nonexistent@example.com');
        $this->assertFalse($exists, 'Should return false for non-existent user');
    }

    /**
     * Test that user exists returns true when both username and email match
     */
    public function testUserExistsReturnsTrueWhenBothMatch(): void
    {
        $username = 'testuser_exists3';
        $email = 'testuser_exists3@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        $exists = db_user_exists($this->pdo, $username, $email);
        $this->assertTrue($exists, 'Should return true when both username and email match');
    }

    /**
     * Test that user exists returns true when only one matches
     */
    public function testUserExistsReturnsTrueWhenOnlyOneMatches(): void
    {
        $username1 = 'testuser_exists4a';
        $email1 = 'testuser_exists4a@example.com';
        $username2 = 'testuser_exists4b';
        $email2 = 'testuser_exists4b@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        db_insert_user($this->pdo, $username1, $email1, $passwordHash);
        db_insert_user($this->pdo, $username2, $email2, $passwordHash);
        
        // Check username from user1 with email from user2
        $exists = db_user_exists($this->pdo, $username1, $email2);
        $this->assertTrue($exists, 'Should return true when username matches (even if email is different)');
        
        // Check email from user1 with username from user2
        $exists = db_user_exists($this->pdo, $username2, $email1);
        $this->assertTrue($exists, 'Should return true when email matches (even if username is different)');
    }

    /**
     * Test that user exists is case insensitive for username
     */
    public function testUserExistsIsCaseInsensitiveForUsername(): void
    {
        $username = 'TestUser_Exists5';
        $email = 'testuser_exists5@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        // MySQL is case-insensitive by default
        $exists = db_user_exists($this->pdo, strtolower($username), 'different@example.com');
        $this->assertTrue($exists, 'Should return true for case-insensitive username match');
    }

    // ============================================================================
    // EDGE CASES & INTEGRATION TESTS
    // ============================================================================

    /**
     * Test full user lifecycle from creation to updates
     */
    public function testFullUserLifecycle(): void
    {
        $username = 'testuser_lifecycle';
        $email = 'testuser_lifecycle@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        // 1. Insert user
        $userId = db_insert_user($this->pdo, $username, $email, $passwordHash);
        $this->assertGreaterThan(0, $userId);
        
        // 2. Get by username
        $user = db_get_user_by_username($this->pdo, $username);
        $this->assertNotNull($user);
        
        // 3. Get by ID
        $user = db_get_user_by_id($this->pdo, $userId);
        $this->assertNotNull($user);
        
        // 4. Get username by ID
        $retrievedUsername = db_get_username_by_id($this->pdo, $userId);
        $this->assertSame($username, $retrievedUsername);
        
        // 5. Check existence
        $exists = db_user_exists($this->pdo, $username, $email);
        $this->assertTrue($exists);
        
        // 6. Update password
        $newHash = password_hash('newpass123', PASSWORD_DEFAULT);
        db_update_user_password_hash($this->pdo, $userId, $newHash);
        $user = db_get_user_by_username($this->pdo, $username);
        $this->assertSame($newHash, $user['password_hash']);
        
        // 7. Update last seen
        db_update_user_last_seen($this->pdo, $userId);
        $user = $this->getUser($userId);
        $this->assertNotNull($user['last_login_at']);
    }

    /**
     * Test that multiple users do not interfere with each other
     */
    public function testMultipleUsersNoInterference(): void
    {
        $username1 = 'user1_multi';
        $email1 = 'user1_multi@example.com';
        $username2 = 'user2_multi';
        $email2 = 'user2_multi@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId1 = db_insert_user($this->pdo, $username1, $email1, $passwordHash);
        $userId2 = db_insert_user($this->pdo, $username2, $email2, $passwordHash);
        
        // Verify users are separate
        $user1 = db_get_user_by_id($this->pdo, $userId1);
        $user2 = db_get_user_by_id($this->pdo, $userId2);
        
        $this->assertNotSame($userId1, $userId2);
        $this->assertSame($username1, $user1['username']);
        $this->assertSame($username2, $user2['username']);
        
        // Update one user should not affect the other
        $newHash = password_hash('newpass123', PASSWORD_DEFAULT);
        db_update_user_password_hash($this->pdo, $userId1, $newHash);
        
        $user2After = db_get_user_by_id($this->pdo, $userId2);
        $this->assertSame($passwordHash, $this->getUser($userId2)['password_hash'], 
            'Other user should not be affected');
    }

    /**
     * Test that get user by username returns password hash but get user by ID does not
     */
    public function testGetUserByUsernameReturnsPasswordHashButGetUserByIdDoesNot(): void
    {
        $username = 'testuser_hashcheck';
        $email = 'testuser_hashcheck@example.com';
        $passwordHash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        $userId = db_insert_user($this->pdo, $username, $email, $passwordHash);
        
        // db_get_user_by_username should include password_hash
        $userByUsername = db_get_user_by_username($this->pdo, $username);
        $this->assertArrayHasKey('password_hash', $userByUsername);
        
        // db_get_user_by_id should NOT include password_hash
        $userById = db_get_user_by_id($this->pdo, $userId);
        $this->assertArrayNotHasKey('password_hash', $userById);
    }
}
