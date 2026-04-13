<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

setAllowedMethods('POST, OPTIONS');
apply_rate_limiting(null, 100, 200, 60);

header('Content-Type: application/json; charset=utf-8');

function json_out(array $payload, int $code = 200): void
{
	http_response_code($code);
	echo json_encode($payload, JSON_UNESCAPED_SLASHES);
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
	$session = requireSession($pdo);
	if (!$session || empty($session['user_id']) || empty($session['session_id'])) {
		$response = ['ok' => false, 'error' => 'unauthorized'];
		if (debug_enabled()) {
			$response['hint'] = 'no_valid_session';
		}
		json_out($response, 401);
	}

	apply_rate_limiting((int) $session['user_id'], 100, 200, 60);

	$ttl = 30;
	$tokenData = nonce_issue_ws_token($pdo, $ttl);
	if (empty($tokenData['token'])) {
		json_out(['ok' => false, 'error' => 'token_issue_failed'], 500);
	}

	json_out([
		'ok' => true,
		'token' => $tokenData['token'],
		'expiresIn' => (int) ($tokenData['expiresIn'] ?? $ttl),
	]);
} catch (Throwable $e) {
	error_log('[ws_token.php] ' . $e->getMessage());

	$response = ['ok' => false, 'error' => 'server_error'];
	if (debug_enabled()) {
		$response['detail'] = $e->getMessage();
		$trace = $e->getTrace();
		if (!empty($trace[0]['file'])) {
			$response['where'] = basename($trace[0]['file']) . ':' . ($trace[0]['line'] ?? '?');
		}
		$response['cookies_seen'] = array_keys($_COOKIE);
		$response['session_cookie_value'] = $_COOKIE['session_id'] ?? null;
	}

	json_out($response, 500);
}
