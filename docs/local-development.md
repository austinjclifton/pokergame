# Local development on macOS

PokerGame runs locally with PHP, MySQL, Ratchet, and Vite directly on macOS.
Docker, a hosted domain, HAProxy, and provider-specific services are not
required.

## Prerequisites

- PHP 8.4.27 or newer within the PHP 8.4 release branch
- Composer 2
- MySQL 8
- Node.js 22.12 or newer on the Node.js 22 or 24 release line
- npm

With Homebrew, install the equivalent current formulae for PHP 8.4, Composer,
MySQL 8, and Node 22 or 24. Confirm the selected binaries before continuing:

```bash
php --version
composer --version
mysql --version
node --version
npm --version
```

Install the locked application dependencies:

```bash
cd backend
composer install
cd ../frontend
npm ci
cd ..
```

## Application database

Start MySQL and create a development-only schema and user. Replace the example
password with a local password and use the same value in `.env`.

```bash
brew services start mysql
mysql -u root -p
```

Run the following statements in the MySQL prompt:

```sql
CREATE DATABASE pokergame
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pokergame_app'@'localhost'
  IDENTIFIED BY 'replace-with-a-local-password';
GRANT ALL PRIVILEGES ON pokergame.* TO 'pokergame_app'@'localhost';
```

Exit the prompt and initialize the schema without dropping any database:

```bash
mysql -u pokergame_app -p pokergame < backend/app/db/poker_schema.sql
```

The application database must never be reused for automated integration tests.
The isolated `_test` setup remains documented in
[`testing.md`](testing.md#integration-test-database).

## Environment configuration

Create an ignored local configuration file and edit its password:

```bash
cp .env.example .env
```

Load it into every terminal that runs PHP:

```bash
set -a
source .env
set +a
```

The PHP configuration variables are:

| Variable | Purpose |
| --- | --- |
| `APP_ENV` | Use `development` locally and `production` when deployed. |
| `APP_ORIGIN` | Exact browser origin allowed to make credentialed API requests. |
| `DB_HOST`, `DB_PORT` | MySQL endpoint. |
| `DB_NAME`, `DB_USER`, `DB_PASS` | Explicit application schema credentials. |
| `WS_HOST`, `WS_PORT` | Ratchet bind address and port. |
| `WS_APP_HOST` | Host header accepted by Ratchet routes. |

All five `DB_*` variables are required. There are no database credential
fallbacks. `WS_HOST` defaults to the loopback interface; a future deployment
must explicitly use a public or private bind address when appropriate.

Vite development defaults to `http://localhost:8000` for HTTP APIs and
`ws://localhost:8080` for WebSockets. Override them in an ignored
`frontend/.env.local` only when necessary:

```bash
cp frontend/.env.example frontend/.env.local
```

Production frontend builds default to the page's own HTTP origin and `/ws` on
the same host. Split deployments can set `VITE_API_BASE_URL` and
`VITE_WS_BASE_URL` at build time. Do not put secrets in `VITE_*` variables;
Vite embeds them in browser assets.

## Start the application

Use three terminals from the repository root.

Terminal 1 — HTTP API:

```bash
set -a; source .env; set +a
php -S localhost:8000 -t backend/public
```

Terminal 2 — WebSocket server:

```bash
set -a; source .env; set +a
php backend/ws/server.php
```

Terminal 3 — React frontend:

```bash
cd frontend
npm run dev -- --host localhost
```

Open `http://localhost:5173`. Use `localhost` consistently so the configured
origin, cookies, and Ratchet Host header agree.

## Manual end-to-end check

Use two independent browser profiles or one normal and one private window:

1. Register two disposable local users and log both in.
2. Confirm both users appear in the lobby.
3. Send and accept a challenge.
4. Confirm both browsers enter the same table and receive synchronized state.
5. Exercise legal game actions and confirm broadcasts reach both browsers.
6. Finish a game and confirm the final state/summary appears for both users.
7. Log both users out and confirm protected pages return to the login screen.

Delete disposable data only through deliberate SQL against the `pokergame`
development schema. The repository contains no application-database reset
script because automated destructive cleanup is reserved for guarded `_test`
databases.

## Stop the application

Press `Ctrl+C` once in each PHP/Vite terminal. Stop MySQL only if it is not
needed by other local projects:

```bash
brew services stop mysql
```

## Common failures

- `Missing required database setting`: load the root `.env` in that PHP
  terminal and ensure every `DB_*` key is present.
- Browser CORS rejection: use exactly `http://localhost:5173` or update
  `APP_ORIGIN` to the browser's actual origin, then restart the HTTP server.
- WebSocket handshake rejection: keep the browser hostname and `WS_APP_HOST`
  aligned; the documented local value is `localhost`.
- Database access denied: verify the dedicated user, password, and grants; do
  not fall back to the MySQL root account.
- Port already in use: stop the existing process or explicitly change the
  corresponding server and frontend endpoint variables together.
- Cookies work over HTTP locally but not after deployment: terminate HTTPS
  correctly and ensure PHP receives the HTTPS request state so cookies retain
  the Secure attribute.

## Automated checks

Unit tests do not use MySQL. Integration tests require a separately configured
database ending in `_test`:

```bash
cd backend
vendor/bin/phpunit --configuration phpunit.xml --display-all-issues
vendor/bin/phpunit --configuration phpunit.integration.xml --display-all-issues
composer audit --locked

cd ../frontend
npm audit --package-lock-only --audit-level=low
npm run lint
npm run build
```
