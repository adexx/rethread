#!/usr/bin/env bash
# Daily database + photo backup. Schedule it, e.g.:
#   15 3 * * * /path/to/rethread/scripts/backup.sh >> /var/log/rethread-backup.log 2>&1
# Then copy backups/ off the server (e.g. rclone to S3/B2), so a dead server doesn't take your backups with it.
set -euo pipefail
cd "$(dirname "$0")/.."
: "${DB_HOST:=127.0.0.1}" "${DB_PORT:=3306}" "${DB_NAME:=rethread}" "${DB_USER:=root}" "${DB_PASS:=root}"
: "${KEEP_DAYS:=14}"
MYSQLDUMP="${MYSQLDUMP:-$(command -v mysqldump || echo /Applications/MAMP/Library/bin/mysql80/bin/mysqldump)}"
STAMP=$(date +%Y%m%d-%H%M%S)
mkdir -p backups
umask 077
# Password via a temporary option file so it doesn't show up in the process list.
CNF=$(mktemp); trap 'rm -f "$CNF"' EXIT
printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' "$DB_USER" "$DB_PASS" "$DB_HOST" "$DB_PORT" > "$CNF"
"$MYSQLDUMP" --defaults-extra-file="$CNF" --single-transaction --routines --no-tablespaces "$DB_NAME" | gzip -9 > "backups/db-$STAMP.sql.gz"
tar -czf "backups/uploads-$STAMP.tar.gz" uploads
find backups -name '*.gz' -mtime +"$KEEP_DAYS" -delete
echo "$(date) backup ok: backups/db-$STAMP.sql.gz"
