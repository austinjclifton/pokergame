# pokergame

A 1v1 poker simulation game built with application security as its main objective.

## Quick Start

Prerequisites and complete testing instructions are documented in
[`docs/testing.md`](docs/testing.md).

**Frontend:**
```bash
cd frontend
npm run dev
```

**Backend API:**
```bash
php -S localhost:8000 -t backend/public
```

**WebSocket Server:**
```bash
php backend/ws/server.php
```

## Local Development

For application development, provide all database settings together. Partial
configuration is rejected rather than mixed with defaults:

```bash
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_NAME=pokergame
export DB_USER=root
export DB_PASS=
php backend/ws/server.php
```

Available environment variables:
- `DB_HOST`
- `DB_PORT` (defaults to `3306`)
- `DB_NAME`
- `DB_USER`
- `DB_PASS` (may be explicitly empty for local development)

## Tests

Pure unit tests do not connect to MySQL:

```bash
cd backend
vendor/bin/phpunit --configuration phpunit.xml
```

Integration tests require explicit `TEST_DB_*` settings and a database whose
name ends in `_test`:

```bash
cd backend
vendor/bin/phpunit --configuration phpunit.integration.xml
```

The WebSocket baseline and persisted state format are recorded in
[`docs/websocket-protocol.md`](docs/websocket-protocol.md) and
[`docs/game-snapshot-format.md`](docs/game-snapshot-format.md).
