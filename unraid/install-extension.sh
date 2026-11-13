#!/bin/bash
# Copies the Wizarr extension into Paymenter's extensions folder.
#
#   ./install-extension.sh                              # /mnt/user/appdata/paymenter
#   ./install-extension.sh /mnt/cache/appdata/paymenter
#
# Safe to run again after updating this repository.

set -euo pipefail

APPDATA="${1:-/mnt/user/appdata/paymenter}"
SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/extensions/Servers/Wizarr"
EXTENSIONS="$APPDATA/extensions"
TARGET="$EXTENSIONS/Servers/Wizarr"

if [ ! -f "$SOURCE/Wizarr.php" ]; then
  echo "Cannot find the extension at $SOURCE" >&2
  exit 1
fi

# Paymenter only copies its built-in extensions into this folder while it is
# EMPTY. Putting Wizarr there first would leave you without the Stripe gateway.
if [ ! -d "$EXTENSIONS/Gateways/Stripe" ]; then
  echo "Paymenter has not set up $EXTENSIONS yet (no Gateways/Stripe in it)." >&2
  echo "Start Paymenter once and wait for it to finish booting, then run this again." >&2
  exit 1
fi

# The whole folder: PHP classes, routes.php and resources/views.
rm -rf "$TARGET"
mkdir -p "$TARGET"
cp -r "$SOURCE"/. "$TARGET"/

# Same owner as the extensions Paymenter put there itself.
chown -R --reference="$EXTENSIONS/Gateways/Stripe" "$TARGET"
chmod -R 755 "$TARGET"

echo "Installed to $TARGET:"
(cd "$TARGET" && find . -type f | sort)
echo
echo "Now reload Paymenter:"
echo "  LXC / plain install:  cd <paymenter folder> && su -s /bin/bash www-data -c \"php artisan optimize:clear && php artisan queue:restart\""
echo "                        (as the web server user, not root - see the README)"
echo "  Docker:               docker restart paymenter"
