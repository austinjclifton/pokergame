<?php
// backend/app/services/AuthService.php
// -----------------------------------------------------------------------------
// Handles authentication logic (login, logout, registration).
// Coordinates data access + presence updates without direct SQL.
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/../db/users.php';
require_once __DIR__ . '/../db/nonces.php';
require_once __DIR__ . '/../db/sessions.php';
require_once __DIR__ . '/../db/presence.php';
require_once __DIR__ . '/../services/PresenceService.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../../lib/session.php';
require_once __DIR__ . '/../../lib/security.php';

// -----------------------------------------------------------------------------
// LOGIN
// -----------------------------------------------------------------------------

function auth_login_user(PDO $pdo, string $username, string $password): array {
    // Username is canonicalized inside db_get_user_by_username()
    $user = db_get_user_by_username($pdo, $username);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        throw new RuntimeException('INVALID_CREDENTIALS');
    }

    // Rehash if needed
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        db_update_user_password_hash($pdo, (int)$user['id'], $newHash);
    }

    // Create session
    $ip = $_SERVER['REMOTE_ADDR']     ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $sessionId = createSession($pdo, (int)$user['id'], $ip, $ua);

    // Audit log: successful login
    try {
        log_audit_event($pdo, [
            'user_id' => (int)$user['id'],
            'session_id' => $sessionId,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'action' => 'user.login',
            'entity_type' => 'user',
            'entity_id' => (int)$user['id'],
            'details' => [
                'username' => $user['username'],
                'password_rehashed' => password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT),
            ],
            'channel' => 'api',
            'status' => 'success',
            'severity' => 'info',
        ]);
    } catch (Throwable $e) {
        // Don't fail login if audit logging fails
        error_log('[AuthService] Audit logging failed: ' . $e->getMessage());
    }

    // Don't mark as online here - presence is handled when WebSocket connects
    // This ensures join messages are sent correctly when they actually connect to the lobby
    
    return [
        'ok' => true,
        'user' => [
            'id'         => (int)$user['id'],
            'username'   => escape_html($user['username']), // Escape for XSS prevention
            'email'      => $user['email'],
            'session_id' => $sessionId,
        ],
    ];
}

// -----------------------------------------------------------------------------
// LOGOUT
// -----------------------------------------------------------------------------

function auth_logout_user(PDO $pdo): bool {
    // Get session info before revoking
    $session = null;
    try {
        $session = requireSession($pdo);
    } catch (Throwable $e) {
        // Session may already be invalid
    }
    
    $revoked = revokeSession($pdo);

    if ($revoked && !empty($_COOKIE['session_id'])) {
        $sid = (int) $_COOKIE['session_id'];

        // Remove from presence table completely
        db_remove_presence_by_session($pdo, $sid);

        // Audit log: successful logout
        if ($session && !empty($session['user_id'])) {
            try {
                log_audit_event($pdo, [
                    'user_id' => (int)$session['user_id'],
                    'session_id' => $sid,
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'action' => 'user.logout',
                    'entity_type' => 'user',
                    'entity_id' => (int)$session['user_id'],
                    'channel' => 'api',
                    'status' => 'success',
                    'severity' => 'info',
                ]);
            } catch (Throwable $e) {
                // Don't fail logout if audit logging fails
                error_log('[AuthService] Audit logging failed: ' . $e->getMessage());
            }
        }

        // Optionally confirm removal
        return true;
    }

    return $revoked;
}

// -----------------------------------------------------------------------------
// REGISTER
// -----------------------------------------------------------------------------

function auth_register_user(PDO $pdo, string $username, string $email, string $password, string $nonce): array {
    // Validate username format (additional check, API should also validate)
    $usernameValidation = validate_username($username);
    if (!$usernameValidation['valid']) {
        throw new RuntimeException('INVALID_USERNAME: ' . $usernameValidation['error']);
    }
    
    // Get canonical username from validation
    $canonicalUsername = $usernameValidation['canonical'] ?? canonicalize_username($username);
    
    // Validate email format and length
    $emailValidation = validate_email($email);
    if (!$emailValidation['valid']) {
        throw new RuntimeException('INVALID_EMAIL: ' . $emailValidation['error']);
    }
    
    // Get canonical email from validation
    $canonicalEmail = $emailValidation['canonical'] ?? canonicalize_email($email);
    
    // Validate password length
    $passwordValidation = validate_password($password);
    if (!$passwordValidation['valid']) {
        throw new RuntimeException('INVALID_PASSWORD: ' . $passwordValidation['error']);
    }
    
    $row = db_get_nonce($pdo, $nonce);
    if (!$row || $row['used_at'] !== null || strtotime($row['expires_at']) < time()) {
        throw new RuntimeException('INVALID_NONCE');
    }
    db_mark_nonce_used($pdo, (int)$row['id']);

    if (!db_is_session_valid($pdo, (int)$row['session_id'])) {
        throw new RuntimeException('NONCE_SESSION_INVALID');
    }

    // Check if user exists using canonical values
    if (db_user_exists($pdo, $canonicalUsername, $canonicalEmail)) {
        throw new RuntimeException('USER_EXISTS');
    }

    // Check if we're already in a transaction (e.g., from tests)
    $inTransaction = $pdo->inTransaction();
    
    try {
        if (!$inTransaction) {
            $pdo->beginTransaction();
        }
        // db_insert_user() will canonicalize again, but that's fine (idempotent)
        $userId = db_insert_user($pdo, $canonicalUsername, $canonicalEmail, password_hash($password, PASSWORD_DEFAULT));
        if (!$inTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if (!$inTransaction) {
            $pdo->rollBack();
        }
        throw new RuntimeException('USER_CREATION_FAILED');
    }

    // Create session for new user
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $sessionId = createSession($pdo, $userId, $ip, $ua);

    // Audit log: user registration
    try {
        log_audit_event($pdo, [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'action' => 'user.register',
            'entity_type' => 'user',
            'entity_id' => $userId,
            'details' => [
                'username' => $canonicalUsername,
                'email' => $canonicalEmail, // Consider redacting domain for privacy
            ],
            'channel' => 'api',
            'status' => 'success',
            'severity' => 'info',
        ]);
    } catch (Throwable $e) {
        // Don't fail registration if audit logging fails
        error_log('[AuthService] Audit logging failed: ' . $e->getMessage());
    }

    // Mark as online (presence system) - use canonical username
    $presence = new PresenceService($pdo);
    $presence->markOnline($userId, $canonicalUsername);

    // Return canonical username (but escape for XSS)
    return [
        'ok' => true,
        'user' => [
            'id'         => $userId,
            'username'   => escape_html($canonicalUsername), // Escape for XSS prevention
            'email'      => $canonicalEmail,
            'session_id' => $sessionId,
        ],
        'presence' => [
            'joined' => true,
        ],
    ];
}

// -----------------------------------------------------------------------------
// SESSION + WS TOKEN HELPERS
// -----------------------------------------------------------------------------

function auth_require_session(PDO $pdo): array {
    $session = requireSession($pdo);
    if (!$session || empty($session['user_id'])) {
        throw new RuntimeException('UNAUTHORIZED');
    }

    return [
        'id'         => (int)$session['user_id'],
        'username'   => $session['username'],
        'email'      => $session['email'],
        'session_id' => (int)$session['session_id'],
    ];
}

function auth_verify_ws_token(PDO $pdo, string $token): ?array {
    try {
        $row = db_consume_ws_nonce($pdo, $token);
        if (!$row) {
            return null;
        }

        return [
            'user_id'    => (int)$row['user_id'],
            'session_id' => (int)$row['session_id'],
            'username'   => (string)$row['username'],
        ];
    } catch (Throwable $e) {
        error_log('auth_verify_ws_token failed: ' . $e->getMessage());
        return null;
    }
}
