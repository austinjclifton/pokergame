<?php
// backend/app/db/sessions.php
// -----------------------------------------------------------------------------
// Data Access Layer (DAL) for the SESSIONS table.
// -----------------------------------------------------------------------------
// This file defines pure SQL helpers that perform low-level operations on
// the `sessions` table — creation, revocation, validation, and TTL extension.
// 
// Responsibilities:
//   • Insert, update, and fetch session rows
//   • Join sessions with user data when needed
//   • Contain NO business logic or cookie handling
//
// Not responsible for:
//   • HTTP cookies or headers
//   • Authentication workflows (see AuthService.php)
//   • Session TTL policies or fingerprint checks
//
// These helpers are used by higher layers such as `lib/session.php`
// (session lifecycle logic) and `app/services/AuthService.php` (auth flows).
// -----------------------------------------------------------------------------

declare(strict_types=1);

/**
 * Checks whether a session ID is still valid (not revoked, not expired).
 */
function db_is_session_valid(PDO $pdo, int $sessionId): bool {
    $stmt = $pdo->prepare("
        SELECT id
        FROM sessions
        WHERE id = :sid
          AND revoked_at IS NULL
          AND expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute(['sid' => $sessionId]);
    return (bool) $stmt->fetch();
}

/**
 * Get the user_id linked to a given session ID.
 * Returns int|null if not found.
 */
function db_get_session_user_id(PDO $pdo, int $sessionId): ?int {
    $stmt = $pdo->prepare("
        SELECT user_id
        FROM sessions
        WHERE id = :sid
        LIMIT 1
    ");
    $stmt->execute(['sid' => $sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['user_id'] : null;
}

/**
 * Marks a session as revoked (used on logout or admin action).
 */
function db_revoke_session(PDO $pdo, int $sessionId): void {
    $stmt = $pdo->prepare("UPDATE sessions SET revoked_at = NOW() WHERE id = :sid");
    $stmt->execute(['sid' => $sessionId]);
}

/**
 * Insert a new session and return its ID.
 */
function db_insert_session(PDO $pdo, int $userId, string $ipHash, string $userAgent, string $expiresAt): int {
    $stmt = $pdo->prepare("
        INSERT INTO sessions (user_id, expires_at, ip_hash, user_agent)
        VALUES (:uid, :exp, :ip, :ua)
    ");
    $stmt->execute([
        'uid' => $userId,
        'exp' => $expiresAt,
        'ip'  => $ipHash,
        'ua'  => $userAgent,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Insert a temporary session (for unauthenticated users) and return its ID.
 * Temporary sessions have NULL user_id and are used for CSRF nonce issuance.
 */
function db_insert_temporary_session(PDO $pdo, string $ipHash, string $userAgent, string $expiresAt): int {
    $stmt = $pdo->prepare("
        INSERT INTO sessions (user_id, expires_at, ip_hash, user_agent)
        VALUES (NULL, :exp, :ip, :ua)
    ");
    $stmt->execute([
        'exp' => $expiresAt,
        'ip'  => $ipHash,
        'ua'  => $userAgent,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Fetch a session joined with its user data.
 */
function db_get_session_with_user(PDO $pdo, int $sessionId): ?array {
    $stmt = $pdo->prepare("
        SELECT u.id AS user_id, u.username, u.email,
               s.id AS session_id, s.expires_at, s.ip_hash, s.user_agent
        FROM sessions s
        JOIN users u ON u.id = s.user_id
        WHERE s.id = :sid
          AND s.revoked_at IS NULL
          AND s.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute(['sid' => $sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Update session expiry time (extend TTL).
 */
function db_touch_session(PDO $pdo, int $sessionId, int $days): void {
    $stmt = $pdo->prepare("
        UPDATE sessions
        SET expires_at = DATE_ADD(NOW(), INTERVAL :days DAY)
        WHERE id = :sid
          AND revoked_at IS NULL
    ");
    $stmt->execute(['sid' => $sessionId, 'days' => $days]);
}

/**
 * Revoke all sessions for a user.
 */
function db_revoke_all_user_sessions(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare("UPDATE sessions SET revoked_at = NOW() WHERE user_id = :uid");
    $stmt->execute(['uid' => $userId]);
}