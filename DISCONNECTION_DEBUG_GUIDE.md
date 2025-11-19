# Disconnection Debugging Guide

## What Was Added

I've added comprehensive logging to track **exactly which disconnection path** is being triggered. All disconnection events now log with a `PATH:` prefix indicating the reason.

## Logging Format

### Authentication Layer (`[AUTH_DISCONNECT]`)
- `MISSING_HANDSHAKE_REQUEST` - No HTTP request object
- `AUTH_FAILED` - Invalid/expired token or session
- `CONNECTION_LIMIT_EXCEEDED` - IP/user/total limit hit
- `EXCEPTION_IN_AUTH_ONOPEN` - Exception during authentication
- `UNAUTHORIZED_MESSAGE` - Message without userCtx
- `TRANSPORT_ERROR` - WebSocket transport error

### Game Socket Layer (`[DISCONNECT]` or `[CLOSE]`)
- `MISSING_USERCTX` - No user context (UNAUTHORIZED)
- `MISSING_HTTP_REQUEST` - No HTTP request
- `INVALID_TABLE_ID` - Invalid table ID
- `NOT_SEATED` - User not seated at table
- `RECONNECT_CLOSING_OLD` - Old connection closed during reconnect
- `SEAT_CONFLICT` - Different user in same seat
- `EXCEPTION_IN_ONOPEN` - Exception during connection
- `NO_CONN_INFO` - Connection info not found in onMessage
- `WEBSOCKET_ERROR` - WebSocket error
- `RECONNECT_REPLACEMENT` - Connection closed because new one exists
- `NATURAL_DISCONNECT` - Normal client disconnect (browser close, network drop)
- `UNTRACKED_CONNECTION` - Connection closed but not in tracking

## How to Use

1. **Restart the WebSocket server** to enable new logging:
   ```bash
   # Kill existing server
   pkill -f "php.*server.php"
   
   # Start with new logging
   php backend/ws/server.php
   ```

2. **Watch the logs** in real-time:
   ```bash
   # In another terminal, watch for disconnect patterns
   tail -f /path/to/your/logs | grep -E "DISCONNECT|CLOSE|AUTH_DISCONNECT"
   ```

3. **Reproduce the issue** - Connect to a game and trigger the disconnect

4. **Look for patterns**:
   - If you see `RECONNECT_CLOSING_OLD` → Normal reconnect behavior
   - If you see `NATURAL_DISCONNECT` → Client-initiated (browser refresh, network issue)
   - If you see `EXCEPTION_IN_ONOPEN` → Bug in connection logic
   - If you see `AUTH_FAILED` → Token/session expired
   - If you see `CONNECTION_LIMIT_EXCEEDED` → Too many connections

## Expected Output Examples

### Normal Reconnect (Expected):
```
[OPEN] admin connected to table #35 (game #18, seat 2)
[DISCONNECT] User 1 (admin) - PATH: RECONNECT_CLOSING_OLD (oldRid=123, newRid=124)
[CLOSE] admin (seat 2) disconnected from table #35 - PATH: RECONNECT_REPLACEMENT (new connection exists)
```

### Natural Disconnect (Expected):
```
[CLOSE] admin (seat 2) disconnected from table #35 (game #18) - PATH: NATURAL_DISCONNECT
```

### Error Disconnect (Needs Investigation):
```
[DISCONNECT] User 1 - PATH: EXCEPTION_IN_ONOPEN - Call to undefined method...
[CLOSE] admin disconnected - PATH: NATURAL_DISCONNECT
```

## Next Steps

1. **Restart the server** with the new logging
2. **Reproduce the disconnect issue**
3. **Check the logs** to see which PATH is being triggered
4. **Share the logs** so we can identify the root cause

The logs will now clearly show whether disconnects are:
- Normal reconnects (expected)
- Client-initiated (browser refresh)
- Server errors (bugs to fix)
- Authentication issues (token problems)
- Network issues (timeouts)

