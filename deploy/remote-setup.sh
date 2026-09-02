#!/bin/bash
#
# Runs on the DreamHost box, over SSH, as the last step of a deploy.
# Idempotent: safe to run on a bare account and on every release after that.
#
# Expects (exported by the caller):
#   DEPLOY_PATH   absolute path of the Passenger app directory
#   SHARED_PATH   absolute path of the directory holding .env and data/
#   NODE_VERSION  major version to install with nvm, e.g. "22"
set -euo pipefail

DEPLOY_PATH="${DEPLOY_PATH:?DEPLOY_PATH is required}"
SHARED_PATH="${SHARED_PATH:?SHARED_PATH is required}"
NODE_VERSION="${NODE_VERSION:-22}"

say() { printf '\n=== %s\n' "$1"; }

say "Layout"
mkdir -p "$DEPLOY_PATH/public" "$DEPLOY_PATH/tmp" "$SHARED_PATH/data"
# Passenger will not serve a directory it cannot read, and an empty public/ is
# what we want: every path falls through to the Node app.
chmod 755 "$DEPLOY_PATH" "$DEPLOY_PATH/public"
chmod 700 "$SHARED_PATH"
echo "deploy:  $DEPLOY_PATH"
echo "shared:  $SHARED_PATH"

if [ -f "$SHARED_PATH/.env" ]; then
  chmod 600 "$SHARED_PATH/.env"
  # The workflow cannot know the account's real home directory, so the
  # database path is pinned here instead of being rendered on the runner.
  DB_LINE="DB_PATH=$SHARED_PATH/data/tracker.db"
  if grep -q '^DB_PATH=' "$SHARED_PATH/.env"; then
    sed -i.bak "s|^DB_PATH=.*|$DB_LINE|" "$SHARED_PATH/.env" && rm -f "$SHARED_PATH/.env.bak"
  else
    printf '%s\n' "$DB_LINE" >> "$SHARED_PATH/.env"
  fi
  echo "$DB_LINE"
else
  echo "warning: $SHARED_PATH/.env is missing — the service will refuse to start" >&2
fi

say "Node"
# DreamHost shared hosting has no usable system Node, so we own one via nvm.
# Passenger spawns the app through a login shell, so exporting PATH from
# .bash_profile is what makes `node` findable at boot time as well as here.
export NVM_DIR="$HOME/.nvm"
if [ ! -s "$NVM_DIR/nvm.sh" ]; then
  echo "installing nvm"
  curl -fsSL https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash
fi
# shellcheck disable=SC1091
. "$NVM_DIR/nvm.sh"

if ! nvm ls "$NODE_VERSION" >/dev/null 2>&1; then
  echo "installing Node $NODE_VERSION"
  nvm install "$NODE_VERSION"
fi
nvm use "$NODE_VERSION" >/dev/null
nvm alias default "$NODE_VERSION" >/dev/null

NODE_BIN="$(dirname "$(command -v node)")"
mkdir -p "$HOME/bin"
ln -sf "$NODE_BIN/node" "$HOME/bin/node"
ln -sf "$NODE_BIN/npm" "$HOME/bin/npm"
ln -sf "$NODE_BIN/npx" "$HOME/bin/npx"

PROFILE="$HOME/.bash_profile"
touch "$PROFILE"
if ! grep -q '# tracker-deploy PATH' "$PROFILE"; then
  {
    echo ''
    echo '# tracker-deploy PATH — Passenger spawns the app through a login shell'
    echo 'export PATH="$HOME/bin:$PATH"'
  } >> "$PROFILE"
  echo "added ~/bin to PATH in $PROFILE"
fi
export PATH="$HOME/bin:$PATH"
echo "node $(node -v), npm $(npm -v)"

say "Dependencies"
cd "$DEPLOY_PATH"
# --omit=dev: the release ships compiled JS, so TypeScript is not needed here.
# better-sqlite3 pulls a prebuilt binary when one matches this Node ABI and
# compiles from source when it does not; either way it is cached across deploys.
npm ci --omit=dev --no-audit --no-fund

say "Restart"
# Passenger re-reads the app when this file's mtime changes.
touch "$DEPLOY_PATH/tmp/restart.txt"
echo "touched $DEPLOY_PATH/tmp/restart.txt"
