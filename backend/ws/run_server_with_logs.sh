#!/bin/bash
# Run WebSocket server with logs redirected to a file
# Usage: ./run_server_with_logs.sh [log_file]

LOG_FILE="${1:-backend/ws/server.log}"

echo "Starting WebSocket server..."
echo "Logs will be written to: $LOG_FILE"
echo "Press Ctrl+C to stop"
echo ""

# Create log directory if it doesn't exist
mkdir -p "$(dirname "$LOG_FILE")"

# Run server and redirect both stdout and stderr to log file
# Also output to terminal with timestamps
php backend/ws/server.php 2>&1 | tee -a "$LOG_FILE" | while IFS= read -r line; do
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $line"
done

