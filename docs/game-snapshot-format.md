# Current game snapshot format

This document records the array emitted by `GameState::toArray()` and consumed
by `GameState::restoreFromArray()` at the Phase 0 baseline. There is currently
no explicit snapshot schema-version field.

## Top-level fields

In current serialization order:

1. `phase`
2. `board`
3. `pot`
4. `currentBet`
5. `dealerSeat`
6. `smallBlindSeat`
7. `bigBlindSeat`
8. `smallBlindAmount`
9. `bigBlindAmount`
10. `actionSeat`
11. `lastRaiseSeat`
12. `lastRaiseAmount`
13. `players`
14. `lastHandResult`
15. `handIndex`
16. `handStartingStacks`
17. `deckSeed`
18. `dealer`

`phase` is the string value of the `Phase` enum. `board` is a list of card
codes. `players` is keyed by seat number. `dealer` is either `null` or the
exported `DealerService` state used to resume the remaining deck.

## Player fields

Each player entry currently contains:

1. `seat`
2. `stack`
3. `bet`
4. `folded`
5. `allIn`
6. `actedThisStreet`
7. `totalInvested`
8. `contribution`
9. `user_id`
10. `cards`
11. `handRank`
12. `handDescription`

The persisted snapshot includes private cards and dealer state. These fields
must not be sent in public WebSocket state projections.

`GameStateSnapshotCharacterizationTest` fixes the current keys and verifies a
JSON encode/decode/restore round trip. Phase 0 deliberately does not add schema
versioning or change persistence behavior.

## Persistence metadata

Persisted snapshots may additionally contain the internal boolean
`_handBootstrapped`. It records whether the current hand is active so a process
restart after `hand_end` can safely accept exactly one `next_hand` command.
This field is not part of `GameState::toArray()` and is excluded from public and
private WebSocket state projections.
