# PokerGame - Complete Documentation

A 1v1 poker simulation game built with application security as its main objective.

---

## 📋 Table of Contents

1. [Quick Start](#quick-start)
2. [Security Overview](#security-overview)
3. [Security Implementation Details](#security-implementation-details)
4. [Test Suite](#test-suite)
5. [Architecture](#architecture)

---

## 🚀 Quick Start

### Frontend
```bash
cd frontend
npm run dev
```

### Backend API Server
```bash
php -S localhost:8000 -t backend/public
```

### WebSocket Server
```bash
php backend/ws/server.php
```

---

## 🔒 Security Overview

**Overall Security Status: 🟢 EXCELLENT**

This application implements comprehensive security measures across multiple layers:

### ✅ Implemented Protections

1. **XSS (Cross-Site Scripting) Protection**
   - All user-generated content escaped with `htmlspecialchars()`
   - Username validation prevents malicious input
   - React's default escaping provides defense-in-depth
   - Content-Security-Policy headers configured

2. **SQL Injection Protection**
   - All queries use prepared statements with parameter binding
   - `PDO::ATTR_EMULATE_PREPARES => false` ensures real prepared statements
   - Strict typing enforced throughout
   - Integer casting for numeric values

3. **CSRF (Cross-Site Request Forgery) Protection**
   - Session-bound, single-use CSRF tokens
   - All state-changing endpoints protected
   - Token validation with expiration and usage tracking
   - SameSite=Lax cookies provide additional protection

4. **DDoS Protection**
   - HTTP API rate limiting: 100 req/min per IP, 200 req/min per user
   - Stricter limits for auth endpoints: 5 req/min per IP
   - WebSocket connection limits: Global, per-IP, and per-user
   - Automatic IP bans after exceeding limits

5. **Information Disclosure Protection**
   - Error messages only exposed in debug mode
   - Centralized `debug_enabled()` helper
   - All errors logged to `error_log()` for debugging
   - Debug mode: `?debug=1` or `APP_DEBUG=1` environment variable

6. **Input Validation & Canonicalization**
   - Username validation: 3-20 chars, alphanumeric + underscore/hyphen
   - Email validation: Format and length checks
   - Password validation: Min/max length enforcement
   - Username/email canonicalization: Lowercase, normalized whitespace
   - JSON payload size limits: 5KB default

7. **Session Security**
   - HttpOnly cookies (prevents XSS access)
   - Secure flag when HTTPS enabled
   - SameSite=Lax (CSRF protection)
   - IP hash validation
   - User-Agent validation (first 60 chars)
   - Automatic session extension when nearing expiration

8. **Password Security**
   - `password_hash()` with `PASSWORD_DEFAULT`
   - Automatic password rehashing on login
   - Maximum length validation

9. **Authorization**
   - All endpoints verify user authentication
   - Challenge operations verify user ownership
   - Database queries filter by user ID

10. **Security Headers**
    - `X-Content-Type-Options: nosniff`
    - `X-Frame-Options: DENY`
    - `Content-Security-Policy`
    - `Strict-Transport-Security` (when HTTPS)
    - `Referrer-Policy: no-referrer-when-downgrade`

### ✅ Additional Security Measures

- **SSRF (Server-Side Request Forgery)**: ✅ Low Risk - No user-provided URL fetching
- **XXE (XML External Entity)**: ✅ Not Vulnerable - Application uses JSON only
- **Buffer Overflow**: ✅ Not Applicable - PHP handles memory management automatically

---

## 🔐 Authentication & Session Management

This system uses a multi-layered authentication approach combining sessions, cookies, CSRF tokens, and WebSocket tokens for secure user authentication and state management.

### Sessions

**Purpose:** Maintain user authentication state across HTTP requests.

**Implementation:**
- Stored in `sessions` database table
- Identified by numeric `session_id` stored in HTTP-only cookie
- Linked to user account via `user_id`
- Validated on each request using IP hash and User-Agent

**Session Lifecycle:**
1. **Creation:** When user logs in or registers, a session is created with:
   - 7-day expiration (configurable via `SESSION_TTL_DAYS`)
   - SHA-256 hash of client IP address
   - User-Agent string (first 255 characters)
   - Linked to user account

2. **Validation:** On each request, the session is validated by:
   - Checking cookie exists and contains valid `session_id`
   - Verifying session exists in database and is not revoked
   - Comparing IP hash (prevents session hijacking)
   - Comparing User-Agent (first 60 characters, prevents basic hijacking)
   - Checking expiration time

3. **Extension:** If session expires within 12 hours (`SESSION_TOUCH_HOURS`), it's automatically extended to full 7-day TTL

4. **Revocation:** Sessions can be revoked on logout or security events:
   - Marked as revoked in database (`revoked_at` timestamp)
   - Cookie is cleared (expired in browser)

**Security Properties:**
- **HttpOnly:** Cookie cannot be accessed via JavaScript (XSS protection)
- **Secure:** Cookie only sent over HTTPS when available
- **SameSite=Lax:** Prevents CSRF attacks by restricting cross-site cookie sending
- **Path=/** Cookie available across entire application
- **IP Validation:** Session invalidated if IP address changes
- **User-Agent Validation:** Session invalidated if User-Agent changes significantly

**Files:**
- `backend/lib/session.php` - Session lifecycle management
- `backend/app/db/sessions.php` - Database operations
- `backend/app/services/AuthService.php` - Authentication service layer

### Cookies

**Purpose:** Store session identifier in browser for persistent authentication.

**Cookie Name:** `session_id`

**Cookie Attributes:**
```php
[
    'expires'  => time() + (7 * 86400),  // 7 days
    'path'     => '/',                    // Available site-wide
    'secure'   => isset($_SERVER['HTTPS']), // HTTPS-only when available
    'httponly' => true,                   // No JavaScript access
    'samesite' => 'Lax',                  // CSRF protection
]
```

**Security Benefits:**
- **HttpOnly:** Prevents XSS attacks from stealing session cookies
- **Secure:** Prevents transmission over unencrypted connections (when HTTPS enabled)
- **SameSite=Lax:** Prevents CSRF attacks by restricting when cookies are sent cross-site
- **Path=/** Ensures cookie is available for all application routes

**Cookie Reading:**
- Cookies are read from `$_COOKIE['session_id']` superglobal
- Validated against database session record
- IP and User-Agent validated on each read

**Cookie Revocation:**
- Cookie is cleared by setting expiration to past time
- Session marked as revoked in database
- User must re-authenticate

### CSRF Tokens (Nonces)

**Purpose:** Prevent Cross-Site Request Forgery (CSRF) attacks on state-changing operations.

**Implementation:**
- Stored in `csrf_nonces` database table
- Linked to session via `session_id`
- Single-use tokens (marked as used after validation)
- 15-minute expiration (configurable)

**Token Properties:**
- **Length:** 64 hex characters (32 bytes = 256 bits of entropy)
- **Format:** Hexadecimal string (lowercase)
- **TTL:** 15 minutes default (configurable via `nonce_issue()`)
- **Single-Use:** Marked as `used_at` after validation, cannot be reused

**Token Lifecycle:**
1. **Issuance:** 
   - Client requests token via `GET /api/nonce.php`
   - Server generates random 256-bit token
   - Token stored in database linked to current session
   - Token returned to client with expiration time

2. **Usage:**
   - Client includes token in state-changing request (POST/PUT/DELETE)
   - Server validates token:
     - Token exists in database
     - Token not expired
     - Token not already used
     - Token linked to valid session
   - Token marked as used after successful validation

3. **Expiration:**
   - Tokens expire after 15 minutes
   - Expired tokens are rejected
   - Client must request new token

**Protected Operations:**
- User logout (`POST /api/logout.php`)
- Challenge creation (`POST /api/challenge.php`)
- Challenge acceptance (`POST /api/challenge_accept.php`)
- Challenge response (`POST /api/challenge_response.php`)
- User registration (`POST /api/register.php`)

**Files:**
- `backend/app/services/NonceService.php` - Token issuance
- `backend/app/db/nonces.php` - Database operations
- `backend/lib/security.php` - Token validation (`validate_csrf_token()`)

### WebSocket Authentication Tokens

**Purpose:** Secure WebSocket connections with short-lived, single-use tokens.

**Implementation:**
- Stored in `csrf_nonces` table (same table as CSRF tokens)
- Shorter TTL than CSRF tokens (30 seconds default)
- Single-use tokens (consumed on connection)
- Required for WebSocket authentication

**Token Properties:**
- **Length:** 32 hex characters (16 bytes = 128 bits of entropy)
- **Format:** Hexadecimal string (lowercase)
- **TTL:** 30 seconds default (configurable, 5-3600 seconds)
- **Single-Use:** Consumed immediately on WebSocket connection
- **Session-Bound:** Linked to authenticated user session

**Token Lifecycle:**
1. **Issuance:**
   - Authenticated user requests token via `POST /api/ws_token.php`
   - Server validates user is authenticated (session cookie required)
   - Server generates random 128-bit token
   - Token stored in database with 30-second expiration
   - Token returned to client

2. **Usage:**
   - Client includes token in WebSocket connection URL: `ws://host:port/lobby?token=...`
   - Server validates token on connection:
     - Token exists in database
     - Token not expired
     - Token not already used
     - Token's session is valid and not revoked
   - Token consumed (marked as used) immediately
   - Connection authenticated if token valid

3. **Fallback:**
   - If token not provided, server falls back to session cookie authentication
   - Session cookie validated same as HTTP requests

**Security Benefits:**
- **Short TTL:** 30-second window reduces attack surface
- **Single-Use:** Token cannot be reused even if intercepted
- **Session-Bound:** Token only valid for specific user session
- **Race-Safe:** Database transactions prevent concurrent consumption
- **Fallback:** Session cookie provides backup authentication method

**Files:**
- `backend/app/services/NonceService.php` - Token issuance (`nonce_issue_ws_token()`)
- `backend/app/db/nonces.php` - Database operations (`db_create_ws_nonce()`, `db_consume_ws_nonce()`)
- `backend/ws/server.php` - WebSocket authentication (`ws_auth()`)
- `backend/ws/AuthenticatedServer.php` - WebSocket connection handler

### Token vs Session vs Cookie Comparison

| Feature | Session Cookie | CSRF Token | WebSocket Token |
|---------|---------------|------------|-----------------|
| **Purpose** | Persistent authentication | CSRF protection | WebSocket auth |
| **Storage** | Browser cookie | Request body/header | URL query parameter |
| **Lifetime** | 7 days | 15 minutes | 30 seconds |
| **Reusability** | Multiple requests | Single use | Single use |
| **Entropy** | Session ID (database) | 256 bits | 128 bits |
| **Validation** | IP + User-Agent | Session link | Session link |
| **Revocation** | Database flag | Used flag | Used flag |

### Security Flow Examples

**HTTP Request Flow:**
1. User logs in → Session created → Cookie set
2. User requests CSRF token → Token issued → Token returned
3. User makes POST request → Token validated → Request processed → Token marked used
4. User makes another POST → New token required

**WebSocket Connection Flow:**
1. User authenticated (has session cookie)
2. User requests WebSocket token → Token issued (30s TTL)
3. User connects WebSocket with token → Token validated → Connection authenticated → Token consumed
4. User reconnects → New token required (previous token consumed)

**Session Validation Flow:**
1. Request received → Cookie read → Session ID extracted
2. Session validated:
   - Exists in database
   - Not revoked
   - Not expired
   - IP hash matches
   - User-Agent matches
3. If valid → Request processed
4. If invalid → User redirected to login

---

## 🔧 Security Implementation Details

### XSS Protection

**Files Modified:**
- `backend/lib/security.php` - Added `escape_html()`, `validate_username()`
- `backend/ws/LobbySocket.php` - All usernames and messages escaped
- `backend/public/api/*.php` - All API responses escape usernames
- `backend/app/services/LobbyService.php` - Username escaping in responses

**Key Functions:**
```php
escape_html($text) - HTML escape user content
validate_username($username) - Validates username format and length
canonicalize_username($username) - Normalizes username for storage
```

### SQL Injection Protection

**Status:** ✅ All queries use prepared statements

**Key Configuration:**
```php
PDO::ATTR_EMULATE_PREPARES => false  // Real prepared statements
```

**Fixed Issues:**
- `LIMIT` clause in `chat_messages.php` now uses integer casting with bounds checking

### CSRF Protection

**Implementation:**
- Tokens issued via `/api/nonce.php`
- Validated using `validate_csrf_token()` in `backend/lib/security.php`
- Protected endpoints: `/api/logout.php`, `/api/challenge.php`, `/api/challenge_accept.php`, `/api/challenge_response.php`

**Token Properties:**
- Session-bound (linked to `session_id`)
- Single-use (marked as used after validation)
- Expires after 15 minutes
- Validated against session validity

### DDoS Protection

**HTTP API Rate Limiting:**
- IP-based: 100 requests/minute
- User-based: 200 requests/minute (after authentication)
- Auth endpoints: 5 requests/minute per IP
- Automatic 5-minute IP ban after limit exceeded

**WebSocket Connection Limits:**
- Global: 1000 concurrent connections
- Per-IP: 10 concurrent connections
- Per-User: 5 concurrent connections

**Implementation:**
- `RateLimitStorage` class for in-memory tracking
- `apply_rate_limiting()` middleware function
- `check_ip_rate_limit()` and `check_user_rate_limit()` helpers

### Information Disclosure

**Fixed Issues:**
- All API endpoints now check `debug_enabled()` before exposing error details
- Error messages logged to `error_log()` for debugging
- Debug mode: `?debug=1` query parameter or `APP_DEBUG=1` environment variable

**Files Fixed:**
- `backend/public/api/me.php`
- `backend/public/api/login.php`
- `backend/public/api/challenges.php`
- `backend/public/api/challenge.php`
- `backend/public/api/challenge_accept.php`
- `backend/public/api/challenge_response.php`
- `backend/public/api/ws_token.php`
- `backend/public/api/nonce.php`

---

## 🧪 Test Suite

### Test Statistics

- **Total Test Files:** 22
- **Unit Tests:** 13 files
- **Integration Tests:** 9 files
- **Test Coverage:** Comprehensive across all layers

### Running Tests

```bash
# Run all tests
cd backend
php vendor/bin/phpunit

# Run specific test file
php vendor/bin/phpunit tests/unit/AuthServiceLoginTest.php

# Run with readable output
php vendor/bin/phpunit --testdox

# Run with code coverage (requires Xdebug/PCOV)
php vendor/bin/phpunit --coverage-html coverage/
```

### Test Coverage

**Well-Tested Components:**
- ✅ Authentication & Sessions (login, registration, password hashing)
- ✅ WebSockets (LobbySocket, AuthenticatedServer)
- ✅ Challenges (service layer, database layer, API endpoints)
- ✅ Presence (online/offline tracking)
- ✅ Database Security (SQL injection prevention)
- ✅ Rate Limiting (HTTP API and WebSocket limits)
- ✅ Security Functions (XSS, CSRF, input validation)
- ✅ LobbyService (online players, chat messages)
- ✅ SubscriptionService (WebSocket connection management)

**Test Files:**
- `tests/unit/` - Unit tests for services and utilities
- `tests/integration/` - Integration tests with real database

### Test Quality

**Strengths:**
- ✅ Real database usage (MySQL with transactions)
- ✅ Transaction isolation for test independence
- ✅ Comprehensive security testing (XSS, CSRF, SQL injection, rate limiting)
- ✅ Realistic test data (real usernames, emails, etc.)
- ✅ Clear separation of unit vs integration tests
- ✅ Unique test data generation (prevents conflicts)

### Missing Test Coverage (Low Priority)

- `ws_token.php` API endpoint (medium priority)
- `chat_messages.php` database functions (low priority - tested indirectly)

---

## 🏗️ Architecture

### Backend Structure

```
backend/
├── app/
│   ├── db/          # Database access layer (pure SQL)
│   └── services/    # Business logic layer
├── config/          # Configuration (database, security)
├── lib/             # Utility libraries (security, sessions)
├── public/api/      # HTTP API endpoints
├── tests/           # Test suite
└── ws/              # WebSocket handlers
```

### API Endpoints

**Authentication:**
- `POST /api/login.php` - User login
- `POST /api/register.php` - User registration
- `POST /api/logout.php` - User logout (requires CSRF token)
- `GET /api/me.php` - Get current user info

**Lobby:**
- `GET /api/lobby.php` - Get online players
- `GET /api/challenges.php` - Get pending challenges
- `POST /api/challenge.php` - Send challenge (requires CSRF token)
- `POST /api/challenge_accept.php` - Accept challenge (requires CSRF token)
- `POST /api/challenge_response.php` - Accept/decline challenge (requires CSRF token)

**WebSocket:**
- `POST /api/ws_token.php` - Get WebSocket authentication token
- `GET /api/nonce.php` - Get CSRF token

### WebSocket Routes

- `/lobby` - Lobby chat and presence (via `LobbySocket`)
- `/game` - Game-specific communication (via `GameSocket` - not yet implemented)

### Database Schema

**Key Tables:**
- `users` - User accounts
- `sessions` - Active user sessions
- `csrf_nonces` - CSRF tokens and WebSocket auth tokens
- `user_lobby_presence` - Online/offline status
- `ws_subscriptions` - Active WebSocket connections
- `game_challenges` - Challenge invitations
- `games` - Game instances
- `game_players` - Players in games
- `chat_messages` - Chat message history

### Security Utilities

**Location:** `backend/lib/security.php`

**Key Functions:**
- `debug_enabled()` - Check if debug mode is enabled
- `escape_html($text)` - Escape HTML special characters
- `validate_username($username)` - Validate username format
- `validate_email($email)` - Validate email format
- `validate_password($password)` - Validate password
- `validate_json_payload_size($input, $maxBytes)` - Validate JSON payload size
- `canonicalize_username($username)` - Normalize username
- `canonicalize_email($email)` - Normalize email
- `normalize_whitespace($text)` - Remove zero-width spaces
- `validate_csrf_token($pdo, $nonce, $sessionId)` - Validate CSRF token
- `get_client_ip()` - Extract client IP (handles proxy headers)
- `check_ip_rate_limit($ip, $limit, $window)` - Check IP rate limit
- `check_user_rate_limit($userId, $limit, $window)` - Check user rate limit
- `apply_rate_limiting($userId, $ipLimit, $userLimit, $window)` - Apply rate limiting middleware
- `apply_auth_rate_limiting($ip, $limit, $window)` - Stricter rate limiting for auth endpoints

---

## 📝 Notes

### Debug Mode

Enable debug mode to see detailed error messages:
- Query parameter: `?debug=1`
- HTTP header: `X-Debug: true`
- Environment variable: `APP_DEBUG=1`

**Warning:** Never enable debug mode in production!

### Security Headers

All API endpoints include security headers via `backend/config/security.php`:
- Content-Security-Policy
- X-Content-Type-Options
- X-Frame-Options
- Referrer-Policy
- Strict-Transport-Security (when HTTPS)

### Rate Limiting

Rate limits are enforced automatically. If exceeded:
- HTTP 429 status code
- `X-RateLimit-Limit` header shows limit
- `X-RateLimit-Remaining` header shows remaining requests
- `Retry-After` header shows seconds until reset
- IP ban after repeated violations (5 minutes)

### CSRF Tokens

All state-changing operations require CSRF tokens:
1. Fetch token: `GET /api/nonce.php`
2. Include in request: `{ "token": "...", ... }`
3. Token is validated and consumed (single-use)

---

## ✅ Security Checklist

- [x] XSS protection (HTML escaping, input validation)
- [x] SQL injection protection (prepared statements)
- [x] CSRF protection (tokens, SameSite cookies)
- [x] DDoS protection (rate limiting, connection limits)
- [x] Information disclosure protection (debug mode only)
- [x] Input validation (username, email, password)
- [x] Canonicalization (username, email)
- [x] Session security (HttpOnly, Secure, SameSite)
- [x] Password security (bcrypt, rehashing)
- [x] Authorization checks (user ownership verification)
- [x] Security headers (CSP, X-Frame-Options, etc.)
- [x] Error handling (no information leakage)
- [x] Rate limiting (IP-based, user-based)
- [x] WebSocket security (authentication, connection limits)

---

**Last Updated:** 2025-01-27  
**Security Status:** 🟢 All Critical Issues Resolved  
**Test Coverage:** 🟢 Comprehensive (22 test files, all critical functionality tested)

