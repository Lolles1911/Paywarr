#!/bin/bash
# Installs Paywarr (the "Wizarr" server extension) into Paymenter.
#
#   bash install.sh                      # Paymenter in /opt/paymenter
#   bash install.sh /var/www/paymenter   # Paymenter somewhere else
#   bash install.sh ~/paymenter          # Docker: the folder with Paymenter's
#                                        # docker-compose.yml and extensions/
#
# Safe to run again after updating this repository.

set -euo pipefail

PAYMENTER="${1:-/opt/paymenter}"
PAYMENTER="${PAYMENTER%/}"
SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/extensions/Servers/Wizarr"
EXTENSIONS="$PAYMENTER/extensions"
TARGET="$EXTENSIONS/Servers/Wizarr"

if [ ! -f "$SOURCE/Wizarr.php" ]; then
  echo "Cannot find the extension at $SOURCE" >&2
  exit 1
fi

if [ ! -d "$EXTENSIONS" ]; then
  echo "No extensions folder in $PAYMENTER." >&2
  echo "Give the folder that holds Paymenter's extensions/ folder: bash install.sh /path/to/paymenter" >&2
  exit 1
fi

# Paymenter only copies its built-in extensions into this folder while it is
# EMPTY. Putting Wizarr there first would leave you without the Stripe gateway.
if [ ! -d "$EXTENSIONS/Gateways/Stripe" ]; then
  echo "Paymenter has not set up $EXTENSIONS yet (no Gateways/Stripe in it)." >&2
  echo "Start Paymenter once and wait for it to finish booting, then run this again." >&2
  exit 1
fi

# The whole folder: PHP classes, routes.php, resources/views and lang.
rm -rf "$TARGET"
mkdir -p "$TARGET"
cp -r "$SOURCE"/. "$TARGET"/

# Same owner as the extensions Paymenter put there itself.
chown -R --reference="$EXTENSIONS/Gateways/Stripe" "$TARGET"
chmod -R 755 "$TARGET"

echo "Installed to $TARGET."

# Paywarr Stats: a separate extension, inactive until enabled under Admin -> Extensions.
STATS_SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/extensions/Others/PaywarrStats"
STATS_TARGET="$EXTENSIONS/Others/PaywarrStats"
if [ -f "$STATS_SOURCE/PaywarrStats.php" ]; then
  rm -rf "$STATS_TARGET"
  mkdir -p "$STATS_TARGET"
  cp -r "$STATS_SOURCE"/. "$STATS_TARGET"/
  chown -R --reference="$EXTENSIONS/Gateways/Stripe" "$STATS_TARGET"
  chmod -R 755 "$STATS_TARGET"
  echo "Installed Paywarr Stats to $STATS_TARGET (enable it under Admin -> Extensions to use it)."
fi

if [ -f "$PAYMENTER/artisan" ] && command -v php >/dev/null 2>&1; then
  # As the user that owns storage/ (usually www-data): run as root, artisan can
  # leave root-owned files behind that the web server then cannot write.
  OWNER="$(stat -c %U "$PAYMENTER/storage")"
  RELOAD="cd '$PAYMENTER' && php artisan optimize:clear >/dev/null && php artisan queue:restart >/dev/null"
  if { [ "$(id -un)" = "$OWNER" ] && bash -c "$RELOAD"; } || su -s /bin/bash "$OWNER" -c "$RELOAD"; then
    echo "Paymenter reloaded (caches cleared, queue workers restarted)."
  else
    echo "Could not reload Paymenter. Run:" >&2
    echo "  su -s /bin/bash $OWNER -c \"cd $PAYMENTER && php artisan optimize:clear && php artisan queue:restart\"" >&2
  fi
else
  echo
  echo "No artisan here, so this is probably Docker. Restart Paymenter, from the folder with its docker-compose.yml:"
  echo "  docker compose restart paymenter"
fi
