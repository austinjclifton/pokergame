<?php
// backend/app/services/NonceService.php
// -----------------------------------------------------------------------------
// Business logic for issuing CSRF and WebSocket authentication nonces.
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/../db/nonces.php';
require_once __DIR__ . '/../../lib/session.php';

/**
 * Issues a nonce for the current session (CSRF protection).
 * If no session exists, creates a temporary session for unauthenticated users.
 * Returns ['nonce' => ..., 'expiresAt' => ...].
 */
function nonce_issue(PDO $pdo, int $ttlMinutes = 15): array {
    $now = new DateTime();
    $expiresAt = (clone $now)->modify("+{$ttlMinutes} minutes")->format('Y-m-d H:i:s');

    // Try existing session
    $user = requireSession($pdo);
    $sessionId = null;

    if ($user && isset($user['session_id'])) {
        $sessionId = (int)$user['session_id'];
    } else {
        // Create temporary session for unauthenticated clients (e.g., registration)
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $sessionId = createTemporarySession($pdo, $ip, $ua);
    }

    // Generate random nonce (256-bit entropy)
    $nonce = bin2hex(random_bytes(32));
    db_insert_nonce($pdo, $sessionId, $nonce, $expiresAt);

    return [
        'nonce' => $nonce,
        'expiresAt' => $expiresAt,
    ];
}

/* ---------------------------------------------------------------------------
 * WebSocket Authentication Token Helpers
 * ---------------------------------------------------------------------------*/

/**
 * Create a short-lived nonce (≈30 seconds) for WebSocket authentication.
 * Only works for authenticated users.
 * Returns ['token' => ..., 'expiresIn' => seconds].
 */
function nonce_issue_ws_token(PDO $pdo, int $ttlSeconds = 30): array {
    $user = requireSession($pdo);
    if (!$user || !isset($user['session_id'])) {
        throw new RuntimeException('UNAUTHORIZED');
    }

    $sessionId = (int)$user['session_id'];
    $token = db_create_ws_nonce($pdo, $sessionId, $ttlSeconds);

    return [
        'token' => $token,
        'expiresIn' => $ttlSeconds,
    ];
}

/**
 * Consume and validate a WebSocket auth token.
 * Returns ['user_id' => ..., 'username' => ..., 'session_id' => ...] if valid.
 */
function nonce_consume_ws_token(PDO $pdo, string $token): ?array {
    $result = db_consume_ws_nonce($pdo, $token);
    return $result ?: null;
}
