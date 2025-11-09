<?php
// backend/app/db/presence.php
// Data access for USER_LOBBY_PRESENCE (pure SQL; no business logic)

declare(strict_types=1);

/**
 * Insert or update a user's lobby presence record.
 * Marks them as the given status (default: 'online') and refreshes timestamp.
 * Username is canonicalized before storage (case-insensitive, normalized whitespace).
 */
function db_upsert_presence(PDO $pdo, int $userId, string $username, string $status = 'online'): bool {
    require_once __DIR__ . '/../../lib/security.php';
    $canonicalUsername = canonicalize_username($username);
    
    $stmt = $pdo->prepare("
        INSERT INTO user_lobby_presence (user_id, user_username, status, last_seen_at)
        VALUES (:uid, :uname, :status, NOW())
        ON DUPLICATE KEY UPDATE
          status = VALUES(status),
          last_seen_at = NOW(),
          user_username = VALUES(user_username)
    ");
    return $stmt->execute([
        'uid'    => $userId,
        'uname'  => $canonicalUsername,
        'status' => $status,
    ]);
}

/**
 * Mark a user as offline/idle by user_id.
 */
function db_set_offline(PDO $pdo, int $userId, string $status = 'idle'): bool {
    // Ensure status is valid for the ENUM column
    $validStatuses = ['online', 'in_game', 'idle'];
    if (!in_array($status, $validStatuses)) {
        $status = 'idle'; // Default to idle for logout
    }
    
    $stmt = $pdo->prepare("
        UPDATE user_lobby_presence
        SET status = :status, last_seen_at = NOW()
        WHERE user_id = :uid
    ");
    return $stmt->execute([
        'uid'    => $userId,
        'status' => $status,
    ]);
}

/**
 * Update only the last_seen_at timestamp (heartbeat).
 */
function db_update_last_seen(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare("
        UPDATE user_lobby_presence
        SET last_seen_at = NOW()
        WHERE user_id = :uid
    ");
    return $stmt->execute(['uid' => $userId]);
}

/**
 * Retrieve all users currently marked 'online'.
 */
function db_get_online_users(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT user_id, user_username, status, last_seen_at
        FROM user_lobby_presence
        WHERE status = 'online'
        ORDER BY user_username
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * (Optional) Remove users who haven't been seen for a given number of minutes.
 * Useful for periodic cleanup if clients disconnect unexpectedly.
 */
function db_purge_stale_presences(PDO $pdo, int $staleMinutes = 10): int {
    $stmt = $pdo->prepare("
        DELETE FROM user_lobby_presence
        WHERE last_seen_at < (NOW() - INTERVAL :mins MINUTE)
          AND status != 'in_game'
    ");
    $stmt->execute(['mins' => $staleMinutes]);
    return $stmt->rowCount();
}

/**
 * Mark a user as online in the lobby.
 * This is an alias for db_upsert_presence with 'online' status.
 * Username is canonicalized before storage.
 */
function db_set_user_online(PDO $pdo, int $userId, string $username): bool {
    return db_upsert_presence($pdo, $userId, $username, 'online');
}

/**
 * Remove a user's presence entry when logging out via session.
 */
function db_remove_presence_by_session(PDO $pdo, int $sessionId): bool {
    // Get the user_id from the session first
    $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE id = :sid LIMIT 1");
    $stmt->execute(['sid' => $sessionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false; // Session not found
    }

    $userId = (int)$row['user_id'];
    return db_remove_presence($pdo, $userId);
}

/**
 * Permanently remove a user from the presence table (true logout).
 */
function db_remove_presence(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare("DELETE FROM user_lobby_presence WHERE user_id = :uid");
    return $stmt->execute(['uid' => $userId]);
}

/**
 * Fetch the current presence status for a given user_id.
 */
function db_get_user_status(PDO $pdo, int $userId): ?string {
    $stmt = $pdo->prepare("
        SELECT status FROM user_lobby_presence WHERE user_id = :uid LIMIT 1
    ");
    $stmt->execute(['uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['status'] ?? null;
}

/**
 * Fetch a user's username (utility for presence updates).
 */
function db_get_username(PDO $pdo, int $userId): ?string {
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $userId]);
    return $stmt->fetchColumn() ?: null;
}

/**
 * Fetch a single user's presence record (or null if none).
 */
function db_get_user_presence(PDO $pdo, int $userId): ?array {
    $stmt = $pdo->prepare("
        SELECT user_id, user_username, status, last_seen_at
        FROM user_lobby_presence
        WHERE user_id = :uid
        LIMIT 1
    ");
    $stmt->execute(['uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
