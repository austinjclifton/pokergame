<?php
// backend/app/services/AuditService.php
// -----------------------------------------------------------------------------
// Audit logging service with security features:
//   - Sensitive data redaction (passwords, tokens)
//   - Hash chain for tamper detection
//   - Automatic IP hashing
//   - UTC timestamp handling
//   - Safe defaults and type checking
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/../db/audit_log.php';
require_once __DIR__ . '/../../lib/security.php';

final class AuditService {
    private PDO $pdo;
    private bool $enableHashChain;
    
    /**
     * Fields that should be redacted from audit logs for security.
     */
    private const SENSITIVE_FIELDS = [
        'password',
        'password_hash',
        'token',
        'refresh_token',
        'api_key',
        'secret',
        'nonce',
        'session_id', // May want to log session_id separately, but not in details
    ];
    
    /**
     * @param PDO $pdo Database connection
     * @param bool $enableHashChain Enable hash chain for tamper detection (default: true)
     */
    public function __construct(PDO $pdo, bool $enableHashChain = true) {
        $this->pdo = $pdo;
        $this->enableHashChain = $enableHashChain;
    }
    
    /**
     * Log an audit event.
     * 
     * This is the main entry point for audit logging. It handles:
     * - Sensitive data redaction
     * - IP address hashing
     * - Hash chain calculation (if enabled)
     * - UTC timestamp generation
     * - Safe defaults
     * 
     * @param array{
     *   user_id?: int|null,
     *   session_id?: int|null,
     *   ip_address?: string|null,
     *   user_agent?: string|null,
     *   action: string,
     *   entity_type?: string|null,
     *   entity_id?: int|null,
     *   details?: array|null,
     *   channel?: 'api'|'websocket',
     *   status?: 'success'|'failure'|'error',
     *   severity?: 'info'|'warn'|'error'|'critical'
     * } $event Event data
     * @return int The ID of the inserted audit log entry
     * @throws RuntimeException If logging fails
     */
    public function log(array $event): int {
        // Validate required field
        if (empty($event['action']) || !is_string($event['action'])) {
            throw new RuntimeException('AuditService::log() requires "action" field');
        }
        
        // Redact sensitive data from details
        $details = $event['details'] ?? null;
        if (is_array($details)) {
            $details = $this->redactSensitiveData($details);
        }
        
        // Hash IP address if provided
        $ipAddress = $event['ip_address'] ?? null;
        $ipHash = null;
        if ($ipAddress !== null && $ipAddress !== 'unknown') {
            $ipHash = hash('sha256', $ipAddress);
        }
        
        // Calculate previous hash for hash chain (if enabled)
        $previousHash = null;
        if ($this->enableHashChain) {
            $previousHash = $this->calculatePreviousHash();
        }
        
        // Prepare data for insertion
        $logData = [
            'user_id' => $event['user_id'] ?? null,
            'session_id' => $event['session_id'] ?? null,
            'ip_address' => $ipAddress, // Store original for debugging (can be NULL in production)
            'ip_hash' => $ipHash,
            'user_agent' => $event['user_agent'] ?? null,
            'action' => $event['action'],
            'entity_type' => $event['entity_type'] ?? null,
            'entity_id' => $event['entity_id'] ?? null,
            'details' => $details,
            'channel' => $event['channel'] ?? 'api',
            'status' => $event['status'] ?? 'success',
            'severity' => $event['severity'] ?? 'info',
            'previous_hash' => $previousHash,
            'timestamp' => gmdate('Y-m-d H:i:s'), // UTC timestamp
        ];
        
        try {
            return db_insert_audit_log($this->pdo, $logData);
        } catch (Throwable $e) {
            // Log to error log but don't throw (audit failures shouldn't break the app)
            error_log('[AuditService] Failed to log audit event: ' . $e->getMessage());
            throw new RuntimeException('Audit logging failed', 0, $e);
        }
    }
    
    /**
     * Redact sensitive fields from an array recursively.
     * 
     * @param array<string, mixed> $data Data to redact
     * @return array<string, mixed> Data with sensitive fields redacted
     */
    private function redactSensitiveData(array $data): array {
        $redacted = [];
        foreach ($data as $key => $value) {
            $keyLower = strtolower($key);
            
            // Check if this key matches any sensitive field pattern
            $isSensitive = false;
            foreach (self::SENSITIVE_FIELDS as $sensitiveField) {
                if (strpos($keyLower, $sensitiveField) !== false) {
                    $isSensitive = true;
                    break;
                }
            }
            
            if ($isSensitive) {
                $redacted[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redactSensitiveData($value);
            } else {
                $redacted[$key] = $value;
            }
        }
        return $redacted;
    }
    
    /**
     * Calculate the hash of an audit log entry for hash chain.
     * 
     * This is used internally for chain calculation and can be used by tests
     * to verify hash chain integrity without duplicating hash logic.
     * 
     * @param array{id: int, timestamp: string, action: string, previous_hash?: string|null} $entry Entry data
     * @return string SHA-256 hash of the entry
     */
    public function computeEntryHash(array $entry): string {
        $hashData = sprintf(
            '%d|%s|%s|%s',
            $entry['id'],
            $entry['timestamp'],
            $entry['action'],
            $entry['previous_hash'] ?? ''
        );
        return hash('sha256', $hashData);
    }
    
    /**
     * Calculate the hash of the previous audit log entry for hash chain.
     * 
     * @return string|null Hash of previous entry, or null if this is the first entry
     */
    private function calculatePreviousHash(): ?string {
        try {
            $latest = db_get_latest_audit_log($this->pdo);
            if (!$latest) {
                return null; // First entry in chain
            }
            
            return $this->computeEntryHash($latest);
        } catch (Throwable $e) {
            // If we can't calculate the hash, log but continue
            error_log('[AuditService] Failed to calculate previous hash: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Verify the integrity of the audit log hash chain.
     * 
     * This can be run periodically to detect tampering.
     * 
     * @return array{valid: bool, broken_at: int|null, message: string}
     */
    public function verifyHashChain(): array {
        if (!$this->enableHashChain) {
            return ['valid' => true, 'broken_at' => null, 'message' => 'Hash chain is disabled'];
        }
        
        try {
            require_once __DIR__ . '/../db/audit_log.php';
            $entries = db_get_all_audit_logs_ordered($this->pdo);
            
            if (empty($entries)) {
                return ['valid' => true, 'broken_at' => null, 'message' => 'No entries to verify'];
            }
            
            $previousHash = null;
            foreach ($entries as $entry) {
                $expectedHash = null;
                if ($previousHash !== null) {
                    $expectedHash = $this->computeEntryHash($previousHash);
                }
                
                if ($entry['previous_hash'] !== $expectedHash) {
                    return [
                        'valid' => false,
                        'broken_at' => (int)$entry['id'],
                        'message' => sprintf(
                            'Hash chain broken at entry %d. Expected: %s, Got: %s',
                            $entry['id'],
                            $expectedHash ?? 'null',
                            $entry['previous_hash'] ?? 'null'
                        )
                    ];
                }
                
                $previousHash = $entry;
            }
            
            return ['valid' => true, 'broken_at' => null, 'message' => 'Hash chain is valid'];
        } catch (Throwable $e) {
            return [
                'valid' => false,
                'broken_at' => null,
                'message' => 'Error verifying hash chain: ' . $e->getMessage()
            ];
        }
    }
}

/**
 * Convenience function for logging audit events.
 * 
 * This is a global helper that can be called from anywhere in the application.
 * It creates an AuditService instance and logs the event.
 * 
 * @param PDO $pdo Database connection
 * @param array{
 *   user_id?: int|null,
 *   session_id?: int|null,
 *   ip_address?: string|null,
 *   user_agent?: string|null,
 *   action: string,
 *   entity_type?: string|null,
 *   entity_id?: int|null,
 *   details?: array|null,
 *   channel?: 'api'|'websocket',
 *   status?: 'success'|'failure'|'error',
 *   severity?: 'info'|'warn'|'error'|'critical'
 * } $event Event data
 * @return int The ID of the inserted audit log entry
 */
function log_audit_event(PDO $pdo, array $event): int {
    $service = new AuditService($pdo, true);
    return $service->log($event);
}

