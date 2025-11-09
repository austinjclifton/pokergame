<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers password hashing & verification logic.
 *
 * Tests the password hashing contract used by AuthService.
 * AuthService uses PHP's built-in password_hash() and password_verify() directly
 * with PASSWORD_DEFAULT (bcrypt in PHP 8+). This test suite validates:
 *  - same password verifies true
 *  - wrong password verifies false
 *  - hashes are non-reversible and unique (salt)
 *  - edge cases and security properties
 *
 * @coversNothing
 */
final class AuthServicePasswordsTest extends TestCase
{
    /**
     * Hash a password using the same method as AuthService.
     * Uses PASSWORD_DEFAULT (bcrypt) to match auth_register_user() and auth_login_user().
     */
    private function appHash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    /**
     * Verify a password using the same method as AuthService.
     * Uses password_verify() to match auth_login_user().
     */
    private function appVerify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public function testHashAndVerifyRoundTrip(): void
    {
        $pwd = 'CorrectHorseBatteryStaple!42';
        $hash = $this->appHash($pwd);

        $this->assertNotSame($pwd, $hash, 'Hash must not equal the plaintext.');
        $this->assertTrue($this->appVerify($pwd, $hash), 'Correct password should verify.');
    }

    public function testWrongPasswordDoesNotVerify(): void
    {
        $pwd = 'secret-123';
        $hash = $this->appHash($pwd);

        $this->assertFalse($this->appVerify('secret-124', $hash), 'Wrong password must fail verification.');
    }

    public function testHashesAreSaltedAndUnique(): void
    {
        $pwd = 'repeatable-password';
        $h1  = $this->appHash($pwd);
        $h2  = $this->appHash($pwd);

        $this->assertNotSame($h1, $h2, 'Repeated hashing should produce different salted hashes.');
        $this->assertTrue($this->appVerify($pwd, $h1));
        $this->assertTrue($this->appVerify($pwd, $h2));
    }

    public function testPasswordNeedsRehashPolicy(): void
    {
        // Tests the rehashing logic used in auth_login_user().
        // AuthService checks password_needs_rehash() and rehashes if needed.
        $pwd  = 'PolicyCheck#2025';
        $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 10]);

        $policyOptions = ['cost' => 12];
        $needsRehash   = password_needs_rehash($hash, PASSWORD_BCRYPT, $policyOptions);

        $this->assertTrue($needsRehash, 'Hash should need rehash when policy (cost) increases.');
    }

    public function testEmptyPassword(): void
    {
        // Empty password should hash and verify correctly
        $pwd = '';
        $hash = $this->appHash($pwd);

        $this->assertNotEmpty($hash, 'Empty password should produce a hash.');
        $this->assertTrue($this->appVerify($pwd, $hash), 'Empty password should verify against its hash.');
        $this->assertFalse($this->appVerify(' ', $hash), 'Single space should not verify as empty password.');
    }

    public function testVeryLongPassword(): void
    {
        // Test with very long password (e.g., 1000 characters)
        // Note: bcrypt truncates passwords at 72 bytes, so very long passwords
        // beyond 72 characters may have the same hash if they start identically
        $pwd = str_repeat('a', 1000);
        $hash = $this->appHash($pwd);

        $this->assertNotEmpty($hash, 'Very long password should produce a hash.');
        $this->assertTrue($this->appVerify($pwd, $hash), 'Very long password should verify correctly.');
        
        // Test with a password that differs in the first 72 bytes (bcrypt truncation point)
        $differentPwd = str_repeat('b', 1000); // Different character
        $this->assertFalse($this->appVerify($differentPwd, $hash),
            'Different very long password should not verify.');
    }

    public function testPasswordWithSpecialCharacters(): void
    {
        // Test passwords with various special characters
        $passwords = [
            '!@#$%^&*()',
            'password with spaces',
            'p@ssw0rd!',
            'Test123#Password',
            'password"with"quotes',
            'password\'with\'apostrophes',
            'password<with>tags',
            'password{with}braces',
            'password[with]brackets',
        ];

        foreach ($passwords as $pwd) {
            $hash = $this->appHash($pwd);
            $this->assertTrue($this->appVerify($pwd, $hash), 
                "Password with special chars should verify: " . addcslashes($pwd, "\0..\37!@\177..\377"));
        }
    }

    public function testPasswordWithUnicodeCharacters(): void
    {
        // Test passwords with Unicode/emoji characters
        $passwords = [
            'password日本語',
            'пароль',
            '🔐secure🔒',
            'café résumé',
            'müßig',
            '密码123',
        ];

        foreach ($passwords as $pwd) {
            $hash = $this->appHash($pwd);
            $this->assertTrue($this->appVerify($pwd, $hash), 
                "Unicode password should verify: " . $pwd);
            $this->assertFalse($this->appVerify($pwd . 'x', $hash),
                "Modified unicode password should not verify.");
        }
    }

    public function testPasswordWithControlCharacters(): void
    {
        // Test passwords with control characters (newline, tab, etc.)
        // Note: bcrypt does not support null bytes (\x00) in passwords
        $passwords = [
            "password\nnewline",
            "password\ttab",
            "password\rcarriage",
            "password\x01\x02", // Non-null control chars
        ];

        foreach ($passwords as $pwd) {
            $hash = $this->appHash($pwd);
            $this->assertTrue($this->appVerify($pwd, $hash),
                "Password with control characters (non-null) should verify.");
        }

        // Test that null bytes are properly rejected by bcrypt
        $this->expectException(ValueError::class);
        $this->appHash("password\0null");
    }

    public function testCaseSensitivePasswords(): void
    {
        $pwd = 'Password123';
        $hash = $this->appHash($pwd);

        // Case-sensitive verification
        $this->assertTrue($this->appVerify('Password123', $hash), 'Exact case should verify.');
        $this->assertFalse($this->appVerify('password123', $hash), 'Lowercase should not verify.');
        $this->assertFalse($this->appVerify('PASSWORD123', $hash), 'Uppercase should not verify.');
        $this->assertFalse($this->appVerify('PassWord123', $hash), 'Mixed case variation should not verify.');
    }

    public function testSimilarButDifferentPasswords(): void
    {
        $pwd = 'password123';
        $hash = $this->appHash($pwd);

        // Similar but different passwords should not verify
        $similar = [
            'password124',  // One digit different
            'password12',   // Missing character
            'password1234', // Extra character
            'passwor123',   // Missing character in middle
            'passworrd123', // Extra character
            'Password123',  // Different case
            'password321',  // Reversed digits
        ];

        foreach ($similar as $similarPwd) {
            $this->assertFalse($this->appVerify($similarPwd, $hash),
                "Similar password should not verify: {$similarPwd}");
        }
    }

    public function testCompletelyWrongPasswords(): void
    {
        $pwd = 'MySecretPassword123!';
        $hash = $this->appHash($pwd);

        $wrong = [
            'CompletelyDifferent',
            'AnotherPassword',
            '12345',
            'admin',
            'password',
            'qwerty',
        ];

        foreach ($wrong as $wrongPwd) {
            $this->assertFalse($this->appVerify($wrongPwd, $hash),
                "Wrong password should not verify: {$wrongPwd}");
        }
    }

    public function testHashFormatAndLength(): void
    {
        $pwd = 'TestPassword123';
        $hash = $this->appHash($pwd);

        // PASSWORD_DEFAULT uses bcrypt (PHP 8+), which starts with $2y$
        $this->assertStringStartsWith('$2y$', $hash,
            'Hash should be bcrypt format (starts with $2y$).');

        // Bcrypt hashes are always 60 characters
        $this->assertSame(60, strlen($hash),
            'Bcrypt hash should be exactly 60 characters.');

        // Format: $2y$cost$salt+hash (22 char salt, 31 char hash = 60 total)
        $this->assertMatchesRegularExpression(
            '/^\$2y\$[0-9]{2}\$[A-Za-z0-9.\/]{53}$/',
            $hash,
            'Hash should match bcrypt format pattern.'
        );
    }

    public function testHashIsNotReversible(): void
    {
        // Verify that hash cannot be used to derive password
        $pwd = 'OriginalPassword123';
        $hash = $this->appHash($pwd);

        // Hash should not contain password in any form
        $this->assertStringNotContainsStringIgnoringCase($pwd, $hash,
            'Hash should not contain the original password.');

        // Even part of password should not be in hash
        $pwdParts = str_split($pwd, 3);
        foreach ($pwdParts as $part) {
            if (strlen($part) >= 3) {
                $this->assertStringNotContainsString($part, $hash,
                    "Hash should not contain password fragment: {$part}");
            }
        }
    }

    public function testMultipleHashesForSamePassword(): void
    {
        // Generate many hashes for the same password and verify they're all different
        $pwd = 'SamePassword123';
        $hashes = [];

        for ($i = 0; $i < 100; $i++) {
            $hash = $this->appHash($pwd);
            $hashes[] = $hash;
            
            // Each hash should verify the password
            $this->assertTrue($this->appVerify($pwd, $hash),
                "Hash #{$i} should verify the password.");
        }

        // All hashes should be unique (due to salt)
        $uniqueHashes = array_unique($hashes);
        $this->assertCount(100, $uniqueHashes,
            'All 100 hashes for the same password should be unique (salted).');
    }

    public function testHashDoesNotNeedRehashWithSamePolicy(): void
    {
        $pwd = 'TestPassword456';
        $hash = $this->appHash($pwd);

        // Hash created with PASSWORD_DEFAULT should not need rehash with PASSWORD_DEFAULT
        $needsRehash = password_needs_rehash($hash, PASSWORD_DEFAULT);
        $this->assertFalse($needsRehash,
            'Hash created with PASSWORD_DEFAULT should not need rehash with same algorithm.');
    }

    public function testHashNeedsRehashWithDifferentAlgorithm(): void
    {
        $pwd = 'TestPassword789';
        $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 10]);

        // Should need rehash if algorithm changes (though PASSWORD_DEFAULT is usually bcrypt)
        // This tests the concept that old hashes can be detected for rehashing
        $needsRehash = password_needs_rehash($hash, PASSWORD_ARGON2ID);
        $this->assertTrue($needsRehash,
            'Hash should need rehash when algorithm changes.');
    }

    public function testHashNeedsRehashWithLowerCost(): void
    {
        // Note: password_needs_rehash returns true if the hash doesn't match the current policy,
        // even if the hash has higher cost than required. This is intentional - you might want
        // to standardize all hashes to a consistent cost.
        $pwd = 'TestPasswordCost';
        $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);

        $needsRehash = password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 10]);
        // PHP's password_needs_rehash returns true when cost differs, even if higher
        // This is by design - it allows you to normalize all hashes to a standard cost
        $this->assertTrue($needsRehash,
            'Hash with different cost should need rehash to match policy (cost normalization).');
        
        // But hash with matching cost should not need rehash
        $needsRehashSame = password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->assertFalse($needsRehashSame,
            'Hash with matching cost should not need rehash.');
    }

    public function testVerifyWithInvalidHashFormat(): void
    {
        $pwd = 'TestPassword';

        // Invalid hash formats should not verify
        $invalidHashes = [
            'not-a-hash',
            'short',
            '$2y$', // Incomplete
            '$2x$10$invalid', // Wrong version
            '', // Empty
            'plaintext', // Not a hash
        ];

        foreach ($invalidHashes as $invalidHash) {
            // password_verify should return false for invalid hashes (not throw)
            $result = @$this->appVerify($pwd, $invalidHash);
            $this->assertFalse($result,
                "Invalid hash format should not verify: " . substr($invalidHash, 0, 20));
        }
    }

    public function testVerifyWithHashFromDifferentPassword(): void
    {
        $pwd1 = 'PasswordOne';
        $pwd2 = 'PasswordTwo';
        
        $hash1 = $this->appHash($pwd1);
        $hash2 = $this->appHash($pwd2);

        // Each password should only verify with its own hash
        $this->assertTrue($this->appVerify($pwd1, $hash1));
        $this->assertTrue($this->appVerify($pwd2, $hash2));
        $this->assertFalse($this->appVerify($pwd1, $hash2));
        $this->assertFalse($this->appVerify($pwd2, $hash1));
    }

    public function testPasswordWithLeadingAndTrailingWhitespace(): void
    {
        $pwd = 'password123';
        $hash = $this->appHash($pwd);

        // Whitespace is part of the password - should be case sensitive
        $this->assertFalse($this->appVerify(' password123', $hash),
            'Leading space should not verify.');
        $this->assertFalse($this->appVerify('password123 ', $hash),
            'Trailing space should not verify.');
        $this->assertFalse($this->appVerify(' password123 ', $hash),
            'Both leading and trailing spaces should not verify.');

        // But if password itself has whitespace, that should work
        $pwdWithSpaces = ' password123 ';
        $hashWithSpaces = $this->appHash($pwdWithSpaces);
        $this->assertTrue($this->appVerify($pwdWithSpaces, $hashWithSpaces),
            'Password with intentional spaces should verify.');
    }

    public function testHashConsistencyAcrossMultipleVerifications(): void
    {
        $pwd = 'ConsistentPassword';
        $hash = $this->appHash($pwd);

        // Same password/hash combination should verify consistently
        for ($i = 0; $i < 100; $i++) {
            $this->assertTrue($this->appVerify($pwd, $hash),
                "Verification #{$i} should consistently return true.");
        }
    }

    public function testCommonWeakPasswords(): void
    {
        // Test that common weak passwords still hash/verify correctly
        // (Security policy should prevent these, but hashing should work)
        $weakPasswords = [
            '123456',
            'password',
            '12345678',
            'qwerty',
            'abc123',
            'password123',
            'admin',
            'letmein',
        ];

        foreach ($weakPasswords as $pwd) {
            $hash = $this->appHash($pwd);
            $this->assertTrue($this->appVerify($pwd, $hash),
                "Weak password '{$pwd}' should still hash and verify correctly.");
        }
    }

    public function testBinaryDataAsPassword(): void
    {
        // Test with binary data (excluding null bytes - bcrypt limitation)
        // Note: bcrypt does not support null bytes (\x00) in passwords
        $binaryPwd = "\x01\x02\x03\xFF\xFE\xFD"; // No null bytes
        $hash = $this->appHash($binaryPwd);

        $this->assertNotEmpty($hash, 'Binary password (non-null) should produce a hash.');
        $this->assertTrue($this->appVerify($binaryPwd, $hash),
            'Binary password should verify correctly.');
    }

    public function testNullByteInPassword(): void
    {
        // Bcrypt does not support null bytes in passwords - this is a limitation
        // This test verifies that bcrypt properly rejects null bytes
        $this->expectException(ValueError::class);
        $this->appHash("password\0null");
    }

    public function testBcrypt72ByteLimit(): void
    {
        // Bcrypt truncates passwords at 72 bytes
        // Test that passwords longer than 72 bytes still work, but may have collisions
        // if they share the first 72 bytes
        $pwd72 = str_repeat('a', 72);
        $pwd73 = str_repeat('a', 73);
        $pwd100 = str_repeat('a', 100);
        
        $hash72 = $this->appHash($pwd72);
        $hash73 = $this->appHash($pwd73);
        $hash100 = $this->appHash($pwd100);
        
        // Each should verify with its own hash
        $this->assertTrue($this->appVerify($pwd72, $hash72));
        $this->assertTrue($this->appVerify($pwd73, $hash73));
        $this->assertTrue($this->appVerify($pwd100, $hash100));
        
        // Due to bcrypt's 72-byte limit, pwd73 and pwd100 might verify with pwd72's hash
        // if they share the first 72 bytes (which they do - all 'a's)
        // This is expected bcrypt behavior
        $this->assertTrue($this->appVerify($pwd73, $hash72),
            'Password longer than 72 bytes may verify with hash of first 72 bytes (bcrypt limitation).');
    }

    public function testHashVerificationWithWrongCaseInHash(): void
    {
        $pwd = 'TestPassword';
        $hash = $this->appHash($pwd);

        // Bcrypt format is case-sensitive - modifying hash should fail
        $modifiedHash = str_replace('$2y$', '$2Y$', $hash); // Wrong case
        $result = @$this->appVerify($pwd, $modifiedHash);
        $this->assertFalse($result,
            'Modified hash (wrong case) should not verify.');
    }

    public function testPasswordLengthBoundaries(): void
    {
        // Test various password lengths
        $lengths = [0, 1, 10, 50, 72, 100, 255, 1000];

        foreach ($lengths as $length) {
            $pwd = str_repeat('a', $length);
            $hash = $this->appHash($pwd);
            
            $this->assertNotEmpty($hash, "Password of length {$length} should produce a hash.");
            $this->assertTrue($this->appVerify($pwd, $hash),
                "Password of length {$length} should verify correctly.");
        }
    }
}
