<?php
/**
 * backend/lib/security.php
 * -----------------------------------------------------------------------------
 * Security utility functions for XSS prevention and input validation.
 * Provides consistent escaping and validation across the application.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

/**
 * Check if debug mode is enabled.
 * Debug mode can be enabled via:
 * - Query parameter: ?debug=1 or ?debug=true
 * - HTTP header: X-Debug: true or X-Debug: 1
 * - Environment variable: APP_DEBUG=1
 * 
 * @return bool True if debug mode is enabled
 */
function debug_enabled(): bool {
    // Check query parameter
    if (isset($_GET['debug']) && ($_GET['debug'] === '1' || strtolower((string)$_GET['debug']) === 'true')) {
        return true;
    }
    
    // Check HTTP header
    $hdr = $_SERVER['HTTP_X_DEBUG'] ?? '';
    if ($hdr === '1' || strtolower($hdr) === 'true') {
        return true;
    }
    
    // Check environment variable
    if ((getenv('APP_DEBUG') ?: '') === '1') {
        return true;
    }
    
    return false;
}

/**
 * Escape HTML special characters to prevent XSS attacks.
 * 
 * This function should be used for all user-generated content that will be
 * displayed in HTML context (including JSON responses that will be rendered
 * by React/frontend).
 * 
 * @param string $text The text to escape
 * @return string Escaped text safe for HTML display
 */
function escape_html(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * Normalize whitespace characters.
 * Removes zero-width spaces and normalizes all whitespace.
 * 
 * @param string $text The text to normalize
 * @return string Normalized text
 */
function normalize_whitespace(string $text): string {
    // Remove zero-width spaces and other invisible characters
    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);
    
    // Normalize whitespace (convert all whitespace to regular spaces)
    $text = preg_replace('/\s+/u', ' ', $text);
    
    // Trim leading/trailing whitespace
    return trim($text);
}

/**
 * Canonicalize username to a standard form.
 * - Converts to lowercase
 * - Normalizes whitespace
 * 
 * Usernames are restricted to ASCII characters, so Unicode normalization
 * is not needed (homograph attacks are prevented by validate_username).
 * 
 * @param string $username The username to canonicalize
 * @return string Canonicalized username
 */
function canonicalize_username(string $username): string {
    // Normalize whitespace first (removes zero-width spaces, etc.)
    $username = normalize_whitespace($username);
    
    // Convert to lowercase for case-insensitive comparison
    $username = mb_strtolower($username, 'UTF-8');
    
    return $username;
}

/**
 * Canonicalize email address to a standard form.
 * - Converts domain to lowercase (RFC 5321 requirement)
 * - Converts local part to lowercase (best practice)
 * - Normalizes whitespace
 * 
 * @param string $email The email to canonicalize
 * @return string Canonicalized email
 */
function canonicalize_email(string $email): string {
    // Normalize whitespace first
    $email = normalize_whitespace($email);
    
    // Remove spaces around @ symbol (invalid in email addresses)
    $email = preg_replace('/\s*@\s*/', '@', $email);
    
    // Split into local and domain parts
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return $email; // Invalid format, return as-is (will fail validation)
    }
    
    [$localPart, $domain] = $parts;
    
    // Trim whitespace from parts (in case there were spaces before normalization)
    $localPart = trim($localPart);
    $domain = trim($domain);
    
    // Domain MUST be lowercase (RFC 5321)
    $domain = mb_strtolower($domain, 'UTF-8');
    
    // Local part: Best practice is to lowercase (Gmail, etc. ignore case)
    // Some providers are case-sensitive, but we normalize to lowercase for consistency
    $localPart = mb_strtolower($localPart, 'UTF-8');
    
    return $localPart . '@' . $domain;
}

/**
 * Validate username format and length.
 * 
 * Usernames must:
 * - Be 3-20 characters long
 * - Contain only alphanumeric characters, underscores, and hyphens
 * - Not start or end with underscore or hyphen
 * - Not contain consecutive underscores or hyphens
 * 
 * @param string $username The username to validate
 * @return array ['valid' => bool, 'error' => string|null]
 */
function validate_username(string $username): array {
    // Normalize whitespace and canonicalize
    $canonical = canonicalize_username($username);
    
    // Length check (use canonical form)
    if (strlen($canonical) < 3) {
        return ['valid' => false, 'error' => 'Username must be at least 3 characters long'];
    }
    
    if (strlen($canonical) > 20) {
        return ['valid' => false, 'error' => 'Username must be at most 20 characters long'];
    }
    
    // Character set check - only alphanumeric, underscore, hyphen
    // Validate original input (before canonicalization) to ensure it only contains valid characters
    $original = trim($username);
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $original)) {
        return ['valid' => false, 'error' => 'Username can only contain letters, numbers, underscores, and hyphens'];
    }
    
    // Cannot start or end with underscore or hyphen
    if (preg_match('/^[_-]|[_-]$/', $canonical)) {
        return ['valid' => false, 'error' => 'Username cannot start or end with underscore or hyphen'];
    }
    
    // Cannot have consecutive underscores or hyphens
    if (preg_match('/[_-]{2,}/', $canonical)) {
        return ['valid' => false, 'error' => 'Username cannot contain consecutive underscores or hyphens'];
    }
    
    // Check for HTML/script tags (additional safety)
    if (preg_match('/<[^>]*>/', $original)) {
        return ['valid' => false, 'error' => 'Username cannot contain HTML tags'];
    }
    
    return ['valid' => true, 'error' => null, 'canonical' => $canonical];
}

/**
 * Sanitize username for display (escape HTML).
 * This is a convenience wrapper around escape_html().
 * 
 * @param string $username The username to sanitize
 * @return string Escaped username safe for HTML display
 */
function sanitize_username(string $username): string {
    return escape_html($username);
}

/**
 * Validate email format and length.
 * 
 * Emails must:
 * - Be at most 255 characters long (matches database VARCHAR(255))
 * - Be in valid email format
 * 
 * @param string $email The email to validate
 * @return array ['valid' => bool, 'error' => string|null]
 */
function validate_email(string $email): array {
    // Canonicalize email (normalizes whitespace, lowercases domain and local part)
    $canonical = canonicalize_email($email);
    
    // Length check (use canonical form)
    if (strlen($canonical) > 255) {
        return ['valid' => false, 'error' => 'Email must be at most 255 characters long'];
    }
    
    // Format check (validate canonical form)
    if (!filter_var($canonical, FILTER_VALIDATE_EMAIL)) {
        return ['valid' => false, 'error' => 'Invalid email format'];
    }
    
    return ['valid' => true, 'error' => null, 'canonical' => $canonical];
}

/**
 * Validate password length.
 * 
 * Passwords must:
 * - Be at least 8 characters long
 * - Be at most 128 characters long (prevents resource exhaustion)
 * 
 * @param string $password The password to validate
 * @return array ['valid' => bool, 'error' => string|null]
 */
function validate_password(string $password): array {
    $length = strlen($password);
    
    if ($length < 8) {
        return ['valid' => false, 'error' => 'Password must be at least 8 characters long'];
    }
    
    if ($length > 128) {
        return ['valid' => false, 'error' => 'Password must be at most 128 characters long'];
    }
    
    return ['valid' => true, 'error' => null];
}

/**
 * Validate JSON payload size before decoding.
 * 
 * This prevents memory exhaustion from oversized payloads.
 * Recommended max size: 10KB (10240 bytes) for most API endpoints.
 * 
 * @param string $input The raw input from php://input
 * @param int $maxBytes Maximum allowed size in bytes (default: 10240 = 10KB)
 * @return array ['valid' => bool, 'error' => string|null, 'size' => int]
 */
function validate_json_payload_size(string $input, int $maxBytes = 10240): array {
    $size = strlen($input);
    
    if ($size > $maxBytes) {
        return [
            'valid' => false,
            'error' => "Payload too large (max {$maxBytes} bytes, got {$size} bytes)",
            'size' => $size
        ];
    }
    
    return ['valid' => true, 'error' => null, 'size' => $size];
}

/**
 * Get client IP address from request.
 * Handles proxies by checking X-Forwarded-For header (first IP in chain).
 * Falls back to REMOTE_ADDR if no proxy headers.
 * 
 * @return string Client IP address
 */
function get_client_ip(): string {
    // Check for proxy headers (X-Forwarded-For, X-Real-IP)
    $headers = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
    ];
    
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            $ip = trim($ips[0]); // First IP in chain is the original client
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    // Fallback to REMOTE_ADDR
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * In-memory rate limiting storage (per-request).
 * In production, consider using Redis or APCu for shared state across processes.
 */
class RateLimitStorage {
    private static array $ipRequests = [];
    private static array $userRequests = [];
    private static array $ipConnections = [];
    private static array $userConnections = [];
    
    /**
     * Get current request count for an IP in a time window.
     * 
     * @param string $key Identifier (IP or user ID)
     * @param int $windowSeconds Time window in seconds
     * @return array ['count' => int, 'window_start' => int]
     */
    public static function getRequestCount(string $key, int $windowSeconds = 60): array {
        $now = time();
        $windowStart = $now - ($now % $windowSeconds);
        $storageKey = "{$key}:{$windowStart}";
        
        if (!isset(self::$ipRequests[$storageKey])) {
            return ['count' => 0, 'window_start' => $windowStart];
        }
        
        return self::$ipRequests[$storageKey];
    }
    
    /**
     * Increment request count for an IP.
     * 
     * @param string $key Identifier (IP or user ID)
     * @param int $windowSeconds Time window in seconds
     * @return int New count
     */
    public static function incrementRequest(string $key, int $windowSeconds = 60): int {
        $now = time();
        $windowStart = $now - ($now % $windowSeconds);
        $storageKey = "{$key}:{$windowStart}";
        
        if (!isset(self::$ipRequests[$storageKey])) {
            self::$ipRequests[$storageKey] = ['count' => 0, 'window_start' => $windowStart];
        }
        
        self::$ipRequests[$storageKey]['count']++;
        
        // Cleanup old entries (older than 2 windows)
        $oldWindowStart = $windowStart - ($windowSeconds * 2);
        $oldStorageKey = "{$key}:{$oldWindowStart}";
        unset(self::$ipRequests[$oldStorageKey]);
        
        return self::$ipRequests[$storageKey]['count'];
    }
    
    /**
     * Get current connection count for an IP or user.
     * 
     * @param string $key Identifier (IP or user ID)
     * @return int Current connection count
     */
    public static function getConnectionCount(string $key): int {
        return self::$ipConnections[$key] ?? 0;
    }
    
    /**
     * Increment connection count.
     * 
     * @param string $key Identifier (IP or user ID)
     * @return int New count
     */
    public static function incrementConnection(string $key): int {
        if (!isset(self::$ipConnections[$key])) {
            self::$ipConnections[$key] = 0;
        }
        self::$ipConnections[$key]++;
        return self::$ipConnections[$key];
    }
    
    /**
     * Decrement connection count.
     * 
     * @param string $key Identifier (IP or user ID)
     * @return int New count
     */
    public static function decrementConnection(string $key): int {
        if (!isset(self::$ipConnections[$key]) || self::$ipConnections[$key] <= 0) {
            return 0;
        }
        self::$ipConnections[$key]--;
        if (self::$ipConnections[$key] <= 0) {
            unset(self::$ipConnections[$key]);
        }
        return self::$ipConnections[$key] ?? 0;
    }
    
    /**
     * Check if IP is temporarily banned.
     * 
     * @param string $ip IP address
     * @return bool True if banned
     */
    public static function isIpBanned(string $ip): bool {
        $banKey = "ban:{$ip}";
        if (!isset(self::$ipRequests[$banKey])) {
            return false;
        }
        
        $banUntil = self::$ipRequests[$banKey]['window_start'];
        if (time() > $banUntil) {
            unset(self::$ipRequests[$banKey]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Ban an IP for a specified duration.
     * 
     * @param string $ip IP address
     * @param int $durationSeconds Ban duration in seconds
     */
    public static function banIp(string $ip, int $durationSeconds = 300): void {
        $banKey = "ban:{$ip}";
        self::$ipRequests[$banKey] = [
            'count' => 1,
            'window_start' => time() + $durationSeconds,
        ];
    }
    
    /**
     * Reset all rate limit storage (for testing only)
     * 
     * This method should only be called in test environments.
     * Guards against accidental use in production.
     * 
     * @return void
     */
    public static function resetForTest(): void {
        // Only allow reset in test environment
        // Check multiple indicators that we're in a test
        $appEnv = getenv('APP_ENV') ?: getenv('ENVIRONMENT') ?: '';
        $isPhpUnit = class_exists('PHPUnit\Framework\TestCase', false);
        $isTestEnv = in_array($appEnv, ['testing', 'test'], true);
        
        if (!$isTestEnv && !$isPhpUnit && !defined('PHPUNIT_TEST')) {
            throw new RuntimeException('resetForTest() can only be called in test environment');
        }
        
        self::$ipRequests = [];
        self::$userRequests = [];
        self::$ipConnections = [];
        self::$userConnections = [];
    }
}

/**
 * Check if request is rate limited by IP.
 * 
 * @param string $ip IP address
 * @param int $maxRequests Maximum requests allowed
 * @param int $windowSeconds Time window in seconds
 * @return array ['allowed' => bool, 'remaining' => int, 'retry_after' => int|null]
 */
function check_ip_rate_limit(string $ip, int $maxRequests = 100, int $windowSeconds = 60): array {
    // Check if IP is banned
    if (RateLimitStorage::isIpBanned($ip)) {
        $banInfo = RateLimitStorage::getRequestCount("ban:{$ip}", 1);
        $retryAfter = max(0, $banInfo['window_start'] - time());
        return [
            'allowed' => false,
            'remaining' => 0,
            'retry_after' => $retryAfter,
            'reason' => 'ip_banned',
        ];
    }
    
    $count = RateLimitStorage::incrementRequest($ip, $windowSeconds);
    $remaining = max(0, $maxRequests - $count);
    
    // If limit exceeded, ban IP temporarily
    if ($count > $maxRequests) {
        RateLimitStorage::banIp($ip, 300); // 5 minute ban
        return [
            'allowed' => false,
            'remaining' => 0,
            'retry_after' => 300,
            'reason' => 'rate_limit_exceeded',
        ];
    }
    
    return [
        'allowed' => true,
        'remaining' => $remaining,
        'retry_after' => null,
        'reason' => null,
    ];
}

/**
 * Check if request is rate limited by user ID.
 * 
 * @param int $userId User ID
 * @param int $maxRequests Maximum requests allowed
 * @param int $windowSeconds Time window in seconds
 * @return array ['allowed' => bool, 'remaining' => int]
 */
function check_user_rate_limit(int $userId, int $maxRequests = 200, int $windowSeconds = 60): array {
    $key = "user:{$userId}";
    $count = RateLimitStorage::incrementRequest($key, $windowSeconds);
    $remaining = max(0, $maxRequests - $count);
    
    return [
        'allowed' => $count <= $maxRequests,
        'remaining' => $remaining,
        'count' => $count,
    ];
}

/**
 * Apply rate limiting to HTTP API endpoint.
 * Checks both IP and user-based limits.
 * 
 * @param int|null $userId User ID (null for unauthenticated requests)
 * @param int $ipMaxRequests Maximum requests per IP per window
 * @param int $userMaxRequests Maximum requests per user per window
 * @param int $windowSeconds Time window in seconds
 * @return bool True if allowed, false if rate limited (sends response and exits)
 */
function apply_rate_limiting(?int $userId = null, int $ipMaxRequests = 100, int $userMaxRequests = 200, int $windowSeconds = 60): bool {
    $ip = get_client_ip();
    
    // Check IP-based rate limit
    $ipLimit = check_ip_rate_limit($ip, $ipMaxRequests, $windowSeconds);
    if (!$ipLimit['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . ($ipLimit['retry_after'] ?? $windowSeconds));
        header('X-RateLimit-Limit: ' . $ipMaxRequests);
        header('X-RateLimit-Remaining: 0');
        if ($ipLimit['retry_after']) {
            header('X-RateLimit-Reset: ' . (time() + $ipLimit['retry_after']));
        }
        echo json_encode([
            'ok' => false,
            'error' => 'rate_limit_exceeded',
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => $ipLimit['retry_after'],
        ]);
        exit;
    }
    
    // Check user-based rate limit (if authenticated)
    if ($userId !== null) {
        $userLimit = check_user_rate_limit($userId, $userMaxRequests, $windowSeconds);
        if (!$userLimit['allowed']) {
            http_response_code(429);
            header('Retry-After: ' . $windowSeconds);
            header('X-RateLimit-Limit: ' . $userMaxRequests);
            header('X-RateLimit-Remaining: 0');
            echo json_encode([
                'ok' => false,
                'error' => 'rate_limit_exceeded',
                'message' => 'Too many requests. Please try again later.',
            ]);
            exit;
        }
        
        // Set rate limit headers
        header('X-RateLimit-Limit: ' . $userMaxRequests);
        header('X-RateLimit-Remaining: ' . $userLimit['remaining']);
    } else {
        // Set IP-based rate limit headers
        header('X-RateLimit-Limit: ' . $ipMaxRequests);
        header('X-RateLimit-Remaining: ' . $ipLimit['remaining']);
    }
    
    return true;
}

/**
 * Get stricter rate limits for authentication endpoints.
 * 
 * @param string $ip IP address
 * @param int $maxRequests Maximum requests (default: 5 for login/register)
 * @param int $windowSeconds Time window (default: 60 seconds)
 * @return bool True if allowed, false if rate limited (sends response and exits)
 */
function apply_auth_rate_limiting(string $ip, int $maxRequests = 5, int $windowSeconds = 60): bool {
    $ipLimit = check_ip_rate_limit($ip, $maxRequests, $windowSeconds);
    if (!$ipLimit['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . ($ipLimit['retry_after'] ?? $windowSeconds));
        header('X-RateLimit-Limit: ' . $maxRequests);
        header('X-RateLimit-Remaining: 0');
        if ($ipLimit['retry_after']) {
            header('X-RateLimit-Reset: ' . (time() + $ipLimit['retry_after']));
        }
        echo json_encode([
            'ok' => false,
            'error' => 'rate_limit_exceeded',
            'message' => 'Too many authentication attempts. Please try again later.',
            'retry_after' => $ipLimit['retry_after'],
        ]);
        exit;
    }
    
    header('X-RateLimit-Limit: ' . $maxRequests);
    header('X-RateLimit-Remaining: ' . $ipLimit['remaining']);
    
    return true;
}

/**
 * Validate and consume a CSRF nonce token.
 * 
 * This function validates that a nonce:
 * - Exists in the database
 * - Has not been used before (single-use)
 * - Has not expired
 * - Belongs to the current session (if sessionId provided)
 * - The session is valid (if sessionId provided)
 * 
 * After validation, the nonce is marked as used (consumed).
 * 
 * @param PDO $pdo Database connection
 * @param string $nonce The nonce token to validate
 * @param int|null $sessionId Optional: session ID to verify nonce belongs to this session
 * @return array ['valid' => bool, 'error' => string|null]
 * 
 * @throws RuntimeException If validation fails
 */
function validate_csrf_token(PDO $pdo, string $nonce, ?int $sessionId = null): void {
    require_once __DIR__ . '/../app/db/nonces.php';
    require_once __DIR__ . '/../app/db/sessions.php';
    
    if (empty($nonce)) {
        throw new RuntimeException('CSRF_TOKEN_MISSING');
    }
    
    // Fetch nonce from database
    $row = db_get_nonce($pdo, $nonce);
    if (!$row) {
        throw new RuntimeException('CSRF_TOKEN_INVALID');
    }
    
    // Check if nonce has already been used
    if ($row['used_at'] !== null) {
        throw new RuntimeException('CSRF_TOKEN_ALREADY_USED');
    }
    
    // Check if nonce has expired (use MySQL time comparison)
    $expiresAt = strtotime($row['expires_at']);
    if ($expiresAt === false || $expiresAt < time()) {
        throw new RuntimeException('CSRF_TOKEN_EXPIRED');
    }
    
    // If session ID provided, verify nonce belongs to this session
    if ($sessionId !== null) {
        if ((int)$row['session_id'] !== $sessionId) {
            throw new RuntimeException('CSRF_TOKEN_SESSION_MISMATCH');
        }
        
        // Verify session is still valid
        if (!db_is_session_valid($pdo, $sessionId)) {
            throw new RuntimeException('CSRF_TOKEN_SESSION_INVALID');
        }
    }
    
    // Mark nonce as used (consumed)
    db_mark_nonce_used($pdo, (int)$row['id']);
}

