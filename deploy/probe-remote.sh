#!/bin/bash
# Diagnostic sweep, run on the DreamHost box via `ssh host bash -s < this`.
# Read-only: it inspects the environment and tries candidate runtimes. Nothing
# here writes to the deploy directory or the real database.
export PATH="$HOME/bin:$PATH"
APP="$HOME/education.rmmann.co.uk"

h() { printf '\n########## %s\n' "$1"; }

h "host"
uname -a
head -2 /etc/os-release 2>/dev/null
getconf GNU_LIBC_VERSION 2>/dev/null || ldd --version 2>&1 | head -1

h "limits"
ulimit -a

h "cgroup / memory"
cat /proc/self/cgroup 2>/dev/null | head -5
for f in /sys/fs/cgroup/memory.max /sys/fs/cgroup/memory/memory.limit_in_bytes; do
  [ -r "$f" ] && echo "$f = $(cat "$f")"
done
free -m 2>/dev/null | head -3
echo "vm.max_map_count = $(cat /proc/sys/vm/max_map_count 2>/dev/null)"
echo "vm.overcommit_memory = $(cat /proc/sys/vm/overcommit_memory 2>/dev/null)"
echo "maps in this shell = $(wc -l < /proc/self/maps)"

h "node: which variants can execute real javascript?"
# A trivial expression does not JIT. The loop below runs hot enough to force
# baseline compilation, which is exactly what has been failing.
WORK='let s=0; for (let i=0;i<300000;i++) s+=i; console.log("computed", s);'
node -v
for flags in "" "--jitless" "--no-sparkplug" "--no-sparkplug --no-opt" "--max-old-space-size=64" "--max-old-space-size=64 --no-sparkplug --no-opt"; do
  printf '  node %-42s -> ' "${flags:-(default)}"
  if out=$(node $flags -e "$WORK" 2>&1); then
    echo "OK   ${out##*$'\n'}"
  else
    echo "FAIL $(printf '%s' "$out" | grep -m1 -E 'Check failed|Fatal|Error' || echo "exit $?")"
  fi
done

h "node: can the real app boot?"
if [ -d "$APP/node_modules" ]; then
  for flags in "" "--no-sparkplug --no-opt"; do
    printf '  app with %-26s -> ' "${flags:-(default)}"
    out=$(cd "$APP" && DB_PATH=/tmp/probe-$$.db PORT=18131 timeout 15 node $flags app.js 2>&1 | head -5)
    printf '%s\n' "$out" | tr '\n' '|'
    echo
    rm -f /tmp/probe-$$.db
  done
else
  echo "  no node_modules at $APP"
fi

h "other runtimes available"
for bin in php php8.3 php8.2 php8.1 python3 ruby perl; do
  if command -v "$bin" >/dev/null 2>&1; then
    printf '  %-10s %s\n' "$bin" "$($bin --version 2>&1 | head -1)"
  else
    printf '  %-10s absent\n' "$bin"
  fi
done

h "php: sqlite and the pieces this service needs"
if command -v php >/dev/null 2>&1; then
  php -m 2>/dev/null | grep -iE '^(pdo_sqlite|sqlite3|json|openssl|curl|mbstring|hash)$' | sed 's/^/  ext: /'
  php -r '
    $db = new PDO("sqlite::memory:");
    $db->exec("create table t (x integer)");
    $db->exec("insert into t values (42)");
    $v = $db->query("select x from t")->fetchColumn();
    echo "  pdo_sqlite works, read back $v, sqlite " . $db->query("select sqlite_version()")->fetchColumn() . "\n";
    echo "  random_bytes+hash ok: " . substr(hash("sha256", random_bytes(16)), 0, 16) . "\n";
  ' 2>&1 | sed 's/^/  /'
  echo "  memory_limit = $(php -r 'echo ini_get("memory_limit");' 2>/dev/null)"
else
  echo "  php not on PATH"
fi

h "python: could it host this instead?"
if command -v python3 >/dev/null 2>&1; then
  python3 -c "
import sqlite3, sys
c = sqlite3.connect(':memory:')
c.execute('create table t (x)')
print('  sqlite3 module works, sqlite', sqlite3.sqlite_version)
print('  python', sys.version.split()[0])
" 2>&1 | sed 's/^/  /'
fi

h "web directory: what is actually being served"
ls -la "$APP" 2>/dev/null | head -15
echo "--- public/"
ls -la "$APP/public" 2>/dev/null
echo "--- is there a .htaccess anywhere?"
find "$HOME" -maxdepth 3 -name '.htaccess' 2>/dev/null | head
echo "--- what else lives in the home directory"
ls -la "$HOME" | head -20

h "done"
