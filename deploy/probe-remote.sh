#!/bin/bash
# Round 2. Two questions:
#   1. Is there ANY node invocation that boots the real app on this host?
#   2. Does PHP serve over HTTP from this domain as it is configured today,
#      with no panel changes?
export PATH="$HOME/bin:$PATH"
APP="$HOME/education.rmmann.co.uk"

h() { printf '\n########## %s\n' "$1"; }

h "node: every flag combination worth trying against the real app"
if [ -d "$APP/node_modules" ]; then
  # --regexp-interpret-all matters because irregexp compiles patterns to native
  # code, which is another executable allocation the interpreter flags do not
  # cover. --jitless is included even though it removes WebAssembly, to see how
  # far it gets.
  while IFS= read -r flags; do
    printf '  %-58s -> ' "${flags:-(default)}"
    out=$(cd "$APP" && DB_PATH=/tmp/probe-$$.db PORT=18131 timeout 12 node $flags app.js 2>&1 \
          | grep -m1 -E 'Tracker listening|Check failed|ReferenceError|Error:' )
    echo "${out:-no output}"
    rm -f /tmp/probe-$$.db
  done <<'FLAGS'
--no-sparkplug --no-opt --regexp-interpret-all
--jitless --regexp-interpret-all
--no-sparkplug --no-opt --no-wasm-tier-up --regexp-interpret-all
--no-sparkplug --no-opt --regexp-interpret-all --max-old-space-size=96
FLAGS
else
  echo "  no node_modules at $APP"
fi

h "php over http: does this domain serve PHP as configured right now?"
cat > "$APP/probe-php.php" <<'PHP'
<?php
header('Content-Type: application/json');
echo json_encode([
  'php'          => PHP_VERSION,
  'sapi'         => PHP_SAPI,
  'pdo_sqlite'   => extension_loaded('pdo_sqlite'),
  'sqlite3'      => extension_loaded('sqlite3'),
  'openssl'      => extension_loaded('openssl'),
  'memory_limit' => ini_get('memory_limit'),
  'docroot'      => $_SERVER['DOCUMENT_ROOT'] ?? '?',
  'script'       => $_SERVER['SCRIPT_FILENAME'] ?? '?',
  'https'        => !empty($_SERVER['HTTPS']),
]);
PHP
echo "  wrote $APP/probe-php.php"

h "php over http: is mod_rewrite usable for front-controller routing?"
cat > "$APP/.htaccess" <<'HT'
# Probe only — front-controller routing to probe-router.php.
RewriteEngine On
RewriteCond %{REQUEST_URI} ^/probe-route
RewriteRule ^probe-route(.*)$ probe-router.php [QSA,L]
HT
cat > "$APP/probe-router.php" <<'PHP'
<?php
header('Content-Type: application/json');
echo json_encode(['routed' => true, 'uri' => $_SERVER['REQUEST_URI'] ?? '?']);
PHP
echo "  wrote $APP/.htaccess and probe-router.php"

h "can php open the real database directory?"
php -r '
  $p = getenv("HOME") . "/tracker-shared/data/probe.db";
  $db = new PDO("sqlite:$p");
  $db->exec("create table if not exists probe (x integer)");
  echo "  wrote and opened $p\n";
  unlink($p);
' 2>&1 | sed 's/^/  /'

h "done"
