#!/bin/bash
# Backs up a Paymenter installation into one archive: the database, .env (it
# holds the APP_KEY that decrypts the stored API keys and passwords) and the
# uploaded files. Keeps the last 14 days.
#
#   bash backup-paymenter.sh                                   # /opt/paymenter -> /var/backups/paymenter
#   bash backup-paymenter.sh /var/www/paymenter /mnt/backup 30 # folder, destination, days to keep
#
# Run as root (it reads .env and writes with root-only permissions). For a
# nightly backup, see "Backups" in the README.

set -euo pipefail
umask 077

PAYMENTER="${1:-/opt/paymenter}"
PAYMENTER="${PAYMENTER%/}"
DEST="${2:-/var/backups/paymenter}"
KEEP_DAYS="${3:-14}"

if [ ! -f "$PAYMENTER/.env" ]; then
  echo "No .env in $PAYMENTER. Usage: bash backup-paymenter.sh /path/to/paymenter [destination] [days]" >&2
  exit 1
fi

# One value from .env, without quotes. Never sourced: .env is not a shell script.
env_value() {
  local line
  line="$(grep -E "^$1=" "$PAYMENTER/.env" | tail -n 1 || true)"
  line="${line#*=}"
  if [[ "$line" == \"*\" ]]; then
    line="${line:1:${#line}-2}"
    line="${line//\\\"/\"}"   # \" inside double quotes is a "
  elif [[ "$line" == \'*\' ]]; then
    line="${line:1:${#line}-2}"
  fi
  printf '%s' "$line"
}

STAMP="$(date +%Y-%m-%d-%H%M)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$DEST" "$WORK/paymenter-$STAMP"
OUT="$WORK/paymenter-$STAMP"

CONNECTION="$(env_value DB_CONNECTION)"
case "$CONNECTION" in
  mysql|mariadb)
    DUMP="$(command -v mariadb-dump || command -v mysqldump || true)"
    if [ -z "$DUMP" ]; then
      echo "Neither mariadb-dump nor mysqldump is installed." >&2
      exit 1
    fi
    # The password goes through the environment, not the command line, where
    # other users could read it in the process list.
    MYSQL_PWD="$(env_value DB_PASSWORD)" "$DUMP" \
      --single-transaction --quick --routines --no-tablespaces \
      --host="$(env_value DB_HOST)" --port="$(env_value DB_PORT)" \
      --user="$(env_value DB_USERNAME)" "$(env_value DB_DATABASE)" > "$OUT/database.sql"
    ;;
  sqlite)
    DB="$(env_value DB_DATABASE)"
    [ -n "$DB" ] || DB="$PAYMENTER/database/database.sqlite"
    if command -v sqlite3 >/dev/null 2>&1; then
      sqlite3 "$DB" ".backup '$OUT/database.sqlite'"
    else
      cp "$DB" "$OUT/database.sqlite"
    fi
    ;;
  *)
    echo "Database type '$CONNECTION' is not supported by this script (MariaDB/MySQL and SQLite are)." >&2
    exit 1
    ;;
esac

cp "$PAYMENTER/.env" "$OUT/env"
# Uploaded logos and images, plus themes and extensions for a quick restore.
for dir in storage/app themes extensions; do
  if [ -d "$PAYMENTER/$dir" ]; then
    mkdir -p "$OUT/$(dirname "$dir")"
    cp -a "$PAYMENTER/$dir" "$OUT/$dir"
  fi
done

ARCHIVE="$DEST/paymenter-$STAMP.tar.gz"
tar -czf "$ARCHIVE.part" -C "$WORK" "paymenter-$STAMP"
mv "$ARCHIVE.part" "$ARCHIVE"
chmod 600 "$ARCHIVE"

# Old backups, but never the one just made.
find "$DEST" -maxdepth 1 -name 'paymenter-*.tar.gz' -mtime +"$KEEP_DAYS" ! -path "$ARCHIVE" -delete

echo "Backup written: $ARCHIVE ($(du -h "$ARCHIVE" | cut -f1))"
