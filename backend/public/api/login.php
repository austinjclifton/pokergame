<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

setAllowedMethods('POST, OPTIONS');
apply_auth_rate_limiting(get_client_ip(), 5, 60);

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $status = 200): void
{
	http_response_code($status);
	echo json_encode($data, JSON_UNESCAPED_SLASHES);
	exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
	http_response_code(204);
	exit;
}

try {
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
		json_out(['ok' => false, 'error' => 'method_not_allowed'], 405);
	}

	$rawInput = $GLOBALS['_TEST_INPUT'] ?? file_get_contents('php://input');
	$payloadValidation = validate_json_payload_size($rawInput, 5120);
	if (!$payloadValidation['valid']) {
		json_out(['ok' => false, 'error' => $payloadValidation['error']], 413);
	}

	$input = json_decode($rawInput, true, 512, JSON_THROW_ON_ERROR);
	$username = trim((string) ($input['username'] ?? ''));
	$password = (string) ($input['password'] ?? '');

	if ($username === '' || $password === '') {
		json_out(['ok' => false, 'error' => 'missing_credentials'], 400);
	}

	$result = auth_login_user($pdo, $username, $password);
	if (isset($result['user']['username'])) {
		$result['user']['username'] = escape_html((string) $result['user']['username']);
	}

	json_out($result, 200);
} catch (RuntimeException $e) {
	if ($e->getMessage() === 'INVALID_CREDENTIALS') {
		AuditService::safeLog($pdo, [
			'user_id' => null,
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
		], 'login.php');

		json_out(['ok' => false, 'error' => 'invalid_credentials'], 401);
	}

	error_log('[login.php] RuntimeException: ' . $e->getMessage());
	$response = ['ok' => false, 'error' => 'bad_request'];
	if (debug_enabled()) {
		$response['detail'] = $e->getMessage();
	}
	json_out($response, 400);
} catch (JsonException $e) {
	error_log('[login.php] JSON parse error: ' . $e->getMessage());
	$response = ['ok' => false, 'error' => 'invalid_json'];
	if (debug_enabled()) {
		$response['detail'] = $e->getMessage();
	}
	json_out($response, 400);
} catch (Throwable $e) {
	error_log('[login.php] ' . $e->getMessage());
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
