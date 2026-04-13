<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

setAllowedMethods('POST, OPTIONS');
apply_rate_limiting(null, 100, 200, 60);

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $status = 200): void
{
	http_response_code($status);
	echo json_encode($data, JSON_UNESCAPED_SLASHES);
	exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
	http_response_code(204);
	exit;
}

if ($method !== 'POST') {
	json_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

try {
	$user = requireSession($pdo);
	if (!$user) {
		json_out(['ok' => false, 'error' => 'Not authenticated'], 401);
	}

	$rawInput = $GLOBALS['_TEST_INPUT'] ?? file_get_contents('php://input');
	$payloadValidation = validate_json_payload_size($rawInput, 1024);
	if (!$payloadValidation['valid']) {
		json_out(['ok' => false, 'error' => $payloadValidation['error']], 413);
	}

	$data = json_decode($rawInput, true) ?? [];
	$token = $data['token'] ?? '';

	try {
		validate_csrf_token($pdo, $token, (int) $user['session_id']);
	} catch (RuntimeException $e) {
		$errorMsg = match ($e->getMessage()) {
			'CSRF_TOKEN_MISSING' => 'Missing CSRF token',
			'CSRF_TOKEN_INVALID' => 'Invalid CSRF token',
			'CSRF_TOKEN_EXPIRED' => 'CSRF token expired',
			'CSRF_TOKEN_ALREADY_USED' => 'CSRF token already used',
			'CSRF_TOKEN_SESSION_MISMATCH' => 'CSRF token does not match session',
			'CSRF_TOKEN_SESSION_INVALID' => 'Session invalid',
			default => 'Invalid CSRF token',
		};
		json_out(['ok' => false, 'error' => $errorMsg], 403);
	}

	apply_rate_limiting((int) $user['user_id'], 100, 200, 60);

	$revoked = auth_logout_user($pdo);
	json_out([
		'ok' => true,
		'message' => $revoked ? 'Session terminated' : 'No active session',
	]);
} catch (Throwable $e) {
	error_log('[logout.php] ' . $e->getMessage());
	json_out(['ok' => false, 'error' => 'Server error'], 500);
}
