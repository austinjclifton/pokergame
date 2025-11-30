<?php
// backend/public/api/admin/audit.php
// -----------------------------------------------------------------------------
// Admin endpoint for querying audit logs.
// 
// Security considerations:
//   - This endpoint should be protected by admin authentication
//   - Rate limiting should be applied
//   - Consider IP whitelisting for production
//   - PII (IP addresses) should be handled according to privacy policy
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';

require_once __DIR__ . '/../../../app/services/AuthService.php';
require_once __DIR__ . '/../../../app/db/audit_log.php';

// Allow only GET + OPTIONS
setAllowedMethods('GET, OPTIONS');

// Apply rate limiting (stricter for admin endpoints)
apply_auth_rate_limiting(get_client_ip(), 30, 60); // 30 requests per minute

header('Content-Type: application/json; charset=utf-8');

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------
function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// -----------------------------------------------------------------------------
// Handle OPTIONS preflight
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// -----------------------------------------------------------------------------
// Authentication & Authorization
// -----------------------------------------------------------------------------
try {
    // Require valid session
    $user = auth_require_session($pdo);
    
    // TODO: Add admin role check here
    // For now, any authenticated user can access (restrict in production)
    // Example:
    // if (!is_admin($user['id'])) {
    //     json_out(['ok' => false, 'error' => 'forbidden'], 403);
    // }
    
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'UNAUTHORIZED') {
        json_out(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    throw $e;
}

// -----------------------------------------------------------------------------
// Main Logic
// -----------------------------------------------------------------------------
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }
    
    // Parse query parameters
    $filters = [];
    
    if (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
        $filters['user_id'] = (int)$_GET['user_id'];
    }
    
    if (isset($_GET['action']) && is_string($_GET['action'])) {
        $filters['action'] = trim($_GET['action']);
    }
    
    if (isset($_GET['entity_type']) && is_string($_GET['entity_type'])) {
        $filters['entity_type'] = trim($_GET['entity_type']);
    }
    
    if (isset($_GET['entity_id']) && is_numeric($_GET['entity_id'])) {
        $filters['entity_id'] = (int)$_GET['entity_id'];
    }
    
    if (isset($_GET['channel']) && in_array($_GET['channel'], ['api', 'websocket'], true)) {
        $filters['channel'] = $_GET['channel'];
    }
    
    if (isset($_GET['status']) && in_array($_GET['status'], ['success', 'failure', 'error'], true)) {
        $filters['status'] = $_GET['status'];
    }
    
    if (isset($_GET['severity']) && in_array($_GET['severity'], ['info', 'warn', 'error', 'critical'], true)) {
        $filters['severity'] = $_GET['severity'];
    }
    
    // Date range filters
    if (isset($_GET['start_date']) && is_string($_GET['start_date'])) {
        // Validate date format (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)
        $startDate = trim($_GET['start_date']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}(\s\d{2}:\d{2}:\d{2})?$/', $startDate)) {
            $filters['start_date'] = $startDate;
        }
    }
    
    if (isset($_GET['end_date']) && is_string($_GET['end_date'])) {
        $endDate = trim($_GET['end_date']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}(\s\d{2}:\d{2}:\d{2})?$/', $endDate)) {
            $filters['end_date'] = $endDate;
        }
    }
    
    // Pagination
    $limit = 100; // Default limit
    if (isset($_GET['limit']) && is_numeric($_GET['limit'])) {
        $requestedLimit = (int)$_GET['limit'];
        if ($requestedLimit > 0 && $requestedLimit <= 1000) { // Max 1000 per request
            $limit = $requestedLimit;
        }
    }
    $filters['limit'] = $limit;
    
    $offset = 0;
    if (isset($_GET['offset']) && is_numeric($_GET['offset'])) {
        $offset = max(0, (int)$_GET['offset']);
    }
    $filters['offset'] = $offset;
    
    // Query audit logs
    $logs = db_query_audit_logs($pdo, $filters);
    $total = db_count_audit_logs($pdo, $filters);
    
    // Privacy: Redact IP addresses in response (keep only hash)
    // In production, you might want to remove ip_address entirely
    $redactedLogs = array_map(function($log) {
        // Remove or redact sensitive fields in response
        unset($log['ip_address']); // Remove actual IP, keep only hash
        return $log;
    }, $logs);
    
    json_out([
        'ok' => true,
        'logs' => $redactedLogs,
        'pagination' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total,
        ],
        'filters' => $filters,
    ], 200);
    
} catch (Throwable $e) {
    error_log('[admin/audit.php] ' . $e->getMessage());
    $payload = ['ok' => false, 'error' => 'server_error'];
    if (debug_enabled()) {
        $payload['detail'] = $e->getMessage();
    }
    json_out($payload, 500);
}

