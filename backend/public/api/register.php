<?php
// backend/public/api/register.php
// -----------------------------------------------------------------------------
// HTTP controller for new user registration.
//
// Flow:
//   - Parse and validate JSON
//   - Call auth_register_user()
//   - Return created user info or error
//
// Security:
//   - Validates nonce from CSRF_NONCES (bound to session)
//   - Prevents replay by marking nonce used
//   - Enforces unique username/email and secure password hashing
//
// Production behavior:
//   - JSON-based clients (fetch/XHR) still receive JSON responses
//   - Browser form submissions are redirected to /login?registered=1
//     after successful registration
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

// Set allowed methods
setAllowedMethods('POST, OPTIONS');

// Rate limiting: 5 registration attempts/min per IP
apply_auth_rate_limiting(get_client_ip(), 5, 60);

// ---------------- Parse request ----------------
$input = file_get_contents('php://input');

// Validate payload size (10KB max)
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

$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';
$email = trim($data['email'] ?? '');
$nonce = $data['token'] ?? '';

// ---------------- Basic validation ----------------
if (!$username || !$email || !$password || !$nonce) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

// Username validation (canonical form returned)
$usernameValidation = validate_username($username);
if (!$usernameValidation['valid']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $usernameValidation['error']]);
    exit;
}
$canonicalUsername = $usernameValidation['canonical'] ?? $username;

// Email validation (canonical form returned)
$emailValidation = validate_email($email);
if (!$emailValidation['valid']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $emailValidation['error']]);
    exit;
}
$canonicalEmail = $emailValidation['canonical'] ?? $email;

// Password validation
$passwordValidation = validate_password($password);
if (!$passwordValidation['valid']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $passwordValidation['error']]);
    exit;
}

try {
    // Attempt registration
    $result = auth_register_user($pdo, $canonicalUsername, $canonicalEmail, $password, $nonce);

    // ---------------------------------------------------------
    // SUCCESS BEHAVIOR
    // ---------------------------------------------------------
    // Detect whether caller is a browser navigation vs XHR/fetch
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

    // If the client expects HTML, redirect after successful registration
    if (stripos($accept, 'text/html') !== false) {
        header('Location: /login?registered=1');
        exit;
    }

    // Default JSON output (React front-end)
    echo json_encode($result);
    exit;

} catch (RuntimeException $e) {
    // Known validation and business-rule errors
    if (strpos($e->getMessage(), 'INVALID_USERNAME:') === 0) {
        $error = substr($e->getMessage(), strlen('INVALID_USERNAME: '));
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
    if (strpos($e->getMessage(), 'INVALID_EMAIL:') === 0) {
        $error = substr($e->getMessage(), strlen('INVALID_EMAIL: '));
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
    if (strpos($e->getMessage(), 'INVALID_PASSWORD:') === 0) {
        $error = substr($e->getMessage(), strlen('INVALID_PASSWORD: '));
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }

    switch ($e->getMessage()) {
        case 'INVALID_NONCE':
            http_response_code(400);
            $msg = 'Invalid or expired registration token';
            break;
        case 'NONCE_SESSION_INVALID':
            http_response_code(403);
            $msg = 'Session invalid for token';
            break;
        case 'USER_EXISTS':
            http_response_code(409);
            $msg = 'Username or email already exists';
            break;
        case 'USER_CREATION_FAILED':
            http_response_code(500);
            $msg = 'Could not create user';
            break;
        default:
            http_response_code(400);
            $msg = 'Registration failed';
    }

    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
