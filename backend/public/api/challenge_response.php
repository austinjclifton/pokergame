<?php
// backend/public/api/challenge_response.php
// Accept or decline a challenge

require_once dirname(__DIR__, 2) . '/bootstrap.php';


// require_once __DIR__ . '/../app/services/AuthService.php';
// require_once __DIR__ . '/../app/services/ChallengeService.php';

// Apply rate limiting (100 requests/minute per IP, 200/minute per user after auth)
apply_rate_limiting(null, 100, 200, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Get current user
    $user = auth_require_session($pdo);
    $userId = (int) $user['id'];

    // Re-apply with user ID for user-based limiting
    apply_rate_limiting($userId, 100, 200, 60);

    // Get request data
    $rawInput = file_get_contents('php://input');

    // Validate payload size (5KB max)
    $payloadValidation = validate_json_payload_size($rawInput, 5120);
    if (!$payloadValidation['valid']) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => $payloadValidation['error']]);
        exit;
    }

    $input = json_decode($rawInput, true);
    if ($input === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
        exit;
    }

    $challengeId = (int) ($input['challenge_id'] ?? 0);
    $action = trim($input['action'] ?? '');
    $token = $input['token'] ?? '';

    if ($challengeId <= 0 || !in_array($action, ['accept', 'decline'])) {
        echo json_encode([
            'ok' => false,
            'error' => 'Invalid challenge_id or action'
        ]);
        exit;
    }

    // Validate CSRF token (bound to current session)
    try {
        validate_csrf_token($pdo, $token, $user['session_id']);
    } catch (RuntimeException $e) {
        $errorMsg = match ($e->getMessage()) {
            'CSRF_TOKEN_MISSING' => 'Missing CSRF token',
            'CSRF_TOKEN_INVALID' => 'Invalid CSRF token',
            'CSRF_TOKEN_EXPIRED' => 'CSRF token expired',
            'CSRF_TOKEN_ALREADY_USED' => 'CSRF token already used',
            'CSRF_TOKEN_SESSION_MISMATCH' => 'CSRF token does not match session',
            'CSRF_TOKEN_SESSION_INVALID' => 'Session invalid',
            default => 'Invalid CSRF token'
        };
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => $errorMsg]);
        exit;
    }

    // Use ChallengeService to handle the response
    $challengeService = new ChallengeService($pdo);

    if ($action === 'accept') {
        $result = $challengeService->accept($challengeId, $userId);
    } else {
        $result = $challengeService->decline($challengeId, $userId);
    }

    if ($result['ok']) {
        echo json_encode([
            'ok' => true,
            'message' => 'Challenge ' . $action . 'ed successfully',
            'game_id' => $result['game_id'] ?? null
        ]);
    } else {
        echo json_encode([
            'ok' => false,
            'error' => $result['message']
        ]);
    }

} catch (Exception $e) {
    error_log('[challenge_response.php] ' . $e->getMessage());
    http_response_code(500);
    $response = [
        'ok' => false,
        'error' => 'Failed to process challenge response',
    ];

    // Only expose error details in debug mode
    if (debug_enabled()) {
        $response['message'] = $e->getMessage();
    }

    echo json_encode($response);
}
?>