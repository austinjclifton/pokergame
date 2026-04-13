<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

apply_rate_limiting(null, 100, 200, 60);

$user = requireSession($pdo);
if (!$user) {
	http_response_code(401);
	echo json_encode(['ok' => false, 'message' => 'Not logged in']);
	exit;
}

apply_rate_limiting((int) $user['user_id'], 100, 200, 60);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
	http_response_code(405);
	echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
	exit;
}

$rawInput = $GLOBALS['_TEST_INPUT'] ?? file_get_contents('php://input');
$payloadValidation = validate_json_payload_size($rawInput, 5120);
if (!$payloadValidation['valid']) {
	http_response_code(413);
	echo json_encode(['ok' => false, 'message' => $payloadValidation['error']]);
	exit;
}

$input = json_decode($rawInput, true) ?? [];
$challengeId = (int) ($input['challenge_id'] ?? 0);
$token = $input['token'] ?? '';

if ($challengeId <= 0) {
	http_response_code(400);
	echo json_encode(['ok' => false, 'message' => 'Missing challenge_id']);
	exit;
}

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

	http_response_code(403);
	echo json_encode(['ok' => false, 'message' => $errorMsg]);
	exit;
}

$service = new ChallengeService($pdo);
try {
	echo json_encode($service->accept($challengeId, (int) $user['user_id']));
} catch (Throwable $e) {
	error_log('[challenge_accept.php] ' . $e->getMessage());
	http_response_code(500);
	$response = ['ok' => false, 'message' => 'Server error'];
	if (debug_enabled()) {
		$response['detail'] = $e->getMessage();
	}
	echo json_encode($response);
}
