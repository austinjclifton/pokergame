# Audit Trail System

Complete audit logging system for tracking all significant actions in the application.

## Quick Start

```php
require_once __DIR__ . '/backend/app/services/AuditService.php';

log_audit_event($pdo, [
    'action' => 'user.login',
    'user_id' => 123,
    'ip_address' => $_SERVER['REMOTE_ADDR'],
    'user_agent' => $_SERVER['HTTP_USER_AGENT'],
    'channel' => 'api',
    'status' => 'success',
]);
```

## Architecture

- **Database**: `backend/app/db/poker_schema.sql` - Enhanced `audit_log` table
- **Data Layer**: `backend/app/db/audit_log.php` - Database functions
- **Service Layer**: `backend/app/services/AuditService.php` - Logging service with security features
- **Admin API**: `backend/public/api/admin/audit.php` - Query endpoint

## Security Features

- ✅ **Sensitive Data Redaction**: Automatically redacts passwords, tokens, API keys
- ✅ **IP Address Hashing**: SHA-256 hashing for privacy
- ✅ **Hash Chain**: Tamper detection via cryptographic chain
- ✅ **Append-Only Design**: Prevents updates/deletes

## Implementation Status

✅ **Fully Implemented** - Audit logging is active for:

- ✅ **User Registration** (`AuthService::auth_register_user`)
- ✅ **User Login** (`AuthService::auth_login_user`) - Success
- ✅ **User Login Failures** (`login.php`) - Failed attempts
- ✅ **User Logout** (`AuthService::auth_logout_user`)
- ✅ **Challenge Creation** (`ChallengeService::send`)
- ✅ **Challenge Acceptance** (`ChallengeService::accept`)
- ✅ **Challenge Decline** (`ChallengeService::decline`)
- ✅ **Chat Messages** (`LobbySocket::onMessage` - chat type)
- ✅ **WebSocket Connections** (`LobbySocket::onOpen`)
- ✅ **WebSocket Disconnections** (`LobbySocket::onClose`)
- ✅ **Rate Limit Violations** (`LobbySocket::rateAllow`)

## Querying Logs

### Via Database Functions

```php
require_once __DIR__ . '/backend/app/db/audit_log.php';

$logs = db_query_audit_logs($pdo, [
    'user_id' => 123,
    'action' => 'user.login',
    'start_date' => '2024-01-01',
    'limit' => 50,
]);
```

### Via Admin API

```bash
GET /api/admin/audit?user_id=123&action=user.login&limit=50
```

## Testing

```bash
cd backend
vendor/bin/phpunit tests/unit/AuditServiceTest.php
vendor/bin/phpunit tests/integration/audit/
```
