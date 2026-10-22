<?php
// Opaque, server-stored session lifecycle management.

declare(strict_types=1);

require_once __DIR__ . '/../app/db/sessions.php';

const SESSION_COOKIE_NAME = 'session_token';
const SESSION_TTL_DAYS = 7;
const SESSION_ROTATE_HOURS = 24;
const SESSION_TOUCH_HOURS = 12;

function sessionCookieIsSecure(): bool
{
    if (function_exists('request_is_https')) {
        return request_is_https();
    }
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    return $https !== '' && $https !== 'off' && $https !== '0';
}

function sessionClientIp(): string
{
    return function_exists('get_client_ip')
        ? get_client_ip()
        : (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function generateSessionToken(): string
{
    return bin2hex(random_bytes(32));
}

function hashSessionToken(string $token): string
{
    return hash('sha256', $token);
}

function setSessionCookie(string $token, int $expires): void
{
    setcookie(SESSION_COOKIE_NAME, $token, [
        'expires' => $expires,
        'path' => '/',
        'secure' => sessionCookieIsSecure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearSessionCookies(): void
{
    $options = [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => sessionCookieIsSecure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    setcookie(SESSION_COOKIE_NAME, '', $options);
    // Remove the obsolete predictable cookie without accepting it.
    setcookie('session_id', '', $options);
}

function createSession(PDO $pdo, int $userId, string $ip, string $userAgent): int
{
    $token = generateSessionToken();
    $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . SESSION_TTL_DAYS . ' days');
    $sessionId = db_insert_session(
        $pdo,
        $userId,
        hash('sha256', $ip),
        substr($userAgent, 0, 255),
        $expires->format('Y-m-d H:i:s'),
        hashSessionToken($token),
    );
    setSessionCookie($token, $expires->getTimestamp());
    return $sessionId;
}

function createTemporarySession(PDO $pdo, string $ip, string $userAgent): int
{
    $token = generateSessionToken();
    $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+' . SESSION_TTL_DAYS . ' days');
    $sessionId = db_insert_temporary_session(
        $pdo,
        hash('sha256', $ip),
        substr($userAgent, 0, 255),
        $expires->format('Y-m-d H:i:s'),
        hashSessionToken($token),
    );
    setSessionCookie($token, $expires->getTimestamp());
    return $sessionId;
}

/** Resolve the current cookie to an internal session row. */
function currentSession(PDO $pdo, bool $requireUser = true): ?array
{
    $token = (string) ($_COOKIE[SESSION_COOKIE_NAME] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        return null;
    }

    $row = db_get_session_by_token_hash($pdo, hashSessionToken($token));
    if (!$row || ($requireUser && (empty($row['user_id']) || $row['username'] === null))) {
        return null;
    }

    $currentUa = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    if (strncmp((string) $row['user_agent'], $currentUa, 60) !== 0) {
        return null;
    }

    $utc = new DateTimeZone('UTC');
    $expiresAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $row['expires_at'], $utc);
    $rotatedAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $row['rotated_at'], $utc);
    if (!$expiresAt || !$rotatedAt) {
        return null;
    }
    $expiresSoon = $expiresAt->getTimestamp() - time() < SESSION_TOUCH_HOURS * 3600;
    $rotationDue = time() - $rotatedAt->getTimestamp() >= SESSION_ROTATE_HOURS * 3600;
    if ($expiresSoon || $rotationDue) {
        $newToken = generateSessionToken();
        if (!db_rotate_session_token($pdo, (int) $row['session_id'], hashSessionToken($newToken), SESSION_TTL_DAYS)) {
            return null;
        }
        setSessionCookie($newToken, time() + SESSION_TTL_DAYS * 86400);
    }

    return $row;
}

function requireSession(PDO $pdo): ?array
{
    $row = currentSession($pdo, true);
    if (!$row) {
        return null;
    }

    return [
        'user_id' => (int) $row['user_id'],
        'username' => (string) $row['username'],
        'email' => (string) $row['email'],
        'is_admin' => (bool) $row['is_admin'],
        'session_id' => (int) $row['session_id'],
    ];
}

function revokeSession(PDO $pdo): bool
{
    $session = currentSession($pdo, false);
    clearSessionCookies();
    if (!$session) {
        return false;
    }
    db_revoke_session($pdo, (int) $session['session_id']);
    return true;
}

function touchSession(PDO $pdo, int $sessionId): void
{
    db_touch_session($pdo, $sessionId, SESSION_TTL_DAYS);
}

function destroyAllSessionsForUser(PDO $pdo, int $userId): void
{
    db_revoke_all_user_sessions($pdo, $userId);
}
