<?php
// backend/public/api/challenges.php
// Get pending challenges for the current user

require_once __DIR__ . '/../bootstrap.php';

require_once __DIR__ . '/../app/services/AuthService.php';
require_once __DIR__ . '/../app/db/challenges.php';
require_once __DIR__ . '/../app/db/users.php';

// Apply rate limiting (100 requests/minute per IP, 200/minute per user after auth)
apply_rate_limiting(null, 100, 200, 60);

try {
    // Get current user
    $user = auth_require_session($pdo);
    
    // Re-apply with user ID for user-based limiting
    apply_rate_limiting($user['id'], 100, 200, 60);
    $userId = (int)$user['id'];

    // Get pending challenges (both sent and received)
    $stmt = $pdo->prepare("
        SELECT 
            gc.id,
            gc.from_user_id,
            gc.to_user_id,
            gc.status,
            gc.created_at,
            from_user.username as from_username,
            to_user.username as to_username
        FROM game_challenges gc
        JOIN users from_user ON from_user.id = gc.from_user_id
        JOIN users to_user ON to_user.id = gc.to_user_id
        WHERE (gc.from_user_id = ? OR gc.to_user_id = ?)
        AND gc.status = 'pending'
        ORDER BY gc.created_at DESC
    ");
    
    $stmt->execute([$userId, $userId]);
    $challenges = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format the response (escape usernames for XSS prevention)
    $formattedChallenges = array_map(function($challenge) use ($userId) {
        return [
            'id' => (int)$challenge['id'],
            'from_user_id' => (int)$challenge['from_user_id'],
            'to_user_id' => (int)$challenge['to_user_id'],
            'from_username' => escape_html($challenge['from_username']),
            'to_username' => escape_html($challenge['to_username']),
            'status' => $challenge['status'],
            'created_at' => $challenge['created_at'],
            'is_from_me' => $challenge['from_user_id'] == $userId,
            'is_to_me' => $challenge['to_user_id'] == $userId
        ];
    }, $challenges);

    echo json_encode([
        'ok' => true,
        'challenges' => $formattedChallenges
    ]);

} catch (Exception $e) {
    error_log('[challenges.php] ' . $e->getMessage());
    http_response_code(500);
    $response = [
        'ok' => false,
        'error' => 'Failed to fetch challenges',
    ];
    
    // Only expose error details in debug mode
    if (debug_enabled()) {
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
}
?>
