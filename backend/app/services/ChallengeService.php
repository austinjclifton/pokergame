<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/users.php';
require_once __DIR__ . '/../db/challenges.php';
require_once __DIR__ . '/AuditService.php';
// Games functionality not yet implemented
// require_once __DIR__ . '/../db/games.php';

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

        if (db_reverse_challenge_pending_exists($this->pdo, $fromUserId, $toUserId)) {
            return ['ok' => false, 'message' => 'This player has already challenged you. Please respond to their challenge first.'];
        }

        $challengeId = db_insert_challenge($this->pdo, $fromUserId, $toUserId);
        
        try {
            log_audit_event($this->pdo, [
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
            ]);
        } catch (Throwable $e) {
            error_log('[ChallengeService] Audit logging failed: ' . $e->getMessage());
        }
        
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

        // Games functionality not yet implemented
        // When games are implemented, uncomment the following:
        // $gameId = db_create_game($this->pdo, (int)$ch['from_user_id'], (int)$ch['to_user_id']);
        // db_attach_game_to_challenge($this->pdo, $challengeId, $gameId);
        
        db_mark_challenge_status($this->pdo, $challengeId, 'accepted');

        try {
            log_audit_event($this->pdo, [
                'user_id' => $acceptingUserId,
                'action' => 'challenge.accept',
                'entity_type' => 'challenge',
                'entity_id' => $challengeId,
                'details' => [
                    'from_user_id' => (int)$ch['from_user_id'],
                    'to_user_id' => (int)$ch['to_user_id'],
                ],
                'channel' => 'websocket',
                'status' => 'success',
                'severity' => 'info',
            ]);
        } catch (Throwable $e) {
            error_log('[ChallengeService] Audit logging failed: ' . $e->getMessage());
        }

        // Note: WebSocket notifications are handled by the WebSocket handler (LobbySocket.php)
        // when challenges are accepted via WebSocket. REST API acceptances don't trigger
        // WebSocket notifications. When games are implemented, game.start events will also
        // be handled by the WebSocket handler.
        return ['ok' => true];
    }

    public function decline(int $challengeId, int $decliningUserId): array {
        $ch = db_get_challenge_for_accept($this->pdo, $challengeId);
        if (!$ch) return ['ok' => false, 'message' => 'Challenge not found'];
        if ($ch['status'] !== 'pending') return ['ok' => false, 'message' => 'Challenge is not pending'];
        if ((int)$ch['to_user_id'] !== $decliningUserId) {
            return ['ok' => false, 'message' => 'Not authorized to decline this challenge'];
        }

        db_mark_challenge_status($this->pdo, $challengeId, 'declined');
        
        try {
            log_audit_event($this->pdo, [
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
            ]);
        } catch (Throwable $e) {
            error_log('[ChallengeService] Audit logging failed: ' . $e->getMessage());
        }
        
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
        
        try {
            log_audit_event($this->pdo, [
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
            ]);
        } catch (Throwable $e) {
            error_log('[ChallengeService] Audit logging failed: ' . $e->getMessage());
        }
        
        return ['ok' => true];
    }
}
