<?php
// backend/public/api/me.php
// -----------------------------------------------------------------------------
// Returns the currently logged-in user if the session cookie is valid.
// Uses requireSession($pdo) from lib/session.php.
//
// Called by AuthGate.jsx on frontend load to determine whether a user is
// authenticated. Returns JSON: { ok: true, user: {...} } or 401 Unauthorized.
// -----------------------------------------------------------------------------

require_once __DIR__ . '/../bootstrap.php';

// Set allowed methods for this endpoints
setAllowedMethods('GET, OPTIONS');

// Apply rate limiting (100 requests/minute per IP, 200/minute per user after auth)
apply_rate_limiting(null, 100, 200, 60);

try {
    $user = requireSession($pdo);

    if ($user) {
        apply_rate_limiting($user['user_id'], 100, 200, 60);
    }

    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'message' => 'No active session or invalid session cookie'
        ]);
        exit;
    }

    $normalized = [
        'id'          => (int)$user['user_id'],
        'username'    => escape_html($user['username']),
        'email'       => $user['email'] ?? null,
        'session_id'  => $user['session_id'] ?? null,
    ];

    echo json_encode([
        'ok' => true,
        'user' => $normalized,
    ]);

} catch (Throwable $e) {
    error_log('[me.php] ' . $e->getMessage());
    http_response_code(500);
    $response = [
        'ok' => false,
        'message' => 'Server error',
    ];

    if (debug_enabled()) {
        $response['detail'] = $e->getMessage();
    }

    echo json_encode($response);
}
