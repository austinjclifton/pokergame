<?php
/**
 * backend/app/db/chat_messages.php
 *
 * Data access functions for CHAT_MESSAGES table.
 * Used by LobbyService, GameService, and WebSocket event handlers.
 */

declare(strict_types=1);

/**
 * Insert a new chat message into the database.
 *
 * @param PDO    $pdo
 * @param string $channelType  'lobby' or 'game'
 * @param int    $channelId    0 for lobby, or GAMES.id for game
 * @param int    $senderUserId
 * @param string $body
 * @param int|null $recipientUserId  optional private message target
 * @return int inserted message ID
 */
function db_insert_chat_message(
    PDO $pdo,
    string $channelType,
    int $channelId,
    int $senderUserId,
    string $body,
    ?int $recipientUserId = null,
    ?string $senderUsername = null
): int {
    require_once __DIR__ . '/../../lib/security.php';
    
    // If sender_username not provided, fetch it from users table
    if ($senderUsername === null) {
        $userStmt = $pdo->prepare("SELECT username FROM users WHERE id = :id LIMIT 1");
        $userStmt->execute(['id' => $senderUserId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        $senderUsername = $user ? $user['username'] : "User#$senderUserId";
    }

    // Canonicalize username before storing (case-insensitive, normalized whitespace)
    $canonicalUsername = canonicalize_username($senderUsername);

    $stmt = $pdo->prepare("
        INSERT INTO chat_messages
        (channel_type, channel_id, sender_user_id, sender_username, recipient_user_id, body)
        VALUES (:t, :cid, :sid, :sname, :rid, :body)
    ");
    $stmt->execute([
        't'     => $channelType,
        'cid'   => $channelId,
        'sid'   => $senderUserId,
        'sname' => $canonicalUsername,
        'rid'   => $recipientUserId,
        'body'  => $body,
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Retrieve recent chat messages for a given channel.
 *
 * @param PDO    $pdo
 * @param string $channelType  'lobby' or 'game'
 * @param int    $channelId
 * @param int    $limit
 * @return array[] list of associative arrays
 */
function db_get_recent_chat_messages(
    PDO $pdo,
    string $channelType,
    int $channelId,
    int $limit = 20
): array {
    // Ensure limit is between 1 and 100 (reasonable maximum)
    $limit = max(1, min((int)$limit, 100));
    
    // Note: MySQL LIMIT clause doesn't support parameter binding in all versions,
    // but since we've validated and cast to int with bounds checking, it's safe.
    // The limit is guaranteed to be a safe integer between 1-100.
    $sql = "
        SELECT id,
               channel_type,
               channel_id,
               sender_user_id,
               sender_username,
               body,
               created_at
        FROM chat_messages
        WHERE channel_type = :t
          AND channel_id = :cid
          AND created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
        ORDER BY created_at DESC
        LIMIT " . (int)$limit . "
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        't' => $channelType,
        'cid' => $channelId,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_reverse($rows); // return oldest → newest
}

/**
 * Delete chat messages older than a cutoff (for cleanup)
 *
 * @param PDO $pdo
 * @param string $channelType
 * @param string $cutoff ISO datetime string
 * @return int number of rows deleted
 */
function db_delete_old_chat_messages(PDO $pdo, string $channelType, string $cutoff): int {
    $stmt = $pdo->prepare("
        DELETE FROM chat_messages
        WHERE channel_type = :t
          AND created_at < :cutoff
    ");
    $stmt->execute([
        't' => $channelType,
        'cutoff' => $cutoff
    ]);
    return $stmt->rowCount();
}
