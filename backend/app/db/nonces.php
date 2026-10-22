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
function db_insert_nonce(PDO $pdo, int $sessionId, string $nonce, string $expiresAt, string $purpose = 'csrf'): int {
    $stmt = $pdo->prepare(
        "INSERT INTO csrf_nonces (session_id, nonce_hash, purpose, created_at, expires_at)
         VALUES (:sid, :nonce_hash, :purpose, UTC_TIMESTAMP(), :exp)"
    );
    $stmt->execute([
        ':sid'   => $sessionId,
        ':nonce_hash' => hash('sha256', $nonce),
        ':purpose' => $purpose,
        ':exp'   => $expiresAt,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Fetch a nonce row (for general CSRF flows).
 * Returns: ['id','session_id','expires_at','used_at'] or null.
 */
function db_get_nonce(PDO $pdo, string $nonce, string $purpose = 'csrf'): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, session_id, expires_at, used_at
           FROM csrf_nonces
          WHERE nonce_hash = :n AND purpose = :purpose
          LIMIT 1"
    );
    $stmt->execute([':n' => hash('sha256', $nonce), ':purpose' => $purpose]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Mark a generic nonce as used (idempotent).
 */
function db_mark_nonce_used(PDO $pdo, int $nonceId): void {
    $stmt = $pdo->prepare(
        "UPDATE csrf_nonces
            SET used_at = UTC_TIMESTAMP()
          WHERE id = :id AND used_at IS NULL"
    );
    $stmt->execute([':id' => $nonceId]);
}

/**
 * Atomically consume a purpose- and session-bound nonce.
 * Returns the consumed row, or null when any condition is not satisfied.
 */
function db_consume_nonce(PDO $pdo, string $nonce, string $purpose, int $sessionId): ?array {
    if ($nonce === '' || $purpose === '' || $sessionId <= 0) {
        return null;
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $select = $pdo->prepare(
            "SELECT n.id, n.session_id, n.expires_at, n.used_at
             FROM csrf_nonces n
             JOIN sessions s ON s.id = n.session_id
             WHERE n.nonce_hash = :nonce_hash
               AND n.purpose = :purpose
               AND n.session_id = :session_id
               AND n.used_at IS NULL
               AND n.expires_at > UTC_TIMESTAMP()
               AND s.revoked_at IS NULL
               AND s.expires_at > UTC_TIMESTAMP()
             FOR UPDATE"
        );
        $select->execute([
            'nonce_hash' => hash('sha256', $nonce),
            'purpose' => $purpose,
            'session_id' => $sessionId,
        ]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return null;
        }

        $update = $pdo->prepare('UPDATE csrf_nonces SET used_at = UTC_TIMESTAMP() WHERE id = :id AND used_at IS NULL');
        $update->execute(['id' => (int) $row['id']]);
        if ($update->rowCount() !== 1) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return null;
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $row;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* =============================================================================
 * WebSocket Auth – Specific helpers
 * ========================================================================== */

/**
 * Create a short-lived nonce (ws token) for WebSocket authentication.
 *
 * SECURITY NOTES:
 * - The token is random (256-bit hex) and expires after $ttlSeconds.
 * - Only its SHA-256 hash is persisted and its purpose is explicit.
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

    $token = bin2hex(random_bytes(32));

    // NOTE: With PDO and MySQL, binding inside INTERVAL is tricky if you try named params.
    // Use positional binding with DATE_ADD; MySQL allows an expression for the interval value.
    $stmt = $pdo->prepare(
        "INSERT INTO csrf_nonces (session_id, nonce_hash, purpose, created_at, expires_at)
         VALUES (?, ?, 'websocket', UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))"
    );
    // Bind as ints/strings explicitly
    $stmt->bindValue(1, $sessionId, PDO::PARAM_INT);
    $stmt->bindValue(2, hash('sha256', $token), PDO::PARAM_STR);
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
              WHERE n.nonce_hash = :t
                AND n.purpose = 'websocket'
              FOR UPDATE"
        );
        $sel->execute([':t' => hash('sha256', $token)]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            return null;
        }

        // Basic validity checks - use MySQL time comparison directly
        $validityCheck = $pdo->prepare(
            "SELECT 
                CASE WHEN n.expires_at <= UTC_TIMESTAMP() THEN 1 ELSE 0 END as nonce_expired,
                CASE WHEN n.used_at IS NOT NULL THEN 1 ELSE 0 END as nonce_used,
                CASE WHEN s.revoked_at IS NOT NULL THEN 1 ELSE 0 END as sess_revoked,
                CASE WHEN s.expires_at <= UTC_TIMESTAMP() THEN 1 ELSE 0 END as sess_expired
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
        $upd = $pdo->prepare("UPDATE csrf_nonces SET used_at = UTC_TIMESTAMP() WHERE id = :id AND used_at IS NULL");
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
        "SELECT id, session_id, nonce_hash, purpose, created_at, expires_at, used_at
           FROM csrf_nonces
          WHERE nonce_hash = :t AND purpose = 'websocket'
          LIMIT 1"
    );
    $stmt->execute([':t' => hash('sha256', $token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Mark a nonce as consumed by id (idempotent).
 */
function db_mark_nonce_consumed(PDO $pdo, int $nonceId): void {
    $stmt = $pdo->prepare("UPDATE csrf_nonces SET used_at = UTC_TIMESTAMP() WHERE id = :id AND used_at IS NULL");
    $stmt->execute([':id' => $nonceId]);
}
