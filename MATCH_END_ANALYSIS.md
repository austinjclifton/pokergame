# Match End Handling Analysis

## Issues Found

### 🔴 **CRITICAL ISSUES**

#### 1. **Race Condition: Actions After Match End**
**Location**: `GameSocket.php::handleAction()` (line 365-369)

**Problem**: After match end, `gameServices[$tableId]` is unset (line 430), but if a player sends an action between the match end detection and the cleanup, they'll get `'game_not_found'` error instead of `'match_ended'`.

**Impact**: Poor UX - user sees confusing error instead of match end message.

**Fix**: Check if match has ended before processing action, or return a specific `'match_ended'` error instead of `'game_not_found'`.

---

#### 2. **Incomplete Cleanup in `handleNextHand()`**
**Location**: `GameSocket.php::handleNextHand()` (line 499-519)

**Problem**: When match ends in `handleNextHand()`, it doesn't:
- Unset `gameServices[$tableId]`
- Unset `tableIdToGameId[$tableId]`
- Unset `tableBootstrapped[$tableId]`

**Impact**: Memory leak, stale references, potential for actions on ended match.

**Fix**: Add the same cleanup as in `handleAction()` (lines 429-433).

---

#### 3. **Incomplete Cleanup in `ensureHandBootstrapped()`**
**Location**: `GameSocket.php::ensureHandBootstrapped()` (line 1161-1174)

**Problem**: When match ends during bootstrap, it doesn't clean up:
- `gameServices[$tableId]`
- `tableIdToGameId[$tableId]`
- `tableBootstrapped[$tableId]`

**Impact**: Same as #2 - memory leak and stale references.

**Fix**: Add cleanup after broadcasting match_end.

---

### 🟡 **MODERATE ISSUES**

#### 4. **Frontend Doesn't Block Actions After Match End**
**Location**: `useGameSocket.js::sendAction()` (line 76-95)

**Problem**: The function checks `matchEndedRef.current` but this is only set AFTER receiving the `match_end` event. If user clicks action button quickly after match ends, the action will be sent.

**Impact**: Unnecessary network traffic, potential confusion.

**Fix**: Check `gameState.matchEnded` in addition to `matchEndedRef.current`, or disable action buttons immediately when match ends.

---

#### 5. **No Validation of Winner/Loser Data**
**Location**: `GameSocket.php::handleAction()` (line 422-426), `handleNextHand()` (line 512-516), `ensureHandBootstrapped()` (line 1167-1171)

**Problem**: If `detectMatchEnd()` somehow returns `matchEnded=true` but `winner` or `loser` are null, the broadcast will send null values to frontend.

**Impact**: Frontend may crash or show broken UI.

**Fix**: Add validation before broadcasting:
```php
if (!$winner || !$loser) {
    error_log("[GameSocket] Match end detected but winner/loser missing");
    // Handle error case
}
```

---

#### 6. **Potential Null Reference in `detectMatchEnd()`**
**Location**: `GameService.php::detectMatchEnd()` (line 246-254)

**Problem**: If there's only one player in `$this->state->players`, the loser loop might not find a loser (though logically it should).

**Impact**: `$loser` could be null, causing issue #5.

**Fix**: Add explicit check:
```php
if ($loser === null) {
    error_log("[GameService] detectMatchEnd: Could not find loser");
    return null; // or handle error
}
```

---

### 🟢 **MINOR ISSUES**

#### 7. **No Explicit Connection Cleanup**
**Location**: `GameSocket.php` - all match end paths

**Problem**: When match ends, connections remain open. Players stay connected but can't do anything useful.

**Impact**: Wasted resources, but not critical since they'll disconnect naturally.

**Fix**: Optionally send a close message or disconnect after a delay.

---

#### 8. **Transaction Not Rolled Back on Match End**
**Location**: `GameSocket.php::handleAction()` (line 383-392)

**Problem**: If match ends, transaction is committed, then cleanup happens. If cleanup fails, we've already committed.

**Impact**: Low - cleanup operations are idempotent, but could leave inconsistent state.

**Fix**: Consider wrapping cleanup in try-catch or moving it before commit.

---

#### 9. **Missing Error Handling in Broadcast**
**Location**: `GameSocket.php::broadcast()` (line 1069-1085)

**Problem**: If broadcast fails for all connections, match end message is lost. No retry or logging of failure.

**Impact**: Players might not see match end screen.

**Fix**: Log broadcast failures, consider retry mechanism or at least log when all broadcasts fail.

---

#### 10. **Frontend State Not Fully Reset**
**Location**: `useGameSocket.js::handleMatchEnd()` (line 355-371)

**Problem**: Sets `matchEnded: true` but doesn't clear other game state like `seats`, `community`, etc.

**Impact**: UI might show stale game data on match end screen.

**Fix**: Clear or reset all game-related state when match ends.

---

## Recommended Fixes (Priority Order)

### Priority 1 (Critical)
1. Add cleanup in `handleNextHand()` match end path
2. Add cleanup in `ensureHandBootstrapped()` match end path
3. Add validation for winner/loser before broadcasting

### Priority 2 (Important)
4. Check match end status before processing actions
5. Add null check in `detectMatchEnd()` for loser
6. Block actions in frontend when match ended

### Priority 3 (Nice to Have)
7. Improve error handling in broadcast
8. Reset frontend state completely on match end
9. Consider explicit connection cleanup

---

## Code Patterns to Follow

### Consistent Cleanup Pattern
When match ends, always:
```php
// 1. Save snapshot (if needed)
// 2. Clean up DB
db_delete_game($this->pdo, $gameId);
db_delete_snapshots($this->pdo, $gameId);
db_clear_table_seats($this->pdo, $tableId);

// 3. Broadcast match_end
$this->broadcast($tableId, [
    'event'  => 'match_end',
    'winner' => $winner,
    'loser'  => $loser,
]);

// 4. Clean up in-memory state
unset(
    $this->gameServices[$tableId],
    $this->tableIdToGameId[$tableId],
    $this->tableBootstrapped[$tableId]
);

// 5. Process pending disconnects
$this->processPendingDisconnects();
```

### Validation Pattern
Before broadcasting match_end:
```php
if (!$matchEnded) {
    // Normal flow
    return;
}

// Validate winner/loser
if (!$winner || !$loser) {
    error_log("[GameSocket] Match end without winner/loser");
    $this->sendError($from, 'server_error', 'Match end data invalid');
    return;
}

// Proceed with cleanup and broadcast
```

