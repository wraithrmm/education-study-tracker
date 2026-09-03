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
# npm is symlinked for manual use but is not run by this script — see below.

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
echo "node $(node -v)"

say "Dependencies"
# npm is deliberately never run on this host. It dies here with
#   Check failed: 12 == (*__errno_location ())
# inside v8::base::OS::SetPermissions — errno 12 is ENOMEM, V8 failing to
# allocate executable memory under the shared account's cap. Plain node runs
# fine; npm is simply too heavy. So node_modules is installed on the CI runner
# and shipped whole, which is also faster and pins the tree to exactly what the
# smoke test exercised. Nothing needs compiling: better-sqlite3 carries its own
# prebuilt binaries inside the package.
cd "$DEPLOY_PATH"
[ -d node_modules ] || { echo "node_modules is missing — the release was not shipped completely" >&2; exit 1; }

# The prebuilt binary still has to load under *this* node and *this* glibc.
# Prove it here, where the error is readable, rather than leaving Passenger to
# report it as a blank 502.
echo "ulimit -v (virtual memory, KB): $(ulimit -v)"
echo "ulimit -m (resident set, KB):  $(ulimit -m)"
# Shell sessions on shared hosting are usually capped far below the web
# processes Passenger spawns. Raise it if the hard limit allows; if it does
# not, this is a no-op and the checks below say so.
ulimit -v unlimited 2>/dev/null || true

PROBE="
  const Database = require('better-sqlite3');
  const db = new Database(':memory:');
  db.exec('create table probe (x)');
  console.log('better-sqlite3 loads, sqlite ' + db.prepare('select sqlite_version() v').get().v);
"

# Deliberately informational: a failure here does not stop the deploy.
# V8 needs to allocate executable memory to run anything beyond a trivial
# script, and this SSH session may be capped below what that takes — which
# says nothing about the environment Passenger gives the app. The live health
# check at the end of the deploy is the real verdict, so let it decide.
#
# Running the same probe with --jitless separates the two possible causes: no
# JIT means no executable allocation, so if that succeeds where the default
# fails, the binary is fine and the shell's memory cap is the problem.
if node -e "$PROBE"; then
  echo "native module loads normally under this shell's limits"
elif node --jitless -e "$PROBE"; then
  echo "NOTE: the module loads only with --jitless. The binary is fine; this"
  echo "SSH session cannot allocate executable memory for V8's JIT. That is a"
  echo "limit on shell processes and may not apply to Passenger's web"
  echo "processes. Continuing — the live health check decides."
else
  echo "WARNING: the module did not load even with --jitless. If the error"
  echo "above mentions GLIBC, the prebuilt binary is newer than this box."
  echo "Continuing anyway so the health check can give a verdict."
fi

say "Restart"
# Passenger re-reads the app when this file's mtime changes.
touch "$DEPLOY_PATH/tmp/restart.txt"
echo "touched $DEPLOY_PATH/tmp/restart.txt"
