# useGameSocket Debugging Report

## 1. Import Map - All Places useGameSocket is Imported

| File | Import Statement | Status |
|------|------------------|--------|
| `/frontend/src/pages/GamePage.jsx` | `import useGameSocket from "../hooks/useGameSocket";` | ✅ Found (line 14) |
| `/frontend/src/hooks/useGameSocket.js` | N/A (exported from here) | ✅ Exists |

**Total imports found: 1**

## 2. File Path Verification

✅ **useGameSocket.js exists**: `/frontend/src/hooks/useGameSocket.js` (18,067 bytes, modified Nov 27 14:05)
✅ **GamePage.jsx exists**: `/frontend/src/pages/GamePage.jsx` (10,099 bytes, modified Nov 27 14:05)
✅ **Import path resolves correctly**: `../hooks/useGameSocket` from `GamePage.jsx` → `src/hooks/useGameSocket.js`

## 3. Component Tree

```
App.jsx
  └─ Router
      └─ Routes
          └─ Route: /game/:tableId
              └─ GamePage (protected, requires user)
                  ├─ useGameSocket(tableId, onError) ← HOOK CALLED HERE (line 53)
                  ├─ GameHeader
                  ├─ GameTable ← Receives gameState from hook
                  ├─ ActionBar
                  └─ GameChatBox
```

## 4. Critical Code Paths

### GamePage.jsx Hook Call (Line 45-53)
```javascript
const {
  gameState,
  sendAction,
  sendChat,
  requestSync,
  disconnect,
  connected,
  reconnecting,
} = useGameSocket(tableId, (err) => setError(err));
```

**⚠️ IMPORTANT**: The hook is called BEFORE the early return check (line 127), so it WILL execute even if tableId is invalid.

### Early Return Check (Line 127-133)
```javascript
if (!tableId) {
  return (
    <div className="game-page">
      <div className="game-error">Invalid table ID</div>
    </div>
  );
}
```

**Note**: This early return happens AFTER the hook is called, so the hook will still initialize even with invalid tableId.

### Route Protection (App.jsx Line 86-87)
```javascript
<Route
  path="/game/:tableId"
  element={user ? <GamePage user={user} /> : <Navigate to="/login" replace />}
/>
```

**⚠️ IMPORTANT**: GamePage only renders if `user` is truthy. If user is null/undefined, it redirects to login.

## 5. Debug Logs Added

### ✅ useGameSocket.js
- **Line 1**: `console.log("%c[HIT] useGameSocket.js loaded", ...)` - File loaded
- **Line 50**: `console.log("%c[HIT] useGameSocket() function called", ...)` - Hook executed

### ✅ GamePage.jsx  
- **Line 12**: `console.log("%c[HIT] GamePage.jsx loaded", ...)` - File loaded
- **Line 30**: `console.log("%c[HIT] GamePage.jsx mounted", ...)` - Component mounted
- **Line 35**: `console.log("%c[HIT] GamePage.jsx - tableId from params", ...)` - tableId extracted
- **Line 47**: `console.log("%c[HIT] GamePage.jsx - About to call useGameSocket", ...)` - Before hook call
- **Line 56**: `console.log("%c[HIT] GamePage.jsx - useGameSocket returned", ...)` - After hook call

### ✅ GameTable.jsx
- **Line 1**: `console.log("%c[HIT] GameTable.jsx loaded", ...)` - File loaded
- **Line 10**: `console.log("%c[HIT] GameTable.jsx rendered", ...)` - Component rendered

## 6. Common Issues Checklist

### ❓ Issue 1: Stale Build in /dist
**Location**: `/frontend/dist/` contains compiled files
**Check**: Is Apache/Vite serving from `/dist` instead of dev server?
**Solution**: 
- Ensure Vite dev server is running (`npm run dev`)
- Check browser is connecting to Vite dev server (usually `http://localhost:5173`)
- Clear browser cache (Cmd+Shift+R / Ctrl+Shift+R)

### ❓ Issue 2: User Not Authenticated
**Check**: Is `user` prop null/undefined when GamePage tries to render?
**Solution**: Check browser console for redirect to `/login`

### ❓ Issue 3: Route Not Matching
**Check**: Is URL exactly `/game/:tableId` format?
**Solution**: Verify URL in browser matches pattern

### ❓ Issue 4: Component Not Mounting
**Check**: Does `[HIT] GamePage.jsx mounted` appear in console?
**Solution**: If not, component is not rendering (check route/user/auth)

### ❓ Issue 5: Hook Not Called
**Check**: Does `[HIT] useGameSocket() function called` appear in console?
**Solution**: If file loads but function doesn't call, check for:
- Early return before hook call (NOT the case here)
- Conditional hook call (NOT the case here)
- Hook called inside conditional (NOT the case here)

### ❓ Issue 6: Import Path Case Sensitivity
**Check**: File system is case-sensitive (macOS/Linux)
**Current**: `useGameSocket.js` (correct case)
**Import**: `"../hooks/useGameSocket"` (correct case)
**Status**: ✅ Match

### ❓ Issue 7: Vite Config Issues
**Check**: `vite.config.js` has no aliases that might interfere
**Status**: ✅ No aliases found

## 7. Testing Steps

1. **Clear browser cache** (Cmd+Shift+R / Ctrl+Shift+R)
2. **Open browser console** (F12)
3. **Navigate to** `/game/123` (or any tableId)
4. **Check console for logs in this order**:
   - ✅ `[HIT] useGameSocket.js loaded` (green)
   - ✅ `[HIT] GamePage.jsx loaded` (blue)
   - ✅ `[HIT] GamePage.jsx mounted` (blue)
   - ✅ `[HIT] GamePage.jsx - tableId from params` (blue)
   - ✅ `[HIT] GamePage.jsx - About to call useGameSocket` (blue)
   - ✅ `[HIT] useGameSocket() function called` (green)
   - ✅ `[HIT] GamePage.jsx - useGameSocket returned` (blue)
   - ✅ `[HIT] GameTable.jsx loaded` (purple)
   - ✅ `[HIT] GameTable.jsx rendered` (purple)

5. **If logs stop at a certain point**, that's where the issue is:
   - No logs at all → File not loading (stale build/cache)
   - Stops at "GamePage.jsx loaded" → Component not mounting (route/auth issue)
   - Stops at "About to call useGameSocket" → Hook call failing (syntax error?)
   - Stops at "useGameSocket returned" → Hook executing but not completing

## 8. Vite Dev Server Check

**Command**: `cd frontend && npm run dev`
**Expected**: Server starts on `http://localhost:5173` (or similar)
**Check**: Browser is accessing dev server, not Apache-served `/dist` folder

## 9. Browser Cache Bypass

If logs don't appear:
1. Open DevTools → Network tab
2. Check "Disable cache" checkbox
3. Hard refresh (Cmd+Shift+R / Ctrl+Shift+R)
4. Or use Incognito/Private window

## 10. Next Steps

Based on which logs appear:
- **No logs**: Check Vite dev server is running, browser is using dev server
- **File loads but component doesn't mount**: Check route/auth/user state
- **Component mounts but hook doesn't call**: Check for syntax errors in hook file
- **Hook calls but doesn't complete**: Check hook's useEffect dependencies and conditions

