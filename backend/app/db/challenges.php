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
