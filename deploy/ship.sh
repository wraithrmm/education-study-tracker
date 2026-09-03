#!/bin/bash
#
# Copy php/ to the server and write its configuration. Shared by the release
# workflow and the branch-verification workflow so the two can never drift.
#
# Expects in the environment:
#   SSHPASS, DEPLOY_HOST, DEPLOY_USER, DEPLOY_DIR, SHARED_DIR,
#   PUBLIC_URL, DASHBOARD_PUBLIC, TRACKER_PASSWORD
set -euo pipefail

cd "$(dirname "$0")/.."

: "${SSHPASS:?DEPLOY_SSH_PASSWORD is not set}"
: "${TRACKER_PASSWORD:?TRACKER_PASSWORD is not set}"
: "${DEPLOY_HOST:?}" "${DEPLOY_USER:?}" "${DEPLOY_DIR:?}" "${SHARED_DIR:?}" "${PUBLIC_URL:?}"

SSH=(sshpass -e ssh -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30)
TARGET="$DEPLOY_USER@$DEPLOY_HOST"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Resolve the account's home once and address everything absolutely. An empty
# answer means the account cannot run remote commands at all — an SFTP-only
# user — which no amount of copying will fix.
REMOTE_HOME="$("${SSH[@]}" "$TARGET" 'printf %s "$HOME"')"
case "$REMOTE_HOME" in
  /*) echo "remote home: $REMOTE_HOME" ;;
  *)
    echo "::error::The SSH account '$DEPLOY_USER' cannot run remote commands (got '$REMOTE_HOME'). On DreamHost, set it to a Shell user under Manage Users. See DEPLOYMENT.md." >&2
    exit 1
    ;;
esac
[ -n "${GITHUB_ENV:-}" ] && echo "REMOTE_HOME=$REMOTE_HOME" >> "$GITHUB_ENV"

"${SSH[@]}" "$TARGET" \
  "mkdir -p '$REMOTE_HOME/$SHARED_DIR/data' '$REMOTE_HOME/$DEPLOY_DIR' && chmod 700 '$REMOTE_HOME/$SHARED_DIR'"

# printf rather than a heredoc: a password containing a backslash would be
# mangled by heredoc escape processing.
umask 077
{
  printf 'PUBLIC_URL=%s\n' "$PUBLIC_URL"
  printf 'TRACKER_PASSWORD=%s\n' "$TRACKER_PASSWORD"
  printf 'DASHBOARD_PUBLIC=%s\n' "${DASHBOARD_PUBLIC:-true}"
  printf 'DB_PATH=%s\n' "$REMOTE_HOME/$SHARED_DIR/data/tracker.db"
} > "$TMP/tracker.env"

# Written through the login shell rather than scp: no temp file is left behind,
# and the password never appears on a command line where another user on a
# shared host could read it out of ps.
"${SSH[@]}" "$TARGET" "umask 077 && cat > '$REMOTE_HOME/$SHARED_DIR/.env'" < "$TMP/tracker.env"

# --delete matters more than usual here: it clears out the previous Node
# deployment (app.js, dist/, node_modules/) that used to live in this
# directory. The database and .env are in SHARED_DIR, outside the target, so
# neither is ever at risk.
rsync -rlptz --delete \
  -e "sshpass -e ssh -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30" \
  php/ "$TARGET:$REMOTE_HOME/$DEPLOY_DIR/"

echo "--- deployed tree"
"${SSH[@]}" "$TARGET" "ls -la '$REMOTE_HOME/$DEPLOY_DIR'"
