<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

setAllowedMethods('POST, OPTIONS');
apply_auth_rate_limiting(get_client_ip(), 5, 60);

$input = $GLOBALS['_TEST_INPUT'] ?? file_get_contents('php://input');
$payloadValidation = validate_json_payload_size($input, 10240);
if (!$payloadValidation['valid']) {
	http_response_code(413);
	echo json_encode(['ok' => false, 'error' => $payloadValidation['error']]);
	exit;
}

$data = json_decode($input, true);
if ($data === null) {
	http_response_code(400);
	echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
	exit;
}

$username = trim((string) ($data['username'] ?? ''));
$password = (string) ($data['password'] ?? '');
$email = trim((string) ($data['email'] ?? ''));
$nonce = $data['token'] ?? '';

if ($username === '' || $email === '' || $password === '' || $nonce === '') {
	http_response_code(400);
	echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
	exit;
}

$usernameValidation = validate_username($username);
if (!$usernameValidation['valid']) {
	http_response_code(400);
	echo json_encode(['ok' => false, 'error' => $usernameValidation['error']]);
	exit;
}
$canonicalUsername = $usernameValidation['canonical'] ?? $username;

$emailValidation = validate_email($email);
if (!$emailValidation['valid']) {
	http_response_code(400);
	echo json_encode(['ok' => false, 'error' => $emailValidation['error']]);
	exit;
}
$canonicalEmail = $emailValidation['canonical'] ?? $email;

$passwordValidation = validate_password($password);
if (!$passwordValidation['valid']) {
	http_response_code(400);
	echo json_encode(['ok' => false, 'error' => $passwordValidation['error']]);
	exit;
}

try {
	$result = auth_register_user($pdo, $canonicalUsername, $canonicalEmail, $password, $nonce);
	$accept = $_SERVER['HTTP_ACCEPT'] ?? '';

	if (stripos($accept, 'text/html') !== false) {
		header('Location: /login?registered=1');
		exit;
	}

	echo json_encode($result);
	exit;
} catch (RuntimeException $e) {
	if (strpos($e->getMessage(), 'INVALID_USERNAME:') === 0) {
		http_response_code(400);
		echo json_encode(['ok' => false, 'error' => substr($e->getMessage(), strlen('INVALID_USERNAME: '))]);
		exit;
	}
	if (strpos($e->getMessage(), 'INVALID_EMAIL:') === 0) {
		http_response_code(400);
		echo json_encode(['ok' => false, 'error' => substr($e->getMessage(), strlen('INVALID_EMAIL: '))]);
		exit;
	}
	if (strpos($e->getMessage(), 'INVALID_PASSWORD:') === 0) {
		http_response_code(400);
		echo json_encode(['ok' => false, 'error' => substr($e->getMessage(), strlen('INVALID_PASSWORD: '))]);
		exit;
	}

	$message = match ($e->getMessage()) {
		'INVALID_NONCE' => 'Invalid or expired registration token',
		'NONCE_SESSION_INVALID' => 'Session invalid for token',
		'USER_EXISTS' => 'Username or email already exists',
		'USER_CREATION_FAILED' => 'Could not create user',
		default => 'Registration failed',
	};

	$status = match ($e->getMessage()) {
		'INVALID_NONCE' => 400,
		'NONCE_SESSION_INVALID' => 403,
		'USER_EXISTS' => 409,
		'USER_CREATION_FAILED' => 500,
		default => 400,
	};

	http_response_code($status);
	echo json_encode(['ok' => false, 'error' => $message]);
	exit;
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['ok' => false, 'error' => 'Server error']);
	exit;
}
