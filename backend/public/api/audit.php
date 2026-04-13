<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

const AUDIT_ENDPOINT_DEFAULT_LIMIT = 100;
const AUDIT_ENDPOINT_MAX_LIMIT = 1000;
const AUDIT_ENDPOINT_ADMIN_USERNAME = 'admin';

setAllowedMethods('GET, OPTIONS');
apply_auth_rate_limiting(get_client_ip(), 30, 60);

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $status = 200): void
{
	http_response_code($status);
	echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
	exit;
}

function require_audit_admin(PDO $pdo): void
{
	try {
		$user = auth_require_session($pdo);
	} catch (RuntimeException $e) {
		if ($e->getMessage() === 'UNAUTHORIZED') {
			json_out(['ok' => false, 'error' => 'unauthorized'], 401);
		}

		throw $e;
	}

	if (($user['username'] ?? null) !== AUDIT_ENDPOINT_ADMIN_USERNAME) {
		json_out(['ok' => false, 'error' => 'forbidden'], 403);
	}
}

function build_audit_filters(array $query): array
{
	$filters = [
		'limit' => AUDIT_ENDPOINT_DEFAULT_LIMIT,
		'offset' => 0,
	];

	if (isset($query['user_id']) && is_numeric($query['user_id'])) {
		$filters['user_id'] = (int) $query['user_id'];
	}
	if (isset($query['action']) && is_string($query['action'])) {
		$filters['action'] = trim($query['action']);
	}
	if (isset($query['entity_type']) && is_string($query['entity_type'])) {
		$filters['entity_type'] = trim($query['entity_type']);
	}
	if (isset($query['entity_id']) && is_numeric($query['entity_id'])) {
		$filters['entity_id'] = (int) $query['entity_id'];
	}
	if (isset($query['channel']) && in_array($query['channel'], ['api', 'websocket'], true)) {
		$filters['channel'] = $query['channel'];
	}
	if (isset($query['status']) && in_array($query['status'], ['success', 'failure', 'error'], true)) {
		$filters['status'] = $query['status'];
	}
	if (isset($query['severity']) && in_array($query['severity'], ['info', 'warn', 'error', 'critical'], true)) {
		$filters['severity'] = $query['severity'];
	}

	foreach (['start_date', 'end_date'] as $key) {
		if (!isset($query[$key]) || !is_string($query[$key])) {
			continue;
		}

		$value = trim($query[$key]);
		if (preg_match('/^\d{4}-\d{2}-\d{2}(\s\d{2}:\d{2}:\d{2})?$/', $value)) {
			$filters[$key] = $value;
		}
	}

	if (isset($query['limit']) && is_numeric($query['limit'])) {
		$requestedLimit = (int) $query['limit'];
		if ($requestedLimit > 0 && $requestedLimit <= AUDIT_ENDPOINT_MAX_LIMIT) {
			$filters['limit'] = $requestedLimit;
		}
	}

	if (isset($query['offset']) && is_numeric($query['offset'])) {
		$filters['offset'] = max(0, (int) $query['offset']);
	}

	return $filters;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
	http_response_code(204);
	exit;
}

try {
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
		json_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
	}

	require_audit_admin($pdo);
	$filters = build_audit_filters($_GET);

	$logs = db_query_audit_logs($pdo, $filters);
	$total = db_count_audit_logs($pdo, $filters);
	$redactedLogs = array_map(static function (array $log): array {
		unset($log['ip_address']);
		return $log;
	}, $logs);

	json_out([
		'ok' => true,
		'logs' => $redactedLogs,
		'pagination' => [
			'total' => $total,
			'limit' => $filters['limit'],
			'offset' => $filters['offset'],
			'has_more' => ($filters['offset'] + $filters['limit']) < $total,
		],
		'filters' => $filters,
	]);
} catch (Throwable $e) {
	error_log('[audit.php] ' . $e->getMessage());
	$payload = ['ok' => false, 'error' => 'server_error'];
	if (debug_enabled()) {
		$payload['detail'] = $e->getMessage();
	}
	json_out($payload, 500);
}
