<?php
declare(strict_types=1);

require_once __DIR__ . '/PresenceService.php';
require_once __DIR__ . '/../db/presence.php';
require_once __DIR__ . '/../db/table_seats.php';
require_once __DIR__ . '/../../lib/security.php';
require_once __DIR__ . '/../../lib/WebSocketLog.php';

final class SocketPresenceService
{
    private PresenceService $presenceService;

    public function __construct(private PDO $pdo)
    {
        $this->presenceService = new PresenceService($pdo);
    }

    /** @return array{id:int,username:string,status:string,active_table_id:?int} */
    public function syncLobbyConnection(int $userId, string $username): array
    {
        $activeTableId = $this->findActiveTableId($userId);
        $currentStatus = db_get_user_status($this->pdo, $userId) ?? 'online';

        if ($currentStatus === 'in_game' && $activeTableId !== null) {
            $this->presenceService->markInGame($userId, $username);

            return $this->buildPayload($userId, $username, 'in_game', $activeTableId);
        }

        $this->presenceService->markOnline($userId, $username);

        return $this->buildPayload($userId, $username, 'online', $activeTableId);
    }

    /** @return array{id:int,username:string,status:string,active_table_id:int} */
    public function syncGameConnection(int $userId, string $username, int $tableId): array
    {
        $this->presenceService->markInGame($userId, $username);

        return $this->buildPayload($userId, $username, 'in_game', $tableId);
    }

    /** @return array{id:int,username:string,status:string,active_table_id:?int}|null */
    public function syncOnlineAfterGameDisconnect(int $userId, string $username, bool $hasOtherActiveGames): ?array
    {
        if ($hasOtherActiveGames) {
            return null;
        }

        return $this->syncOnlinePresence($userId, $username);
    }

    /** @return array{id:int,username:string,status:string,active_table_id:?int} */
    public function syncOnlinePresence(int $userId, string $username): array
    {
        $this->presenceService->markOnline($userId, $username);

        return $this->buildPayload(
            $userId,
            $username,
            'online',
            $this->findActiveTableId($userId)
        );
    }

    /** @return list<array{id:int,username:string,status:string,active_table_id:?int}> */
    public function listVisibleUsers(): array
    {
        $users = db_get_visible_presence_users($this->pdo);
        $payloads = [];

        foreach ($users as $user) {
            $payloads[] = $this->buildPayload(
                (int) $user['user_id'],
                (string) $user['user_username'],
                (string) ($user['status'] ?? 'online'),
                $this->findActiveTableId((int) $user['user_id'])
            );
        }

        return $payloads;
    }

    public function updateHeartbeat(int $userId): bool
    {
        return $this->presenceService->updateHeartbeat($userId);
    }

    public function markOffline(int $userId): bool
    {
        return $this->presenceService->markOffline($userId);
    }

    private function findActiveTableId(int $userId): ?int
    {
        try {
            return db_find_active_table_for_user($this->pdo, $userId);
        } catch (\Throwable $e) {
            WebSocketLog::warn('SocketPresenceService', 'Active table lookup failed for user ' . $userId . ': ' . $e->getMessage());
            return null;
        }
    }

    /** @return array{id:int,username:string,status:string,active_table_id:?int} */
    private function buildPayload(int $userId, string $username, string $status, ?int $activeTableId): array
    {
        return [
            'id' => $userId,
            'username' => escape_html($username),
            'status' => $status,
            'active_table_id' => $activeTableId,
        ];
    }
}