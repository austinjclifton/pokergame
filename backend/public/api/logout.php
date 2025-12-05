<?php
// backend/public/api/logout.php
// -----------------------------------------------------------------------------
// HTTP controller for user logout.
//
// Responsibilities:
//  - Handles preflight (OPTIONS) requests
//  - Calls AuthService::auth_logout_user()
//  - Returns simple JSON response
//
// Business logic (revoking session, marking user offline)
// lives in app/services/AuthService.php.
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// Set allowed methods for this endpoint
setAllowedMethods('POST, OPTIONS');

// require_once __DIR__ . '/../app/services/AuthService.php';

// Apply rate limiting (100 requests/minute per IP)
apply_rate_limiting(null, 100, 200, 60);

// Validate CSRF token for logout
try {
    // Get current session for CSRF validation
    $user = requireSession($pdo);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }
    
    // Parse request body for CSRF token
    // Check for test input first (for PHPUnit testing)
    $rawInput = $GLOBALS['_TEST_INPUT'] ?? file_get_contents('php://input');
    $payloadValidation = validate_json_payload_size($rawInput, 1024); // 1KB max for logout
    if (!$payloadValidation['valid']) {
        http_response_code(413);
        echo json_encode(['ok' => false, 'error' => $payloadValidation['error']]);
        exit;
    }
    
    $data = json_decode($rawInput, true) ?? [];
    $token = $data['token'] ?? '';
    
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
        echo json_encode(['ok' => false, 'error' => $errorMsg]);
        exit;
    }
    
    // Apply user-based rate limiting after authentication
    apply_rate_limiting($user['user_id'], 100, 200, 60);
    
    $revoked = auth_logout_user($pdo);

    if ($revoked) {
        echo json_encode(['ok' => true, 'message' => 'Session terminated']);
    } else {
        // logout even if no session present still returns ok
        echo json_encode(['ok' => true, 'message' => 'No active session']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
