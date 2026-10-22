# Authentication and authorization security

Phase 2 replaces public numeric session identifiers with opaque bearer tokens
while retaining numeric session IDs only for internal database relationships.

## HTTP sessions

- Login and registration generate 32 random bytes and send the hexadecimal
  token in the `session_token` cookie.
- MySQL stores only the SHA-256 token hash. API response bodies do not expose
  the raw token or the internal session ID.
- The cookie is `HttpOnly`, `SameSite=Lax`, scoped to `/`, and expires after
  seven days. It is `Secure` for direct HTTPS requests, an HTTPS `APP_ORIGIN`,
  or HTTPS reported by an explicitly trusted proxy.
- Sessions renew and rotate their bearer token after 24 hours or when expiry
  is near. The prior token stops resolving immediately. Logout revokes the
  database session and clears both the opaque cookie and the obsolete numeric
  cookie.
- User-Agent matching retains the existing theft signal. Client IP addresses
  are recorded as hashes for auditing but normal IP changes do not invalidate
  a session.

`TRUSTED_PROXIES` accepts exact IP addresses separated by commas. Forwarded IP
and protocol headers are ignored unless `REMOTE_ADDR` matches that list. CIDR
notation is not supported.

## WebSocket authentication

An authenticated HTTP client requests a 30-second WebSocket token from
`POST /api/ws_token.php`. Both lobby and game upgrades require that token and
an exact origin listed in `WS_ALLOWED_ORIGINS`. The token is consumed under a
database row lock before the connection reaches a channel handler. Lobby
cookie fallback no longer exists. Revoking the parent session causes the next
message on an established socket to be rejected.

## Nonces and throttling

CSRF nonces are stored only as hashes, bound to one session and one operation
purpose, and consumed atomically. Registration, logout, and the HTTP challenge
operations request distinct purposes. Authentication throttling is persisted
in MySQL by a hash of the client identifier, so separate PHP requests and
workers share the same limit.

## Administration

Audit access requires `users.is_admin = TRUE`; usernames confer no privilege.
Grant this only through a deliberate database administration operation after
verifying the numeric user record. Audit responses omit raw IP addresses.

## Deployment checklist

1. Back up the selected database and verify `SELECT DATABASE()`.
2. Run the guarded Phase 2 migration documented in
   `docs/local-development.md`. Existing sessions and nonces are revoked.
3. Set an HTTPS `APP_ORIGIN`, exact HTTPS `WS_ALLOWED_ORIGINS`, and only the
   immediate trusted proxy IPs.
4. Start HTTP and WebSocket services with the same environment, then verify
   registration, login, reconnect, logout, and ordinary-user audit denial.
5. Do not log full WebSocket upgrade URLs because they contain a short-lived
   single-use token query parameter.
