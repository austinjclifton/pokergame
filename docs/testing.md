# Development and testing

## Prerequisites

- PHP 8.4.27 or newer within the PHP 8.4 release branch
- Composer 2
- MySQL 8
- Node.js 22.12 or newer on the Node.js 22 or 24 release line, and npm
- Application PHP extensions: `filter`, `hash`, `json`, `mbstring`, `PDO`, and
  `pdo_mysql`
- Test/tooling PHP extensions: `dom`, `iconv`, `libxml`, `pdo_sqlite`, `xml`,
  and `xmlwriter`

The Composer platform constraint is `~8.4.27`, which admits patched PHP 8.4
releases starting at 8.4.27 and excludes PHP 8.5. CI exercises the minimum
supported version. The backend remains on PHPUnit 12.

## Install dependencies

```bash
cd backend
composer install

cd ../frontend
npm ci
```

`backend/vendor` is currently present in the repository but tests and CI use
the committed Composer lock file as the dependency source of truth.

## Unit tests

Unit tests never load application database configuration and do not require
MySQL. A few game-service tests use an in-memory SQLite PDO connection, so
`pdo_sqlite` is required:

```bash
cd backend
vendor/bin/phpunit --configuration phpunit.xml
```

The legacy database-backed `tests/unit/AuditServiceTest.php` is explicitly
excluded from the unit suite and included in the integration suite.

## Integration-test database

Use a dedicated database whose name ends exactly in `_test`. Do not reuse a
development database. One possible local setup is:

```sql
CREATE DATABASE pokergame_local_test
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pokergame_test'@'localhost' IDENTIFIED BY 'choose-a-local-password';
GRANT ALL PRIVILEGES ON pokergame_local_test.* TO 'pokergame_test'@'localhost';
```

Load the current schema only after selecting the test database explicitly:

```bash
mysql -u pokergame_test -p pokergame_local_test < backend/app/db/poker_schema.sql
```

All five variables are required. An empty password is allowed only when
`TEST_DB_PASS` is explicitly present.

```bash
export TEST_DB_HOST=127.0.0.1
export TEST_DB_PORT=3306
export TEST_DB_NAME=pokergame_local_test
export TEST_DB_USER=pokergame_test
export TEST_DB_PASS=choose-a-local-password

cd backend
vendor/bin/phpunit --configuration phpunit.integration.xml
```

The integration bootstrap rejects missing settings, invalid ports, names that
do not end in `_test`, and connections whose active schema differs from the
requested schema. It does not read `.env`, `DB_*`, or application defaults.

Some integration tests temporarily disable foreign-key checks or delete rows.
Those operations occur only after the active connection has passed the `_test`
guard. Never invoke the database reset scripts as part of the test workflow.

## Other checks

```bash
cd backend
composer validate --no-check-publish
composer audit --locked --no-dev

cd ../frontend
npm run lint
npm run build
```

CI also syntax-checks every first-party PHP file. Composer auditing includes
development dependencies and is blocking.
