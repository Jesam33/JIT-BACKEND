#!/usr/bin/env bash
set -euo pipefail

# migrate_and_assign.sh
# Usage: bash ./scripts/migrate_and_assign.sh
# If using Git Bash on Windows and you need winpty for php.exe, run:
#   winpty bash ./scripts/migrate_and_assign.sh

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$SCRIPT_DIR/.."
cd "$PROJECT_ROOT"

read_env() {
  local key="$1"
  if [[ ! -f .env ]]; then
    echo ""
    return
  fi
  local val
  val=$(grep -E "^${key}=" .env | head -n1 | sed -E "s/^${key}=(.*)/\1/") || true
  # strip surrounding quotes
  val="${val%\"}"
  val="${val#\"}"
  echo "$val"
}

DB_HOST=$(read_env "DB_HOST")
DB_PORT=$(read_env "DB_PORT")
DB_NAME=$(read_env "DB_DATABASE")
DB_USER=$(read_env "DB_USERNAME")
DB_PASS=$(read_env "DB_PASSWORD")

DB_PORT=${DB_PORT:-3306}
DB_USER=${DB_USER:-root}

if [[ -z "$DB_HOST" || -z "$DB_NAME" ]]; then
  echo ".env DB_HOST or DB_DATABASE not found. Aborting." >&2
  exit 1
fi

echo "DB host: ${DB_HOST}:${DB_PORT}, DB: ${DB_NAME}, User: ${DB_USER}"

mkdir -p backups
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="backups/backup_${DB_NAME}_${TIMESTAMP}.sql"

if command -v mysqldump >/dev/null 2>&1; then
  echo "Creating DB backup to ${BACKUP_FILE} ..."
  if [[ -n "$DB_PASS" ]]; then
    mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$BACKUP_FILE" || echo "mysqldump failed (exit $?), continue with caution"
  else
    mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" > "$BACKUP_FILE" || echo "mysqldump failed (exit $?), continue with caution"
  fi
else
  echo "mysqldump not found in PATH — skipping DB dump. Export manually via phpMyAdmin or Laragon before continuing." >&2
fi

if command -v mysql >/dev/null 2>&1; then
  echo "Ensuring database ${DB_NAME} exists..."
  if [[ -n "$DB_PASS" ]]; then
    mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE IF NOT EXISTS \\`$DB_NAME\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || echo "mysql returned non-zero exit code"
  else
    mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -e "CREATE DATABASE IF NOT EXISTS \\`$DB_NAME\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || echo "mysql returned non-zero exit code"
  fi
else
  echo "mysql client not found in PATH — cannot auto-create database. Please create ${DB_NAME} manually if it doesn't exist." >&2
fi

# Helper to run artisan and stop on error
run_artisan() {
  echo "\n-> php artisan $*"
  if ! php artisan "$@"; then
    echo "artisan $* failed" >&2
    exit 1
  fi
}

run_artisan migrate:status
run_artisan migrate --force
run_artisan tenants:assign-default
run_artisan migrate:status

echo "\nRunning test suite (optional). This may take a while."
run_artisan test

echo "\nDone. Verify backups in 'backups' and inspect output above."
