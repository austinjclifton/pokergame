<?php
// backend/public/api/login.php
// -----------------------------------------------------------------------------
// Login Controller
// Responsibilities:
//   • Parse JSON input and validate credentials
//   • Delegate to AuthService (handles session + presence logic)
//   • Return structured JSON and consistent HTTP codes
// -----------------------------------------------------------------------------
// Notes:
//   • Works with cookie-based session_id (set by createSession)
//   • Compatible with WebSocket token issuance (/api/ws_token.php)
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/security.php'; // Includes debug_enabled()
require_once __DIR__ . '/../../app/services/AuthService.php';
require_once __DIR__ . '/../../app/services/AuditService.php';

// Allow only POST + OPTIONS (CORS-friendly)
setAllowedMethods('POST, OPTIONS');

// Apply strict rate limiting for authentication endpoint (5 requests/minute per IP)
apply_auth_rate_limiting(get_client_ip(), 5, 60);

header('Content-Type: application/json; charset=utf-8');

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------
function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// -----------------------------------------------------------------------------
// Handle OPTIONS preflight (for Postman / browsers)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// -----------------------------------------------------------------------------
// Main Logic
// -----------------------------------------------------------------------------
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }

    // Read + validate input
    $rawInput = file_get_contents('php://input');
    
    // Validate payload size (5KB max for login)
    $payloadValidation = validate_json_payload_size($rawInput, 5120);
    if (!$payloadValidation['valid']) {
        json_out(['ok' => false, 'error' => $payloadValidation['error']], 413);
    }
    
    $input = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if ($username === '' || $password === '') {
        json_out(['ok' => false, 'error' => 'missing_credentials'], 400);
    }

    // Call AuthService (handles session + cookie + presence)
    $result = auth_login_user($pdo, $username, $password);

    // Escape username in response for XSS prevention
    if (isset($result['user']['username'])) {
        $result['user']['username'] = escape_html($result['user']['username']);
    }

    // Success → 200
    json_out($result, 200);

} catch (RuntimeException $e) {
    // Domain-level, expected issues
    switch ($e->getMessage()) {
        case 'INVALID_CREDENTIALS':
            // Audit log: failed login attempt
            try {
                log_audit_event($pdo, [
                    'user_id' => null, // Unknown user
                    'ip_address' => get_client_ip(),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'action' => 'user.login',
                    'details' => [
                        'username_attempted' => $username ?? 'unknown',
                        'reason' => 'invalid_credentials',
                    ],
                    'channel' => 'api',
                    'status' => 'failure',
                    'severity' => 'warn',
                ]);
            } catch (Throwable $auditError) {
                // Don't fail login if audit logging fails
                error_log('[login.php] Audit logging failed: ' . $auditError->getMessage());
            }
            json_out(['ok' => false, 'error' => 'invalid_credentials'], 401);
            break;
        default:
            error_log('[login.php] RuntimeException: ' . $e->getMessage());
            $response = ['ok' => false, 'error' => 'bad_request'];
            if (debug_enabled()) {
                $response['detail'] = $e->getMessage();
            }
            json_out($response, 400);
    }

} catch (JsonException $e) {
    error_log('[login.php] JSON parse error: ' . $e->getMessage());
    $response = ['ok' => false, 'error' => 'invalid_json'];
    if (debug_enabled()) {
        $response['detail'] = $e->getMessage();
    }
    json_out($response, 400);

} catch (Throwable $e) {
    // Hard errors (DB failure, coding bug, etc.)
    error_log('[login] ' . $e->getMessage());
    $payload = ['ok' => false, 'error' => 'server_error'];
    if (debug_enabled()) {
        $payload['detail'] = $e->getMessage();
        $trace = $e->getTrace();
        if (!empty($trace[0]['file'])) {
            $payload['where'] = basename($trace[0]['file']) . ':' . ($trace[0]['line'] ?? '?');
        }
    }
    json_out($payload, 500);
}
