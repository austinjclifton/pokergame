<?php
// backend/public/api/challenges/pending.php
// Get pending challenges for the current user (simplified format)

require_once __DIR__ . '/../../../bootstrap.php';

require_once __DIR__ . '/../../../app/services/AuthService.php';
require_once __DIR__ . '/../../../app/db/challenges.php';
require_once __DIR__ . '/../../../app/db/users.php';

// Apply rate limiting
apply_rate_limiting(null, 100, 200, 60);

try {
    // Get current user
    $user = auth_require_session($pdo);
    
    // Re-apply with user ID for user-based limiting
    apply_rate_limiting($user['id'], 100, 200, 60);
    $userId = (int)$user['id'];

    // Get pending challenges
    $pendingChallenges = db_get_pending_challenges_by_user($pdo, $userId);
    
    // Separate into outgoing and incoming
    $outgoing = [];
    $incoming = [];
    
    foreach ($pendingChallenges as $challenge) {
        $challengeData = [
            'id' => $challenge['id'],
            'opponent' => escape_html($challenge['from_user_id'] === $userId 
                ? $challenge['to_username'] 
                : $challenge['from_username']),
            'opponent_id' => $challenge['from_user_id'] === $userId 
                ? (int)$challenge['to_user_id'] 
                : (int)$challenge['from_user_id'],
            'created_at' => $challenge['created_at'],
        ];
        
        if ($challenge['from_user_id'] === $userId) {
            $outgoing[] = $challengeData;
        } else {
            $incoming[] = $challengeData;
        }
    }
    
    // Log for debugging
    if (!empty($outgoing) || !empty($incoming)) {
        $outgoingInfo = !empty($outgoing) 
            ? "outgoing: " . implode(', ', array_column($outgoing, 'opponent'))
            : '';
        $incomingInfo = !empty($incoming)
            ? "incoming: " . implode(', ', array_column($incoming, 'opponent'))
            : '';
        error_log("[INFO] Pending challenges restored for user {$userId} → {$outgoingInfo} {$incomingInfo}");
    }
    
    echo json_encode([
        'ok' => true,
        'outgoing' => $outgoing,
        'incoming' => $incoming,
    ]);

} catch (Exception $e) {
    error_log('[challenges/pending.php] ' . $e->getMessage());
    http_response_code(500);
    $response = [
        'ok' => false,
        'error' => 'Failed to fetch pending challenges',
    ];
    
    // Only expose error details in debug mode
    if (debug_enabled()) {
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
}
?>

