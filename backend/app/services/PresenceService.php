<?php
// backend/app/services/PresenceService.php
// -----------------------------------------------------------------------------
// Service layer for managing lobby presence, fully DB-driven.
// No raw SQL here; delegates all reads/writes to app/db/presence.php.
// -----------------------------------------------------------------------------

require_once __DIR__ . '/../db/presence.php';

class PresenceService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Mark a user as online in the lobby.
     * Returns TRUE only if the user was previously offline or missing.
     */
    public function markOnline(int $userId, string $username): bool
    {
        $before = db_get_user_presence($this->pdo, $userId);
        db_upsert_presence($this->pdo, $userId, $username, 'online');
        $after = db_get_user_presence($this->pdo, $userId);

        // only return true if they just transitioned into online
        return !$before || $before['status'] !== 'online';
    }

    /**
     * Mark a user as in_game (actively playing).
     * Returns TRUE only if the user was not previously in_game.
     */
    public function markInGame(int $userId, string $username): bool
    {
        $before = db_get_user_presence($this->pdo, $userId);
        db_upsert_presence($this->pdo, $userId, $username, 'in_game');
        $after = db_get_user_presence($this->pdo, $userId);

        // only return true if they just transitioned into in_game
        return !$before || $before['status'] !== 'in_game';
    }

    /**
     * Mark a user as offline or remove them completely.
     * Returns TRUE only if they were previously online.
     */
    public function markOffline(int $userId): bool
    {
        $before = db_get_user_presence($this->pdo, $userId);
        db_remove_presence($this->pdo, $userId);
        return $before && $before['status'] === 'online';
    }

    /** Refresh heartbeat without changing status. */
    public function updateHeartbeat(int $userId): bool
    {
        return db_update_last_seen($this->pdo, $userId);
    }

    /** Fetch all currently online users. */
    public function getOnlineUsers(): array
    {
        return db_get_online_users($this->pdo);
    }
}
