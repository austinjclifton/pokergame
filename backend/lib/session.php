<?php
// backend/lib/session.php
// -----------------------------------------------------------------------------
// Session lifecycle management (no direct SQL).
// Uses /app/db/sessions.php for persistence.
// -----------------------------------------------------------------------------

require_once __DIR__ . '/../app/db/sessions.php';

const SESSION_TTL_DAYS = 7;
const SESSION_TOUCH_HOURS = 12;

function createSession(PDO $pdo, int $userId, string $ip, string $userAgent): int {
    $ipHash  = hash('sha256', $ip);
    $expires = (new DateTime())->modify('+' . SESSION_TTL_DAYS . ' days')->format('Y-m-d H:i:s');

    $sessionId = db_insert_session($pdo, $userId, $ipHash, substr($userAgent, 0, 255), $expires);

    setcookie('session_id', $sessionId, [
        'expires'  => time() + (SESSION_TTL_DAYS * 86400),
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return $sessionId;
}

/**
 * Create a temporary session for unauthenticated users (e.g., for CSRF nonce issuance).
 * Temporary sessions have NULL user_id.
 */
function createTemporarySession(PDO $pdo, string $ip, string $userAgent): int {
    $ipHash  = hash('sha256', $ip);
    $expires = (new DateTime())->modify('+' . SESSION_TTL_DAYS . ' days')->format('Y-m-d H:i:s');

    $sessionId = db_insert_temporary_session($pdo, $ipHash, substr($userAgent, 0, 255), $expires);

    setcookie('session_id', $sessionId, [
        'expires'  => time() + (SESSION_TTL_DAYS * 86400),
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return $sessionId;
}

/**
 * Validates and returns the active session's user.
 * Returns an associative array:
 * [
 *   'user_id'    => int,
 *   'username'   => string,
 *   'email'      => string,
 *   'session_id' => int  // numeric ID from sessions.id (for FK use)
 * ]
 */
function requireSession(PDO $pdo): ?array {
    $sid = isset($_COOKIE['session_id']) ? (int)$_COOKIE['session_id'] : 0;
    if ($sid <= 0) return null;

    $row = db_get_session_with_user($pdo, $sid);
    if (!$row) return null;

    $currentIpHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $currentUA = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    if (!hash_equals($row['ip_hash'], $currentIpHash)) return null;
    if (strncmp($row['user_agent'], $currentUA, 60) !== 0) return null;

    // Extend session if nearing expiry
    if (strtotime($row['expires_at']) - time() < SESSION_TOUCH_HOURS * 3600) {
        db_touch_session($pdo, $sid, SESSION_TTL_DAYS);
    }

    // Return unified structure with numeric session_id
    return [
        'user_id'    => (int)$row['user_id'],
        'username'   => $row['username'],
        'email'      => $row['email'],
        'session_id' => (int)$row['session_id'], // <— used by NonceService & WebSocket
    ];
}

function revokeSession(PDO $pdo): bool {
    $sid = isset($_COOKIE['session_id']) ? (int)$_COOKIE['session_id'] : 0;
    if ($sid <= 0) return false;

    db_revoke_session($pdo, $sid);

    setcookie('session_id', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return true;
}

function touchSession(PDO $pdo, int $sessionId): void {
    db_touch_session($pdo, $sessionId, SESSION_TTL_DAYS);
}

function destroyAllSessionsForUser(PDO $pdo, int $userId): void {
    db_revoke_all_user_sessions($pdo, $userId);
}
