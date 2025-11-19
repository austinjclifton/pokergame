# WebSocket Server Logging

## Current Log Output

The WebSocket server logs are currently output to **stdout/stderr** (the terminal where you run the server).

### Log Types

1. **Echo statements** (`echo "[MESSAGE]..."`) → stdout (terminal)
2. **Error log** (`error_log(...)`) → PHP error log (usually stderr or configured error log file)

## Viewing Logs

### Option 1: View in Terminal (Current Method)
Just run the server and watch the terminal:
```bash
php backend/ws/server.php
```

### Option 2: Redirect to File
Run the server and redirect output to a file:
```bash
php backend/ws/server.php > backend/ws/server.log 2>&1
```

Then view the log:
```bash
tail -f backend/ws/server.log
```

### Option 3: Use the Logging Script
Use the provided script that logs to a file AND shows output in terminal:
```bash
./backend/ws/run_server_with_logs.sh
```

Or specify a custom log file:
```bash
./backend/ws/run_server_with_logs.sh backend/ws/custom.log
```

### Option 4: View PHP Error Log
Check PHP's configured error log location:
```bash
php -i | grep error_log
```

Common locations:
- macOS: `/usr/local/var/log/php-fpm.log` or stderr
- Linux: `/var/log/php/error.log` or stderr
- Development: Usually stderr (same as terminal)

## Real-time Log Monitoring

To watch logs in real-time while the server is running:

```bash
# If logging to file
tail -f backend/ws/server.log

# If running in terminal, just watch the terminal output
```

## Finding Recent Errors

```bash
# Search for errors in log file
grep -i "error\|exception\|failed" backend/ws/server.log | tail -20

# Search for action-related logs
grep -i "\[ACTION\]" backend/ws/server.log | tail -20

# Search for disconnect logs
grep -i "\[DISCONNECT\]\|\[CLOSE\]" backend/ws/server.log | tail -20
```

