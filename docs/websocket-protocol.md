# Current WebSocket protocol

This document records the protocol at the Phase 0 baseline. It is a
characterization of existing behavior, not a redesigned contract.

All frames are JSON objects with a case-sensitive `type` field unless noted.
Authentication occurs during the HTTP upgrade through `AuthenticatedServer`.
The game connection also requires `table_id` in the request query string.

As of Phase 2, both lobby and game upgrades require an exact allowed `Origin`
and a 64-character `token` query parameter obtained from `POST
/api/ws_token.php`. The token is 256-bit, expires after 30 seconds, is stored
only as a hash, and can be consumed once. Persistent cookies and numeric
session identifiers are not accepted by the WebSocket server. This narrows the
handshake contract without changing any JSON message envelope below.

## Lobby client messages

| Type | Current fields |
|---|---|
| `ping` | `type` |
| `chat` | `type`, `msg` |
| `logout` | `type` |
| `challenge` | `type`, `to_user_id` |
| `challenge_response` | `type`, `challenge_id`, `action` (`accept` or `decline`) |
| `challenge_cancel` | `type`, `challenge_id` |

Lobby server messages currently include `pong`, `history`, `online_users`,
`presence`, `chat`, `challenge`, `challenge_sent`, `challenge_response`,
`challenge_resolved`, `challenge_cancel`, `GAME_START`, and `error`.

Errors use `{ "type": "error", "error": "machine_code" }` with optional
additional fields. Lobby chat messages use lowercase `chat`; game chat uses
uppercase `CHAT`.

## Game client messages

| Type | Current fields |
|---|---|
| `ping` | `type` |
| `action` | `type`, `action`, `amount`, `game_version` |
| `next_hand` | `type`, optional `game_version` |
| `chat` | `type`, `msg` |
| `forfeit` | `type` |

Game server messages include:

- `STATE_SYNC`: `type`, `reason`, `game_id`, `version`, `state`
- `STATE_DIFF`: `type`, `game_id`, `version`, `state`
- `STATE_PRIVATE`: `type`, usually `game_id`, `seat`, and `state`
- `PLAYER_CONNECTED`, `PLAYER_AWAY`, `CHAT`, `CHAT_HISTORY`, `pong`, and `error`
- hand completion is currently also broadcast as an event envelope containing
  `event: "hand_end"` and `summary`, without a `type` field
- `hand_start` originates from `GameService` with `type`, `state`, and `version`

The public state contains board and seat-visible information but excludes hole
cards and dealer internals. `STATE_PRIVATE.state` adds the receiving player's
`myCards` and `legalActions`. Existing integration tests characterize this
separation in `tests/integration/websocket/GameSocketTest.php`.

Message names and casing are intentionally recorded as-is. Phase 0 does not
normalize them.
