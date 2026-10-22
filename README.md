# pokergame

A 1v1 poker simulation game built with application security as its main objective.

## Local development

The complete macOS setup, configuration, startup, shutdown, and manual
end-to-end verification workflow is in
[`docs/local-development.md`](docs/local-development.md).

Application database settings are always explicit. Copy `.env.example` to the
ignored `.env` file and load it into each PHP terminal before starting the HTTP
or WebSocket server. No hostname, RIT, DigitalOcean, or HAProxy detection is
used.

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

Additional prerequisites and test-database instructions are documented in
[`docs/testing.md`](docs/testing.md). The WebSocket baseline and persisted
state format are recorded in
[`docs/websocket-protocol.md`](docs/websocket-protocol.md) and
[`docs/game-snapshot-format.md`](docs/game-snapshot-format.md).

The opaque-session, WebSocket-token, proxy, nonce, throttle, and administrator
security model is documented in
[`docs/authentication-security.md`](docs/authentication-security.md).
