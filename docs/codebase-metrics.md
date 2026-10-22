# Phase 1C codebase baseline

This baseline was recorded on 2026-10-21 from the Phase 1C working tree. It is
intended to guide later incremental simplification, not to impose arbitrary
line-count targets.

## Scope and measurements

Generated assets, Composer dependencies, npm dependencies, tests, and
`backend/test_gameplay.php` are excluded. PHP application source includes
`backend/bootstrap.php` and PHP files under `backend/app`, `backend/config`,
`backend/lib`, `backend/public/api`, and `backend/ws`. Frontend application
source includes JavaScript and JSX under `frontend/src`.

| Source | Files | Lines |
| --- | ---: | ---: |
| PHP application | 59 | 10,350 |
| JavaScript/JSX application | 36 | 4,874 |
| Total | 95 | 15,224 |

These are physical line counts from `wc -l`, including comments and blank
lines. They are reproducible with:

```bash
php_files=$(find backend/app backend/config backend/lib backend/public/api backend/ws \
  -type f -name '*.php' ! -path '*/vendor/*' ! -path '*/tests/*' \
  ! -name 'test_gameplay.php' -print; printf '%s\n' backend/bootstrap.php)
js_files=$(find frontend/src -type f \( -name '*.js' -o -name '*.jsx' \) -print)
printf '%s\n' "$php_files" | xargs wc -l
printf '%s\n' "$js_files" | xargs wc -l
```

## Largest first-party files

| PHP file | Lines |
| --- | ---: |
| `backend/ws/GameSocket.php` | 1,480 |
| `backend/ws/LobbySocket.php` | 769 |
| `backend/lib/security.php` | 669 |
| `backend/app/services/game/GameService.php` | 524 |
| `backend/app/services/game/engine/BettingEngine.php` | 399 |

| JavaScript/JSX file | Lines |
| --- | ---: |
| `frontend/src/hooks/useGameSocket.js` | 1,079 |
| `frontend/src/pages/LobbyPage.jsx` | 362 |
| `frontend/src/components/game/ActionBar.jsx` | 330 |
| `frontend/src/components/game/HandResultOverlay.jsx` | 323 |
| `frontend/src/pages/GamePage.jsx` | 315 |

## Duplication and complexity candidates

These are candidates for later verified work, not Phase 1C behavior changes:

- `GameSocket.php` and `LobbySocket.php` repeat connection authentication,
  error-envelope construction, logging, and client-removal patterns. Small
  shared helpers could reduce this after protocol characterization remains in
  place.
- `useGameSocket.js` combines connection lifecycle, reconnection, protocol
  dispatch, game-state normalization, and UI-facing actions. Later extraction
  should begin at those existing responsibilities without changing the hook's
  public interface.
- HTTP API endpoints repeat method checks, JSON decoding, authentication and
  error response boilerplate. A minimal response/request helper is preferable
  to a framework migration.
- Database access code repeats prepared-statement and row-mapping patterns.
  Consolidation should stay within existing repositories/services and avoid an
  ORM.
- Game and player state are mapped in several WebSocket and service paths.
  Later work should establish one tested public/private serializer before
  deduplicating it.

## Obsolete branches removed in Phase 1C

- Hostname-based selection between local and RIT database credentials.
- Default MySQL root/student credentials and implicit database settings.
- Frontend URL inspection used to choose local versus RIT endpoints.
- The tracked RIT production endpoint file.
- macOS hostname guessing, RIT hostnames, and HAProxy-specific startup
  assumptions in the WebSocket launcher.
- Duplicate application-database reset scripts. Automated destructive database
  operations remain confined to the guarded integration-test bootstrap.

No poker rules, authentication semantics, database schema, WebSocket message
contract, snapshot format, or persistence behavior was changed while removing
these branches.
