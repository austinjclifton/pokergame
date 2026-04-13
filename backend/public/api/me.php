<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

setAllowedMethods('GET, OPTIONS');
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

if ($method !== 'GET') {
	json_out(['ok' => false, 'message' => 'Method not allowed'], 405);
}

try {
	$user = requireSession($pdo);
	if ($user) {
		apply_rate_limiting((int) $user['user_id'], 100, 200, 60);
	}

	if (!$user) {
		json_out([
			'ok' => false,
			'message' => 'No active session or invalid session cookie',
		], 401);
	}

	json_out([
		'ok' => true,
		'user' => [
			'id' => (int) $user['user_id'],
			'user_id' => (int) $user['user_id'],
			'username' => escape_html((string) $user['username']),
			'email' => $user['email'] ?? null,
			'session_id' => $user['session_id'] ?? null,
		],
	]);
} catch (Throwable $e) {
	error_log('[me.php] ' . $e->getMessage());
	$response = ['ok' => false, 'message' => 'Server error'];
	if (debug_enabled()) {
		$response['detail'] = $e->getMessage();
	}
	json_out($response, 500);
}
