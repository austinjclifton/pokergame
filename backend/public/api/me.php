<?php
// backend/public/api/me.php
// -----------------------------------------------------------------------------
// Returns the currently logged-in user if the session cookie is valid.
// Uses requireSession($pdo) from lib/session.php.
//
// Called by AuthGate.jsx on frontend load to determine whether a user is
// authenticated. Returns JSON: { ok: true, user: {...} } or 401 Unauthorized.
// -----------------------------------------------------------------------------

require_once __DIR__ . '/../../config/security.php';

// Set allowed methods for this endpoint
setAllowedMethods('GET, OPTIONS');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/session.php';
require_once __DIR__ . '/../../lib/security.php';

// Apply rate limiting (100 requests/minute per IP, 200/minute per user after auth)
apply_rate_limiting(null, 100, 200, 60);

try {
    // Validate session using your helper (reads session_id cookie, checks DB)
    $user = requireSession($pdo);
    
    if ($user) {
        // Re-apply with user ID for user-based limiting
        apply_rate_limiting($user['user_id'], 100, 200, 60);
    }

    if (!$user) {
        // No valid session found
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'message' => 'No active session or invalid session cookie'
        ]);
        exit;
    }

    // Session valid — return minimal user info (escape username for XSS prevention)
    $user['username'] = escape_html($user['username']);
    echo json_encode([
        'ok' => true,
        'user' => $user,
    ]);
} catch (Throwable $e) {
    // Catch any internal error (e.g., DB unavailable)
    error_log('[me.php] ' . $e->getMessage());
    http_response_code(500);
    $response = [
        'ok' => false,
        'message' => 'Server error',
    ];
    
    // Only expose error details in debug mode
    if (debug_enabled()) {
        $response['detail'] = $e->getMessage();
    }
    
    echo json_encode($response);
}
