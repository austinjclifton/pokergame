<?php
// backend/app/db/subscriptions.php
// Data access for WS_SUBSCRIPTIONS table (pure SQL)

declare(strict_types=1);

/**
 * Register a new WebSocket connection.
 */
function db_insert_subscription(
    PDO $pdo,
    int $userId,
    string $connectionId,
    string $channelType,
    int $channelId
): bool {
    $gameId = ($channelType === 'game') ? $channelId : null;

    $stmt = $pdo->prepare("
        INSERT INTO ws_subscriptions
          (user_id, connection_id, channel_type, channel_id, game_id, connected_at, last_ping_at)
        VALUES
          (:uid, :cid, :ctype, :chid, :gid, NOW(), NOW())
    ");
    return $stmt->execute([
        'uid'   => $userId,
        'cid'   => $connectionId,
        'ctype' => $channelType,
        'chid'  => $channelId,
        'gid'   => $gameId,
    ]);
}

/**
 * Update heartbeat (ping).
 */
function db_update_subscription_ping(PDO $pdo, string $connectionId): bool {
    $stmt = $pdo->prepare("
        UPDATE ws_subscriptions
        SET last_ping_at = NOW()
        WHERE connection_id = :cid
    ");
    return $stmt->execute(['cid' => $connectionId]);
}

/**
 * Mark a connection as disconnected.
 */
function db_set_subscription_disconnected(PDO $pdo, string $connId): bool {
    $stmt = $pdo->prepare("UPDATE ws_subscriptions 
                           SET disconnected_at = NOW() 
                           WHERE connection_id = :id AND disconnected_at IS NULL");
    $stmt->execute(['id' => $connId]);
    return $stmt->rowCount() > 0;
}

/**
 * Delete a subscription completely (for cleanup).
 */
function db_delete_subscription(PDO $pdo, string $connectionId): bool {
    $stmt = $pdo->prepare("
        DELETE FROM ws_subscriptions
        WHERE connection_id = :cid
    ");
    return $stmt->execute(['cid' => $connectionId]);
}

/**
 * Retrieve all active connections for a user.
 */
function db_get_user_subscriptions(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("
        SELECT id, connection_id, channel_type, channel_id, connected_at, last_ping_at
        FROM ws_subscriptions
        WHERE user_id = :uid
          AND disconnected_at IS NULL
    ");
    $stmt->execute(['uid' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Count active (not disconnected) connections for a user in a channel type.
 */
function db_count_active_connections_in_channel(PDO $pdo, int $userId, string $channelType): int {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM ws_subscriptions 
        WHERE user_id = :uid 
          AND channel_type = :ctype 
          AND disconnected_at IS NULL
    ");
    $stmt->execute(['uid' => $userId, 'ctype' => $channelType]);
    return (int)$stmt->fetchColumn();
}

/**
 * Optional cleanup: remove old, inactive subscriptions.
 */
function db_delete_stale_subscriptions(PDO $pdo, int $staleMinutes = 10): int {
    $stmt = $pdo->prepare("
        DELETE FROM ws_subscriptions
        WHERE disconnected_at IS NOT NULL
           OR last_ping_at < (NOW() - INTERVAL :mins MINUTE)
    ");
    $stmt->execute(['mins' => $staleMinutes]);
    return $stmt->rowCount();
}
