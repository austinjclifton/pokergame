# Table Cleanup Report
## Removed Tables Analysis

**Date:** Generated automatically  
**Tables Removed:**
- `actions`
- `game_actions`
- `game_hands`
- `game_state_snapshots`
- `hand_players`
- `user_game_results`
- `game_players` (to confirm)

---

## 🔴 CRITICAL ISSUES FOUND

### 1. **GameSocket.php** - BROKEN CODE
**File:** `backend/ws/GameSocket.php`  
**Line:** 612, 623, 627  
**Issue:** Calls `db_get_actions()` which queries the removed `game_actions` table, and calls `rebuildFromActions()` which doesn't exist in GameService.

**Code Snippet:**
```php
// Line 612
$actions  = db_get_actions($this->pdo, $gameId);

// Line 623
$gameService->rebuildFromActions(array_values($postSnap));

// Line 627
$gameService->rebuildFromActions($actions);
```

**Action Required:** 
- Remove calls to `db_get_actions()` (table doesn't exist)
- Remove calls to `rebuildFromActions()` (method doesn't exist)
- Update `rebuildFromDatabase()` to use snapshot-only recovery

---

## 📋 DETAILED FINDINGS BY FILE

### **backend/app/db/game_actions.php** - ENTIRE FILE NEEDS DELETION/REWRITE

This file contains extensive references to removed tables:

#### Functions referencing `actions` table:
1. **`db_insert_game_action()`** (lines 29-58)
   - SQL: `INSERT INTO actions (...)`
   - **Status:** DELETE - Table doesn't exist

2. **`db_get_next_seq_no()`** (lines 67-77)
   - SQL: `SELECT ... FROM actions WHERE hand_id = ...`
   - **Status:** DELETE - Table doesn't exist

3. **`db_get_hand_actions()`** (lines 86-96)
   - SQL: `SELECT * FROM actions WHERE hand_id = ...`
   - **Status:** DELETE - Table doesn't exist

#### Functions referencing `game_state_snapshots` table:
4. **`db_get_game_state_snapshot()`** (lines 149-177)
   - SQL: `SELECT ... FROM game_state_snapshots WHERE game_id = ...`
   - **Status:** DELETE - Table doesn't exist

5. **`db_save_game_state_snapshot()`** (lines 192-223)
   - SQL: `INSERT INTO game_state_snapshots (...)`
   - **Status:** DELETE - Table doesn't exist

#### Functions referencing `game_hands` table:
6. **`db_get_actions_since()`** (lines 233-248)
   - SQL: `SELECT a.* FROM actions a INNER JOIN game_hands h ON a.hand_id = h.id`
   - **Status:** DELETE - Both tables don't exist

7. **`db_rebuild_state()`** (lines 258-362)
   - SQL: `SELECT ... FROM game_hands h WHERE h.game_id = ...`
   - SQL: `SELECT seat, stack, user_id FROM game_players WHERE game_id = ...`
   - **Status:** DELETE - References `game_hands` and `game_players` tables

#### Functions referencing `game_actions` table:
8. **`db_insert_action()`** (lines 399-416)
   - SQL: `INSERT INTO game_actions (...)`
   - **Status:** DELETE - Table doesn't exist

9. **`db_get_actions()`** (lines 425-451)
   - SQL: `SELECT ... FROM game_actions WHERE game_id = ...`
   - **Status:** DELETE - Table doesn't exist, but currently called from GameSocket.php

10. **`db_get_last_seq()`** (lines 460-472)
    - SQL: `SELECT COALESCE(MAX(seq), 0) FROM game_actions WHERE game_id = ...`
    - **Status:** DELETE - Table doesn't exist

**Action Required:** 
- **Option 1:** Delete entire file if no functions are needed
- **Option 2:** Keep only `db_increment_game_version()` function (lines 106-140) if still needed, delete rest

---

### **backend/app/services/game/GamePersistence.php** - NEEDS UPDATES

**File:** `backend/app/services/game/GamePersistence.php`  
**Line:** 5, 38, 98

**Issues:**
1. **Line 5:** `require_once __DIR__ . '/../../db/game_actions.php';`
   - **Status:** REMOVE - File contains broken functions

2. **Line 38:** `return db_insert_action(...);`
   - **Status:** REMOVE or REWRITE - Function queries removed `game_actions` table
   - **Note:** If action logging is still needed, must use new architecture

3. **Line 98:** `return db_rebuild_state($this->pdo, $gameId, $engine);`
   - **Status:** REMOVE or REWRITE - Function references removed tables (`game_hands`, `game_players`, `actions`)

**Action Required:**
- Remove `require_once` for `game_actions.php`
- Remove or rewrite `recordAction()` method (line 30-47)
- Remove or rewrite `rebuild()` method (line 96-99) - should use snapshot-only recovery

---

### **backend/ws/GameSocket.php** - NEEDS UPDATES

**File:** `backend/ws/GameSocket.php`  
**Lines:** 27, 612, 617-628

**Issues:**
1. **Line 27:** `require_once __DIR__ . '/../app/db/game_actions.php';`
   - **Status:** REMOVE - File contains broken functions

2. **Lines 612-628:** `rebuildFromDatabase()` method
   - Calls `db_get_actions()` which queries removed `game_actions` table
   - Calls `$gameService->rebuildFromActions()` which doesn't exist
   - **Status:** REWRITE - Should use snapshot-only recovery

**Code Snippet:**
```php
// Line 612
$actions  = db_get_actions($this->pdo, $gameId);

// Lines 617-628
if ($snapshot && !empty($snapshot['state'])) {
    $this->restoreFromSnapshot($gameService, $snapshot['state'], (int)$snapshot['version']);
    
    if (!empty($actions)) {
        $postSnap = array_filter(
            $actions,
            fn($a) => (int)$a['seq'] > (int)$snapshot['version']
        );
        if (!empty($postSnap)) {
            $gameService->rebuildFromActions(array_values($postSnap)); // ❌ Method doesn't exist
        }
    }
} elseif (!empty($actions)) {
    $gameService->rebuildFromActions($actions); // ❌ Method doesn't exist
}
```

**Action Required:**
- Remove `require_once` for `game_actions.php`
- Rewrite `rebuildFromDatabase()` to use snapshot-only recovery (no action replay)

---

### **backend/tests/integration/db/GameActionsDBTest.php** - DELETE ENTIRE FILE

**File:** `backend/tests/integration/db/GameActionsDBTest.php`  
**Status:** DELETE ENTIRE FILE

**Reason:** 
- Entire test file tests functions that query removed `game_actions` table
- All test methods call `db_insert_action()`, `db_get_actions()`, `db_get_last_seq()` which are broken
- Line 38: `$this->pdo->exec("DELETE FROM game_actions");` - Table doesn't exist

**Action Required:** DELETE FILE

---

### **backend/tests/integration/websocket/GameRecoveryTest.php** - NEEDS UPDATES

**File:** `backend/tests/integration/websocket/GameRecoveryTest.php`  
**Lines:** 167, 237-245

**Issues:**
1. **Line 167:** `require_once __DIR__ . '/../../../app/db/game_actions.php';`
   - **Status:** REMOVE

2. **Lines 237-245:** Test queries removed `actions` table
   ```php
   $stmt = $this->pdo->prepare("
       SELECT COUNT(*) as count
       FROM actions
       WHERE game_id = :game_id
   ");
   ```
   - **Status:** REMOVE or REWRITE test - Table doesn't exist

**Action Required:**
- Remove `require_once` for `game_actions.php`
- Remove or rewrite `testActionsArePersistedAndReplayable()` test method

---

### **backend/tests/integration/websocket/GamePersistenceIntegrationTest.php** - NEEDS UPDATES

**File:** `backend/tests/integration/websocket/GamePersistenceIntegrationTest.php`  
**Lines:** 131, 139, 217

**Issues:**
1. **Line 131:** `require_once __DIR__ . '/../../../app/db/game_actions.php';`
   - **Status:** REMOVE

2. **Line 139:** `$this->pdo->exec("DELETE FROM game_actions");`
   - **Status:** REMOVE - Table doesn't exist

3. **Line 217:** `$actions = db_get_actions($this->pdo, $this->tableId);`
   - **Status:** REMOVE or REWRITE - Function queries removed table

**Action Required:**
- Remove `require_once` for `game_actions.php`
- Remove `DELETE FROM game_actions` cleanup
- Remove or rewrite test that uses `db_get_actions()`

---

### **backend/tests/integration/websocket/GameRejoinTest.php** - NEEDS UPDATES

**File:** `backend/tests/integration/websocket/GameRejoinTest.php`  
**Lines:** 131, 140, 225

**Issues:**
1. **Line 131:** `require_once __DIR__ . '/../../../app/db/game_actions.php';`
   - **Status:** REMOVE

2. **Line 140:** `$this->pdo->exec("DELETE FROM game_actions");`
   - **Status:** REMOVE - Table doesn't exist

3. **Line 225:** Comment references `game_actions` table
   - **Status:** UPDATE comment

**Action Required:**
- Remove `require_once` for `game_actions.php`
- Remove `DELETE FROM game_actions` cleanup
- Update comment on line 225

---

### **backend/tests/integration/websocket/GameSocketTest.php** - NEEDS UPDATES

**File:** `backend/tests/integration/websocket/GameSocketTest.php`  
**Line:** 166

**Issue:**
1. **Line 166:** `require_once __DIR__ . '/../../../app/db/game_actions.php';`
   - **Status:** REMOVE

**Action Required:**
- Remove `require_once` for `game_actions.php`

---

### **DOCUMENTATION.md** - NEEDS UPDATE

**File:** `DOCUMENTATION.md`  
**Line:** 524

**Issue:**
1. **Line 524:** `- \`game_players\` - Players in games`
   - **Status:** REMOVE - Table doesn't exist

**Action Required:**
- Remove line 524 from documentation

---

## ✅ SAFE TO DELETE (No References Found)

The following tables have **NO REFERENCES** found in the codebase:
- ✅ `hand_players` - No references found
- ✅ `user_game_results` - No references found

---

## 📝 SUMMARY BY ACTION TYPE

### **DELETE ENTIRE FILES:**
1. `backend/tests/integration/db/GameActionsDBTest.php` - All tests reference removed tables

### **DELETE/REWRITE ENTIRE FILES:**
2. `backend/app/db/game_actions.php` - Most functions reference removed tables
   - **Exception:** May keep `db_increment_game_version()` if still needed

### **REMOVE REQUIRE STATEMENTS:**
3. `backend/app/services/game/GamePersistence.php` - Line 5
4. `backend/ws/GameSocket.php` - Line 27
5. `backend/tests/integration/websocket/GameRecoveryTest.php` - Line 167
6. `backend/tests/integration/websocket/GamePersistenceIntegrationTest.php` - Line 131
7. `backend/tests/integration/websocket/GameRejoinTest.php` - Line 131
8. `backend/tests/integration/websocket/GameSocketTest.php` - Line 166

### **REWRITE FUNCTIONS/METHODS:**
9. `backend/app/services/game/GamePersistence.php::recordAction()` - Remove or rewrite
10. `backend/app/services/game/GamePersistence.php::rebuild()` - Remove or rewrite
11. `backend/ws/GameSocket.php::rebuildFromDatabase()` - Rewrite to use snapshot-only recovery

### **REMOVE TEST CLEANUP CODE:**
12. `backend/tests/integration/websocket/GamePersistenceIntegrationTest.php` - Line 139
13. `backend/tests/integration/websocket/GameRejoinTest.php` - Line 140

### **UPDATE/REMOVE TESTS:**
14. `backend/tests/integration/websocket/GameRecoveryTest.php::testActionsArePersistedAndReplayable()` - Remove or rewrite
15. `backend/tests/integration/websocket/GamePersistenceIntegrationTest.php` - Remove test using `db_get_actions()`

### **UPDATE DOCUMENTATION:**
16. `DOCUMENTATION.md` - Remove reference to `game_players` table

---

## 🚨 CRITICAL: Missing Method

**Issue:** `GameSocket.php` calls `$gameService->rebuildFromActions()` but this method **does not exist** in `GameService.php`.

**Impact:** Code will crash at runtime when trying to rebuild game state.

**Fix Required:** 
- Remove calls to `rebuildFromActions()` 
- Use snapshot-only recovery instead

---

## 📊 REFERENCE COUNT SUMMARY

| Table Name | References Found | Status |
|------------|------------------|--------|
| `actions` | 8+ (in game_actions.php) | ❌ DELETE |
| `game_actions` | 20+ | ❌ DELETE |
| `game_hands` | 2 | ❌ DELETE |
| `game_state_snapshots` | 2 | ❌ DELETE |
| `hand_players` | 0 | ✅ SAFE |
| `user_game_results` | 0 | ✅ SAFE |
| `game_players` | 2 | ❌ DELETE |

---

## 🔧 RECOMMENDED CLEANUP ORDER

1. **First:** Fix `GameSocket.php::rebuildFromDatabase()` - Remove broken `db_get_actions()` and `rebuildFromActions()` calls
2. **Second:** Update `GamePersistence.php` - Remove broken function calls
3. **Third:** Delete `backend/app/db/game_actions.php` (or keep only `db_increment_game_version()`)
4. **Fourth:** Delete `backend/tests/integration/db/GameActionsDBTest.php`
5. **Fifth:** Update all test files to remove `require_once` and cleanup code
6. **Sixth:** Update `DOCUMENTATION.md`

---

**END OF REPORT**

