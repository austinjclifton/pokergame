<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/users.php';
require_once __DIR__ . '/../db/challenges.php';
require_once __DIR__ . '/../db/tables.php';
require_once __DIR__ . '/../db/table_seats.php';
require_once __DIR__ . '/../db/games.php';
require_once __DIR__ . '/AuditService.php';

final class ChallengeService {
    public function __construct(private PDO $pdo) {}

    /**
     * Create a pending challenge from $fromUserId → $toUsername
     * @return array{ok:bool,message?:string,challenge_id?:int}
     */
    public function send(int $fromUserId, string $toUsername): array {
        $target = db_get_user_by_username($this->pdo, $toUsername);
        if (!$target) {
            return ['ok' => false, 'message' => 'Target user not found'];
        }

        $toUserId = (int)$target['id'];
        if ($toUserId === $fromUserId) {
            return ['ok' => false, 'message' => 'Cannot challenge yourself'];
        }

        if (db_challenge_pending_exists($this->pdo, $fromUserId, $toUserId)) {
            return ['ok' => false, 'message' => 'Challenge already pending'];
        }

        // Check if user already has a pending challenge sent to anyone
        if (db_user_has_pending_challenge_sent($this->pdo, $fromUserId)) {
            return ['ok' => false, 'message' => 'You already have a pending challenge. Please wait for a response or cancel it before sending another challenge.'];
        }

        if (db_reverse_challenge_pending_exists($this->pdo, $fromUserId, $toUserId)) {
            return ['ok' => false, 'message' => 'This player has already challenged you. Please respond to their challenge first.'];
        }

        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        
        AuditService::safeLog($this->pdo, [
            'user_id' => $fromUserId,
            'action' => 'challenge.create',
            'entity_type' => 'challenge',
            'entity_id' => $challengeId,
            'details' => [
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUserId,
                'to_username' => $toUsername,
            ],
            'channel' => 'websocket',
            'status' => 'success',
            'severity' => 'info',
        ], 'ChallengeService');
        
        // Note: WebSocket notifications are handled by the WebSocket handler (LobbySocket.php)
        // when challenges are sent via WebSocket. REST API challenges don't trigger WebSocket
        // notifications (clients using REST API should poll or handle updates differently).
        return ['ok' => true, 'challenge_id' => $challengeId];
    }

    public function accept(int $challengeId, int $acceptingUserId): array {
        $ch = db_get_challenge_for_accept($this->pdo, $challengeId);
        if (!$ch) return ['ok' => false, 'message' => 'Challenge not found'];
        if ($ch['status'] !== 'pending') return ['ok' => false, 'message' => 'Challenge is not pending'];
        if ((int)$ch['to_user_id'] !== $acceptingUserId) {
            return ['ok' => false, 'message' => 'Not authorized to accept this challenge'];
        }

        $fromUserId = (int)$ch['from_user_id'];
        $toUserId = (int)$ch['to_user_id'];

        // Create a table for the two players
        $fromUsername = db_get_username_by_id($this->pdo, $fromUserId) ?? "User#$fromUserId";
        $toUsername = db_get_username_by_id($this->pdo, $toUserId) ?? "User#$toUserId";
        $tableName = "{$fromUsername} vs {$toUsername}";
        
        // Default table settings: 2 seats, 10/20 blinds
        $tableId = db_create_table($this->pdo, $tableName, 2, 10, 20, 0);
        if (!$tableId) {
            return ['ok' => false, 'message' => 'Failed to create game table'];
        }

        $startedTransaction = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTransaction = true;
        }
        
        try {
            // Seat both players
            $seat1 = db_seat_player($this->pdo, $tableId, 1, $fromUserId);
            $seat2 = db_seat_player($this->pdo, $tableId, 2, $toUserId);
            
            if (!$seat1 || !$seat2) {
                if ($startedTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                // Cleanup: mark table as closed if seating failed
                db_update_table_status($this->pdo, $tableId, 'CLOSED');
                return ['ok' => false, 'message' => 'Failed to seat players'];
            }

            // Create game record (dealer, blinds will be set when hand starts)
            // For now, use seat 1 as dealer, seat 1 as SB, seat 2 as BB
            // These will be properly set when startHand() is called
            $deckSeed = mt_rand();
            $gameId = db_create_game($this->pdo, $tableId, 1, 1, 2, $deckSeed);
            
            if (!$gameId) {
                if ($startedTransaction && $this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                db_update_table_status($this->pdo, $tableId, 'CLOSED');
                return ['ok' => false, 'message' => 'Failed to create game record'];
            }

            // Mark challenge as accepted and attach game
            db_mark_challenge_status($this->pdo, $challengeId, 'accepted');
            $stmt = $this->pdo->prepare("UPDATE game_challenges SET game_id = ? WHERE id = ?");
            $stmt->execute([$gameId, $challengeId]);
            
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            db_update_table_status($this->pdo, $tableId, 'CLOSED');
            error_log("[ChallengeService] Error accepting challenge: " . $e->getMessage());
            return ['ok' => false, 'message' => 'Failed to create game: ' . $e->getMessage()];
        }

        AuditService::safeLog($this->pdo, [
            'user_id' => $acceptingUserId,
            'action' => 'challenge.accept',
            'entity_type' => 'challenge',
            'entity_id' => $challengeId,
            'details' => [
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUserId,
                'table_id' => $tableId,
            ],
            'channel' => 'websocket',
            'status' => 'success',
            'severity' => 'info',
        ], 'ChallengeService');

        // Return table_id and game_id so clients can redirect
        return ['ok' => true, 'table_id' => $tableId, 'game_id' => $gameId];
    }

    public function decline(int $challengeId, int $decliningUserId): array {
        $ch = db_get_challenge_for_accept($this->pdo, $challengeId);
        if (!$ch) return ['ok' => false, 'message' => 'Challenge not found'];
        if ($ch['status'] !== 'pending') return ['ok' => false, 'message' => 'Challenge is not pending'];
        if ((int)$ch['to_user_id'] !== $decliningUserId) {
            return ['ok' => false, 'message' => 'Not authorized to decline this challenge'];
        }

        db_mark_challenge_status($this->pdo, $challengeId, 'declined');
        
        AuditService::safeLog($this->pdo, [
            'user_id' => $decliningUserId,
            'action' => 'challenge.decline',
            'entity_type' => 'challenge',
            'entity_id' => $challengeId,
            'details' => [
                'from_user_id' => (int)$ch['from_user_id'],
                'to_user_id' => (int)$ch['to_user_id'],
            ],
            'channel' => 'websocket',
            'status' => 'success',
            'severity' => 'info',
        ], 'ChallengeService');
        
        return ['ok' => true];
    }

    public function cancel(int $challengeId, int $cancellingUserId): array {
        $ch = db_get_challenge_for_accept($this->pdo, $challengeId);
        if (!$ch) return ['ok' => false, 'message' => 'Challenge not found'];
        if ($ch['status'] !== 'pending') return ['ok' => false, 'message' => 'Challenge is not pending'];
        if ((int)$ch['from_user_id'] !== $cancellingUserId) {
            return ['ok' => false, 'message' => 'Not authorized to cancel this challenge'];
        }

        db_mark_challenge_status($this->pdo, $challengeId, 'declined');
        
        AuditService::safeLog($this->pdo, [
            'user_id' => $cancellingUserId,
            'action' => 'challenge.cancel',
            'entity_type' => 'challenge',
            'entity_id' => $challengeId,
            'details' => [
                'from_user_id' => (int)$ch['from_user_id'],
                'to_user_id' => (int)$ch['to_user_id'],
            ],
            'channel' => 'websocket',
            'status' => 'success',
            'severity' => 'info',
        ], 'ChallengeService');
        
        return ['ok' => true];
    }
}
