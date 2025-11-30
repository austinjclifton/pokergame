<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_once __DIR__ . '/../../app/services/ChallengeService.php';

// Apply rate limiting (100 requests/minute per IP, 200/minute per user after auth)
apply_rate_limiting(null, 100, 200, 60);

$user = requireSession($pdo);
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Not logged in']);
    exit;
}

// Re-apply with user ID for user-based limiting
apply_rate_limiting($user['user_id'], 100, 200, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$rawInput = file_get_contents('php://input');

// Validate payload size (5KB max)
$payloadValidation = validate_json_payload_size($rawInput, 5120);
if (!$payloadValidation['valid']) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'message' => $payloadValidation['error']]);
    exit;
}

$input = json_decode($rawInput, true) ?? [];
$challengeId = isset($input['challenge_id']) ? (int)$input['challenge_id'] : 0;
$token = $input['token'] ?? '';

if ($challengeId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Missing challenge_id']);
    exit;
}

// Validate CSRF token (bound to current session)
try {
    validate_csrf_token($pdo, $token, $user['session_id']);
} catch (RuntimeException $e) {
    $errorMsg = match($e->getMessage()) {
        'CSRF_TOKEN_MISSING' => 'Missing CSRF token',
        'CSRF_TOKEN_INVALID' => 'Invalid CSRF token',
        'CSRF_TOKEN_EXPIRED' => 'CSRF token expired',
        'CSRF_TOKEN_ALREADY_USED' => 'CSRF token already used',
        'CSRF_TOKEN_SESSION_MISMATCH' => 'CSRF token does not match session',
        'CSRF_TOKEN_SESSION_INVALID' => 'Session invalid',
        default => 'Invalid CSRF token'
    };
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => $errorMsg]);
    exit;
}

$service = new ChallengeService($pdo);
try {
    $res = $service->accept($challengeId, (int)$user['user_id']);
    echo json_encode($res);
} catch (Throwable $e) {
    error_log('[challenge_accept.php] ' . $e->getMessage());
    http_response_code(500);
    $response = ['ok' => false, 'message' => 'Server error'];
    
    // Only expose error details in debug mode
    if (debug_enabled()) {
        $response['detail'] = $e->getMessage();
    }
    
    echo json_encode($response);
}
