<?php
/**
 * backend/app/services/SubscriptionService.php
 *
 * Service layer for managing WebSocket subscriptions (WS_SUBSCRIPTIONS table).
 */

require_once __DIR__ . '/../db/subscriptions.php';

class SubscriptionService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Register a new active WebSocket connection.
     */
    public function register(int $userId, string $connId, string $channelType = 'lobby', int $channelId = 0): bool
    {
        try {
            // First, clean up any existing connection with this ID
            db_delete_subscription($this->pdo, $connId);
            
            return db_insert_subscription($this->pdo, $userId, $connId, $channelType, $channelId);
        } catch (PDOException $e) {
            // If it's a duplicate key error, try to clean up and retry once
            if ($e->getCode() == 23000 && strpos($e->getMessage(), 'Duplicate entry') !== false) {
                error_log("Duplicate connection ID detected, cleaning up: " . $connId);
                db_delete_subscription($this->pdo, $connId);
                return db_insert_subscription($this->pdo, $userId, $connId, $channelType, $channelId);
            }
            throw $e;
        }
    }

    /**
     * Update heartbeat ping.
     */
    public function ping(string $connId): bool
    {
        return db_update_subscription_ping($this->pdo, $connId);
    }

    /**
     * Mark a WebSocket connection as disconnected.
     */
    public function disconnect(string $connId): bool
    {
        return db_set_subscription_disconnected($this->pdo, $connId);
    }

    /**
     * Get all active connections for a given user.
     */
    public function getUserConnections(int $userId): array
    {
        return db_get_user_subscriptions($this->pdo, $userId);
    }

    /**
     * Optional cleanup of old records.
     */
    public function cleanupStale(int $minutes = 10): int
    {
        return db_delete_stale_subscriptions($this->pdo, $minutes);
    }

    /**
     * Return number of active (not disconnected) connections for a user in a channel type.
     */
    public function countActiveInChannel(int $userId, string $channelType = 'lobby'): int
    {
        return db_count_active_connections_in_channel($this->pdo, $userId, $channelType);
    }

    /**
     * Whether the user has at least one active connection in a channel type.
     */
    public function userHasActiveInChannel(int $userId, string $channelType = 'lobby'): bool
    {
        return $this->countActiveInChannel($userId, $channelType) > 0;
    }
}
