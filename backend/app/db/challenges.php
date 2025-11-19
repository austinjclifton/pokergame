<?php
// backend/app/db/challenges.php
declare(strict_types=1);

/**
 * DB helpers for game_challenges table.
 * Schema columns (per your DDL):
 *  id, from_user_id, to_user_id, status('pending','accepted','declined','expired'),
 *  game_id, created_at, responded_at, expires_at
 */

function db_challenge_pending_exists(PDO $pdo, int $fromUserId, int $toUserId): bool {
    $sql = "SELECT id FROM game_challenges
            WHERE from_user_id = ? AND to_user_id = ? AND status = 'pending'
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$fromUserId, $toUserId]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function db_reverse_challenge_pending_exists(PDO $pdo, int $fromUserId, int $toUserId): bool {
    $sql = "SELECT id FROM game_challenges
            WHERE from_user_id = ? AND to_user_id = ? AND status = 'pending'
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$toUserId, $fromUserId]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Check if a user has any pending challenge they sent (to any target)
 * @param PDO $pdo
 * @param int $fromUserId
 * @return bool
 */
function db_user_has_pending_challenge_sent(PDO $pdo, int $fromUserId): bool {
    $sql = "SELECT id FROM game_challenges
            WHERE from_user_id = ? AND status = 'pending'
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$fromUserId]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function db_insert_challenge(PDO $pdo, int $fromUserId, int $toUserId): int {
    $sql = "INSERT INTO game_challenges (from_user_id, to_user_id, created_at)
            VALUES (?, ?, NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$fromUserId, $toUserId]);
    return (int)$pdo->lastInsertId();
}

function db_get_challenge_for_accept(PDO $pdo, int $challengeId): ?array {
    $sql = "SELECT id, from_user_id, to_user_id, status, game_id
            FROM game_challenges WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$challengeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function db_mark_challenge_status(PDO $pdo, int $challengeId, string $status): void {
    $sql = "UPDATE game_challenges
            SET status = ?, responded_at = NOW()
            WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status, $challengeId]);
}

function db_attach_game_to_challenge(PDO $pdo, int $challengeId, int $gameId): void {
    $sql = "UPDATE game_challenges SET game_id = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$gameId, $challengeId]);
}

/**
 * Get all pending challenges for a user (both sent and received)
 * @param PDO $pdo
 * @param int $userId
 * @return array<array{id: int, from_user_id: int, to_user_id: int, from_username: string, to_username: string, created_at: string}>
 */
function db_get_pending_challenges_by_user(PDO $pdo, int $userId): array {
    require_once __DIR__ . '/users.php';
    
    $sql = "SELECT 
                gc.id,
                gc.from_user_id,
                gc.to_user_id,
                gc.created_at
            FROM game_challenges gc
            WHERE (gc.from_user_id = ? OR gc.to_user_id = ?)
            AND gc.status = 'pending'
            ORDER BY gc.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $userId]);
    $challenges = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Enrich with usernames
    $result = [];
    foreach ($challenges as $challenge) {
        $fromUserId = (int)$challenge['from_user_id'];
        $toUserId = (int)$challenge['to_user_id'];
        
        $fromUser = db_get_user_by_id($pdo, $fromUserId);
        $toUser = db_get_user_by_id($pdo, $toUserId);
        
        $result[] = [
            'id' => (int)$challenge['id'],
            'from_user_id' => $fromUserId,
            'to_user_id' => $toUserId,
            'from_username' => $fromUser['username'] ?? "User#{$fromUserId}",
            'to_username' => $toUser['username'] ?? "User#{$toUserId}",
            'created_at' => $challenge['created_at'],
        ];
    }
    
    return $result;
}
