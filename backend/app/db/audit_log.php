<?php
// backend/app/db/audit_log.php
// -----------------------------------------------------------------------------
// Data access layer for AUDIT_LOG table.
// All functions here are pure SQL operations: no business logic, no validation.
// -----------------------------------------------------------------------------

declare(strict_types=1);

// -----------------------------------------------------------------------------
// INSERT operations
// -----------------------------------------------------------------------------

/**
 * Insert an audit log entry.
 * 
 * @param PDO $pdo Database connection
 * @param array{
 *   user_id?: int|null,
 *   session_id?: int|null,
 *   ip_address?: string|null,
 *   ip_hash?: string|null,
 *   user_agent?: string|null,
 *   action: string,
 *   entity_type?: string|null,
 *   entity_id?: int|null,
 *   details?: array|null,
 *   channel?: 'api'|'websocket',
 *   status?: 'success'|'failure'|'error',
 *   severity?: 'info'|'warn'|'error'|'critical',
 *   previous_hash?: string|null,
 *   timestamp?: string|null
 * } $data Audit log data
 * @return int The ID of the inserted audit log entry
 */
function db_insert_audit_log(PDO $pdo, array $data): int {
    // Ensure timestamp is UTC
    $timestamp = $data['timestamp'] ?? null;
    if ($timestamp === null) {
        $timestamp = gmdate('Y-m-d H:i:s');
    }
    
    // Convert details array to JSON if provided
    $detailsJson = null;
    if (isset($data['details']) && is_array($data['details'])) {
        $detailsJson = json_encode($data['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO audit_log (
            timestamp,
            user_id,
            session_id,
            ip_address,
            ip_hash,
            user_agent,
            action,
            entity_type,
            entity_id,
            details,
            channel,
            status,
            severity,
            previous_hash
        ) VALUES (
            :timestamp,
            :user_id,
            :session_id,
            :ip_address,
            :ip_hash,
            :user_agent,
            :action,
            :entity_type,
            :entity_id,
            :details,
            :channel,
            :status,
            :severity,
            :previous_hash
        )
    ");
    
    $stmt->execute([
        'timestamp' => $timestamp,
        'user_id' => $data['user_id'] ?? null,
        'session_id' => $data['session_id'] ?? null,
        'ip_address' => $data['ip_address'] ?? null,
        'ip_hash' => $data['ip_hash'] ?? null,
        'user_agent' => $data['user_agent'] ?? null,
        'action' => $data['action'],
        'entity_type' => $data['entity_type'] ?? null,
        'entity_id' => $data['entity_id'] ?? null,
        'details' => $detailsJson,
        'channel' => $data['channel'] ?? 'api',
        'status' => $data['status'] ?? 'success',
        'severity' => $data['severity'] ?? 'info',
        'previous_hash' => $data['previous_hash'] ?? null,
    ]);
    
    return (int)$pdo->lastInsertId();
}

// -----------------------------------------------------------------------------
// SELECT operations
// -----------------------------------------------------------------------------

/**
 * Get the most recent audit log entry (for hash chain calculation).
 * 
 * @param PDO $pdo Database connection
 * @return array{id: int, previous_hash: string|null, timestamp: string, action: string}|null
 */
function db_get_latest_audit_log(PDO $pdo): ?array {
    $stmt = $pdo->prepare("
        SELECT id, previous_hash, timestamp, action
        FROM audit_log
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Query audit logs with filters.
 * 
 * @param PDO $pdo Database connection
 * @param array{
 *   user_id?: int,
 *   action?: string,
 *   entity_type?: string,
 *   entity_id?: int,
 *   channel?: 'api'|'websocket',
 *   status?: 'success'|'failure'|'error',
 *   severity?: 'info'|'warn'|'error'|'critical',
 *   start_date?: string,
 *   end_date?: string,
 *   limit?: int,
 *   offset?: int
 * } $filters Query filters
 * @return array<int, array> Array of audit log entries
 */
function db_query_audit_logs(PDO $pdo, array $filters = []): array {
    $where = [];
    $params = [];
    
    if (isset($filters['user_id'])) {
        $where[] = 'user_id = :user_id';
        $params['user_id'] = $filters['user_id'];
    }
    
    if (isset($filters['action'])) {
        $where[] = 'action = :action';
        $params['action'] = $filters['action'];
    }
    
    if (isset($filters['entity_type'])) {
        $where[] = 'entity_type = :entity_type';
        $params['entity_type'] = $filters['entity_type'];
    }
    
    if (isset($filters['entity_id'])) {
        $where[] = 'entity_id = :entity_id';
        $params['entity_id'] = $filters['entity_id'];
    }
    
    if (isset($filters['channel'])) {
        $where[] = 'channel = :channel';
        $params['channel'] = $filters['channel'];
    }
    
    if (isset($filters['status'])) {
        $where[] = 'status = :status';
        $params['status'] = $filters['status'];
    }
    
    if (isset($filters['severity'])) {
        $where[] = 'severity = :severity';
        $params['severity'] = $filters['severity'];
    }
    
    if (isset($filters['start_date'])) {
        $where[] = 'timestamp >= :start_date';
        $params['start_date'] = $filters['start_date'];
    }
    
    if (isset($filters['end_date'])) {
        $where[] = 'timestamp <= :end_date';
        $params['end_date'] = $filters['end_date'];
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $limit = $filters['limit'] ?? 100;
    $offset = $filters['offset'] ?? 0;
    
    $sql = "
        SELECT 
            id,
            timestamp,
            user_id,
            session_id,
            ip_address,
            ip_hash,
            user_agent,
            action,
            entity_type,
            entity_id,
            details,
            channel,
            status,
            severity,
            previous_hash
        FROM audit_log
        {$whereClause}
        ORDER BY timestamp DESC, id DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($sql);
    
    // Bind limit and offset as integers
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse JSON details
    foreach ($rows as &$row) {
        if ($row['details'] !== null) {
            $row['details'] = json_decode($row['details'], true);
        }
    }
    
    return $rows;
}

/**
 * Get all audit log entries ordered by ID ascending (for hash chain verification).
 * 
 * @param PDO $pdo Database connection
 * @return array<int, array{id: int, timestamp: string, action: string, previous_hash: string|null}>
 */
function db_get_all_audit_logs_ordered(PDO $pdo): array {
    $stmt = $pdo->prepare("
        SELECT id, timestamp, action, previous_hash
        FROM audit_log
        ORDER BY id ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Count audit logs matching filters (for pagination).
 * 
 * @param PDO $pdo Database connection
 * @param array{
 *   user_id?: int,
 *   action?: string,
 *   entity_type?: string,
 *   entity_id?: int,
 *   channel?: 'api'|'websocket',
 *   status?: 'success'|'failure'|'error',
 *   severity?: 'info'|'warn'|'error'|'critical',
 *   start_date?: string,
 *   end_date?: string
 * } $filters Query filters
 * @return int Count of matching entries
 */
function db_count_audit_logs(PDO $pdo, array $filters = []): int {
    $where = [];
    $params = [];
    
    if (isset($filters['user_id'])) {
        $where[] = 'user_id = :user_id';
        $params['user_id'] = $filters['user_id'];
    }
    
    if (isset($filters['action'])) {
        $where[] = 'action = :action';
        $params['action'] = $filters['action'];
    }
    
    if (isset($filters['entity_type'])) {
        $where[] = 'entity_type = :entity_type';
        $params['entity_type'] = $filters['entity_type'];
    }
    
    if (isset($filters['entity_id'])) {
        $where[] = 'entity_id = :entity_id';
        $params['entity_id'] = $filters['entity_id'];
    }
    
    if (isset($filters['channel'])) {
        $where[] = 'channel = :channel';
        $params['channel'] = $filters['channel'];
    }
    
    if (isset($filters['status'])) {
        $where[] = 'status = :status';
        $params['status'] = $filters['status'];
    }
    
    if (isset($filters['severity'])) {
        $where[] = 'severity = :severity';
        $params['severity'] = $filters['severity'];
    }
    
    if (isset($filters['start_date'])) {
        $where[] = 'timestamp >= :start_date';
        $params['start_date'] = $filters['start_date'];
    }
    
    if (isset($filters['end_date'])) {
        $where[] = 'timestamp <= :end_date';
        $params['end_date'] = $filters['end_date'];
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $sql = "SELECT COUNT(*) as count FROM audit_log {$whereClause}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)($row['count'] ?? 0);
}

