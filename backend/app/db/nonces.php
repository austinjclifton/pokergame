<?php
// backend/app/db/nonces.php
// -----------------------------------------------------------------------------
// Data-access layer for the csrf_nonces table.
// Supports both general CSRF nonces and short-lived WebSocket auth tokens.
// This file contains *only* SQL and minimal shaping; no business rules.
// -----------------------------------------------------------------------------

declare(strict_types=1);

/**
 * Insert a CSRF nonce linked to a session.
 * Returns the inserted nonce ID.
 *
 * Table expectations (minimal):
 *   csrf_nonces(
 *     id BIGINT PK AUTO_INCREMENT,
 *     session_id BIGINT NOT NULL,
 *     nonce VARCHAR(255) NOT NULL UNIQUE,
 *     created_at DATETIME NOT NULL,
 *     expires_at DATETIME NOT NULL,
 *     used_at DATETIME NULL
 *   )
 */
function db_insert_nonce(PDO $pdo, int $sessionId, string $nonce, string $expiresAt): int {
    $stmt = $pdo->prepare(
        "INSERT INTO csrf_nonces (session_id, nonce, created_at, expires_at)
         VALUES (:sid, :nonce, NOW(), :exp)"
    );
    $stmt->execute([
        ':sid'   => $sessionId,
        ':nonce' => $nonce,
        ':exp'   => $expiresAt,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Fetch a nonce row (for general CSRF flows).
 * Returns: ['id','session_id','expires_at','used_at'] or null.
 */
function db_get_nonce(PDO $pdo, string $nonce): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, session_id, expires_at, used_at
           FROM csrf_nonces
          WHERE nonce = :n
          LIMIT 1"
    );
    $stmt->execute([':n' => $nonce]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Mark a generic nonce as used (idempotent).
 */
function db_mark_nonce_used(PDO $pdo, int $nonceId): void {
    $stmt = $pdo->prepare(
        "UPDATE csrf_nonces
            SET used_at = NOW()
          WHERE id = :id AND used_at IS NULL"
    );
    $stmt->execute([':id' => $nonceId]);
}

/* =============================================================================
 * WebSocket Auth – Specific helpers
 * ========================================================================== */

/**
 * Create a short-lived nonce (ws token) for WebSocket authentication.
 *
 * SECURITY NOTES:
 * - The token is random (128-bit hex) and expires after $ttlSeconds.
 * - We *do not* distinguish rows by "type"; instead, WS code treats these
 *   tokens as single-use regardless, based on "used_at" + "expires_at".
 *
 * Returns the generated token string.
 */
function db_create_ws_nonce(PDO $pdo, int $sessionId, int $ttlSeconds = 30): string {
    if ($sessionId <= 0) {
        throw new InvalidArgumentException('sessionId must be positive');
    }
    if ($ttlSeconds < 5 || $ttlSeconds > 3600) {
        // Guardrails: token shorter than 5s is useless; >1h is too long.
        throw new InvalidArgumentException('ttlSeconds out of bounds');
    }

    // 128-bit token (32 hex chars). You can bump to 256-bit if desired.
    $token = bin2hex(random_bytes(16));

    // NOTE: With PDO and MySQL, binding inside INTERVAL is tricky if you try named params.
    // Use positional binding with DATE_ADD; MySQL allows an expression for the interval value.
    $stmt = $pdo->prepare(
        "INSERT INTO csrf_nonces (session_id, nonce, created_at, expires_at)
         VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))"
    );
    // Bind as ints/strings explicitly
    $stmt->bindValue(1, $sessionId, PDO::PARAM_INT);
    $stmt->bindValue(2, $token, PDO::PARAM_STR);
    $stmt->bindValue(3, $ttlSeconds, PDO::PARAM_INT);
    $stmt->execute();

    return $token;
}

/**
 * Consume (one-time) a ws token and return the linked identity.
 *
 * Returns:
 *   [
 *     'session_id' => int,
 *     'user_id'    => int,
 *     'username'   => string
 *   ]
 * or null if invalid/expired/already used.
 *
 * Implementation is race-safe:
 * - Locks the row with FOR UPDATE within a transaction,
 * - Verifies not used and not expired,
 * - Marks used_at, commits, and returns identity.
 */
function db_consume_ws_nonce(PDO $pdo, string $token): ?array {
    if ($token === '') {
        return null;
    }

    try {
        $pdo->beginTransaction();

        // Lock candidate row so two parallel connections can't both consume it.
        $sel = $pdo->prepare(
            "SELECT n.id AS nonce_id,
                    n.session_id,
                    n.expires_at,
                    n.used_at,
                    s.id AS sess_id,
                    s.user_id,
                    s.revoked_at,
                    s.expires_at AS sess_expires_at,
                    u.username
               FROM csrf_nonces n
               JOIN sessions s ON s.id = n.session_id
               JOIN users    u ON u.id = s.user_id
              WHERE n.nonce = :t
              FOR UPDATE"
        );
        $sel->execute([':t' => $token]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            return null;
        }

        // Basic validity checks - use MySQL time comparison directly
        $validityCheck = $pdo->prepare(
            "SELECT 
                CASE WHEN n.expires_at <= NOW() THEN 1 ELSE 0 END as nonce_expired,
                CASE WHEN n.used_at IS NOT NULL THEN 1 ELSE 0 END as nonce_used,
                CASE WHEN s.revoked_at IS NOT NULL THEN 1 ELSE 0 END as sess_revoked,
                CASE WHEN s.expires_at <= NOW() THEN 1 ELSE 0 END as sess_expired
             FROM csrf_nonces n
             JOIN sessions s ON s.id = n.session_id
             WHERE n.id = :id"
        );
        $validityCheck->execute([':id' => (int)$row['nonce_id']]);
        $validity = $validityCheck->fetch(PDO::FETCH_ASSOC);
        
        $nonceExpired = (bool)$validity['nonce_expired'];
        $nonceUsed    = (bool)$validity['nonce_used'];
        $sessRevoked  = (bool)$validity['sess_revoked'];
        $sessExpired  = (bool)$validity['sess_expired'];

        if ($nonceUsed || $nonceExpired || $sessRevoked || $sessExpired) {
            $pdo->rollBack();
            return null;
        }

        // Mark as used (single-use)
        $upd = $pdo->prepare("UPDATE csrf_nonces SET used_at = NOW() WHERE id = :id AND used_at IS NULL");
        $upd->execute([':id' => (int)$row['nonce_id']]);

        $pdo->commit();

        return [
            'session_id' => (int)$row['sess_id'],
            'user_id'    => (int)$row['user_id'],
            'username'   => (string)$row['username'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Re-throw so the service layer can decide how to handle/log it,
        // or convert to null if you prefer silent failure upstream.
        throw $e;
    }
}

/* =============================================================================
 * Optional helpers used by AuthService refactor (if you adopt it)
 * ========================================================================== */

/**
 * Fetch a nonce row intended for WS validation (without consuming).
 * Useful if your service wants to do more checks before marking used.
 */
function db_get_nonce_for_ws(PDO $pdo, string $token): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, session_id, nonce, created_at, expires_at, used_at
           FROM csrf_nonces
          WHERE nonce = :t
          LIMIT 1"
    );
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Mark a nonce as consumed by id (idempotent).
 */
function db_mark_nonce_consumed(PDO $pdo, int $nonceId): void {
    $stmt = $pdo->prepare("UPDATE csrf_nonces SET used_at = NOW() WHERE id = :id AND used_at IS NULL");
    $stmt->execute([':id' => $nonceId]);
}
