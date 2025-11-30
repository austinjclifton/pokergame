<?php
// backend/public/api/nonce.php
// -----------------------------------------------------------------------------
// HTTP controller for issuing secure, short-lived CSRF nonces.
//
// Flow:
//   - (1) Handle CORS / OPTIONS
//   - (2) Call NonceService::nonce_issue()
//   - (3) Return nonce + expiry as JSON
//
// Security:
//   - Nonces bound to current session_id (real or temp)
//   - Each nonce stored in DB for later validation
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// Set allowed methods for this endpoint
setAllowedMethods('GET, OPTIONS');

require_once __DIR__ . '/../app/services/NonceService.php';

// Apply rate limiting (100 requests/minute per IP)
apply_rate_limiting(null, 100, 200, 60);

try {
    $result = nonce_issue($pdo);
    echo json_encode(['ok' => true] + $result);
} catch (Throwable $e) {
    error_log('[nonce.php] ' . $e->getMessage());
    http_response_code(500);
    $response = ['ok' => false, 'message' => 'Server error'];
    
    // Only expose error details in debug mode
    if (debug_enabled()) {
        $response['detail'] = $e->getMessage();
    }
    
    echo json_encode($response);
}
