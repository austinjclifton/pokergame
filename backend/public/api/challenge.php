<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';


// require_once __DIR__ . '/../app/services/ChallengeService.php';

// Apply rate limiting (100 requests/minute per IP)
apply_rate_limiting(null, 100, 200, 60);

// Auth via your session helper (returns ['id','username','email','session_id'])
$user = requireSession($pdo);
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Not logged in']);
    exit;
}

// Apply user-based rate limiting after authentication (200 requests/minute per user)
apply_rate_limiting($user['user_id'], 100, 200, 60);

// Only POST is supported here (send challenge)
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
$target = isset($input['target']) ? trim($input['target']) : '';
$token = $input['token'] ?? '';

if ($target === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Missing target username']);
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
    echo json_encode(['ok' => false, 'message' => $errorMsg]);
    exit;
}

$service = new ChallengeService($pdo);
try {
    $res = $service->send((int) $user['user_id'], $target);
    // business "false" is still 200 for predictable client handling
    echo json_encode($res);
} catch (Throwable $e) {
    error_log('[challenge.php] ' . $e->getMessage());
    http_response_code(500);
    $response = ['ok' => false, 'message' => 'Server error'];

    // Only expose error details in debug mode
    if (debug_enabled()) {
        $response['detail'] = $e->getMessage();
    }

    echo json_encode($response);
}
