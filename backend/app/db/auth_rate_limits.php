<?php
declare(strict_types=1);

/**
 * Increment a fixed-window authentication throttle under a row lock.
 * The identifier is keyed with a one-way hash so raw client addresses are not
 * stored in the throttle table.
 *
 * @return array{allowed:bool,remaining:int,retry_after:int}
 */
function db_consume_auth_rate_limit(
    PDO $pdo,
    string $scope,
    string $identifier,
    int $limit,
    int $windowSeconds,
    int $blockSeconds = 300,
): array {
    if ($limit < 1 || $windowSeconds < 1 || $blockSeconds < 1) {
        throw new InvalidArgumentException('Invalid authentication rate-limit configuration');
    }

    $keyHash = hash('sha256', $scope . "\0" . $identifier);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $select = $pdo->prepare(
            'SELECT request_count,
                    UNIX_TIMESTAMP(window_started_at) AS window_started,
                    UNIX_TIMESTAMP(blocked_until) AS blocked_until,
                    UNIX_TIMESTAMP(NOW()) AS now_epoch
             FROM auth_rate_limits
             WHERE key_hash = :key_hash
             FOR UPDATE'
        );
        $select->execute(['key_hash' => $keyHash]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $insert = $pdo->prepare(
                'INSERT INTO auth_rate_limits (key_hash, window_started_at, request_count)
                 VALUES (:key_hash, NOW(), 1)'
            );
            $insert->execute(['key_hash' => $keyHash]);
            $result = ['allowed' => true, 'remaining' => $limit - 1, 'retry_after' => 0];
        } else {
            $now = (int) $row['now_epoch'];
            $blockedUntil = $row['blocked_until'] === null ? 0 : (int) $row['blocked_until'];
            if ($blockedUntil > $now) {
                $result = ['allowed' => false, 'remaining' => 0, 'retry_after' => $blockedUntil - $now];
            } else {
                $windowStarted = (int) $row['window_started'];
                $count = $now - $windowStarted >= $windowSeconds ? 1 : (int) $row['request_count'] + 1;
                $newWindow = $now - $windowStarted >= $windowSeconds;
                $blocked = $count > $limit;
                $update = $pdo->prepare(
                    'UPDATE auth_rate_limits
                     SET request_count = :request_count,
                         window_started_at = CASE WHEN :new_window = 1 THEN NOW() ELSE window_started_at END,
                         blocked_until = CASE WHEN :blocked = 1 THEN DATE_ADD(NOW(), INTERVAL :block_seconds SECOND) ELSE NULL END
                     WHERE key_hash = :key_hash'
                );
                $update->execute([
                    'request_count' => $count,
                    'new_window' => $newWindow ? 1 : 0,
                    'blocked' => $blocked ? 1 : 0,
                    'block_seconds' => $blockSeconds,
                    'key_hash' => $keyHash,
                ]);
                $result = [
                    'allowed' => !$blocked,
                    'remaining' => max(0, $limit - $count),
                    'retry_after' => $blocked ? $blockSeconds : 0,
                ];
            }
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
