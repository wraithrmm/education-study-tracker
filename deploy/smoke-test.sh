#!/bin/bash
#
# Boots the assembled release the way Passenger does — require() on app.js from
# a CommonJS context — and checks the endpoints that have to work for a Claude
# connector to complete its handshake.
#
# Run from the repo root after deploy/build-release.sh, with release/ having had
# its production dependencies installed.
set -euo pipefail

cd "$(dirname "$0")/.."
PORT="${SMOKE_PORT:-8099}"
BASE="http://127.0.0.1:$PORT"
WORK="$(mktemp -d)"
trap 'kill "${APP_PID:-0}" 2>/dev/null || true; rm -rf "$WORK"' EXIT

fail() { echo "SMOKE FAIL: $1" >&2; sed -n '1,80p' "$WORK/app.log" >&2; exit 1; }

(
  cd release
  DB_PATH="$WORK/tracker.db" \
  TRACKER_PASSWORD="smoke-test-password" \
  PUBLIC_URL="$BASE" \
  PORT="$PORT" \
  node -e "require('./app.js')" > "$WORK/app.log" 2>&1
) &
APP_PID=$!

for _ in $(seq 1 40); do
  curl -fsS --noproxy '*' "$BASE/healthz" >/dev/null 2>&1 && break
  sleep 0.5
done

echo "--- /healthz"
curl -fsS --noproxy '*' "$BASE/healthz" || fail "healthz did not answer"
echo

echo "--- /.well-known/oauth-protected-resource"
curl -fsS --noproxy '*' "$BASE/.well-known/oauth-protected-resource" \
  | grep -q '"resource"' || fail "protected-resource metadata missing"
echo "ok"

echo "--- /.well-known/oauth-authorization-server"
curl -fsS --noproxy '*' "$BASE/.well-known/oauth-authorization-server" \
  | grep -q '"registration_endpoint"' || fail "authorization-server metadata missing"
echo "ok"

# Claude discovers the auth server from this header. Without it the connector
# fails with a generic "couldn't reach the MCP server".
echo "--- POST /mcp unauthenticated"
headers="$(curl -sS --noproxy '*' -o /dev/null -D - -X POST "$BASE/mcp" \
  -H 'content-type: application/json' -d '{}')"
grep -qi '^HTTP/1.1 401' <<<"$headers" || fail "/mcp did not answer 401"
grep -qi '^www-authenticate: Bearer resource_metadata=' <<<"$headers" \
  || fail "/mcp 401 is missing the WWW-Authenticate resource_metadata header"
echo "ok"

echo "--- dashboard"
curl -fsS --noproxy '*' -o /dev/null "$BASE/" || fail "dashboard index did not render"
echo "ok"

echo "SMOKE PASS"
