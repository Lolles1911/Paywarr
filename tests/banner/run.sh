#!/usr/bin/env bash
# Browser test of the access banner. Needs php, python3, node and Playwright.
set -euo pipefail
cd "$(dirname "$0")"
PORT="${PORT:-8778}"
OUT="$(mktemp -d)"
php render.php "$OUT" >/dev/null
# Not in a subshell: $! must be the server itself, or it outlives the test.
python3 -m http.server "$PORT" --bind 127.0.0.1 --directory "$OUT" >/dev/null 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true; rm -rf "$OUT"' EXIT
sleep 1
PORT="$PORT" node banner.test.cjs "$OUT"
