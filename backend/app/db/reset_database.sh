#!/bin/bash
# Reset database script - drops and recreates the poker database

set -e

# Load database config
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/../../.."

# Source database config (if using environment variables)
if [ -f "backend/config/db.php" ]; then
    # Extract DB config from PHP file
    DB_HOST=$(grep -oP "(?<=\\$DB_HOST = getenv\('DB_HOST'\) \?: ')[^']*" backend/config/db.php || echo "127.0.0.1")
    DB_NAME=$(grep -oP "(?<=\\$DB_NAME = getenv\('DB_NAME'\) \?: ')[^']*" backend/config/db.php || echo "pokergame")
    DB_USER=$(grep -oP "(?<=\\$DB_USER = getenv\('DB_USER'\) \?: ')[^']*" backend/config/db.php || echo "root")
    DB_PASS=$(grep -oP "(?<=\\$DB_PASS = getenv\('DB_PASS'\) \?: ')[^']*" backend/config/db.php || echo "")
else
    # Defaults
    DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_NAME="${DB_NAME:-pokergame}"
    DB_USER="${DB_USER:-root}"
    DB_PASS="${DB_PASS:-}"
fi

echo "========================================="
echo "Resetting Poker Database"
echo "========================================="
echo "Host: $DB_HOST"
echo "Database: $DB_NAME"
echo "User: $DB_USER"
echo ""
echo "⚠️  WARNING: This will DROP and recreate the database!"
echo "   All data will be lost!"
echo ""
read -p "Are you sure? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    echo "Cancelled."
    exit 1
fi

echo ""
echo "Dropping database..."
mysql -h"$DB_HOST" -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;"

echo "Creating database..."
mysql -h"$DB_HOST" -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} -e "CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "Loading schema..."
mysql -h"$DB_HOST" -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$DB_NAME" < backend/app/db/poker_schema.sql

echo ""
echo "✅ Database reset complete!"
echo ""

