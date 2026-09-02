#!/bin/bash
#
# Assembles the exact directory tree that gets rsynced to the server.
# Run after `npm run build`. Output: ./release
set -euo pipefail

cd "$(dirname "$0")/.."
OUT=release

[ -d dist ] || { echo "dist/ is missing — run 'npm run build' first" >&2; exit 1; }

rm -rf "$OUT"
mkdir -p "$OUT"

cp -R dist "$OUT/dist"
cp app.js package-lock.json "$OUT/"
mkdir -p "$OUT/deploy"
cp deploy/remote-setup.sh "$OUT/deploy/"

# The root package.json is emitted WITHOUT "type": "module" so that Passenger's
# require() of app.js gets CommonJS. dist/ carries its own package.json to keep
# the compiled service ESM. See the comment at the top of app.js.
node -e '
  const pkg = require("./package.json");
  delete pkg.type;
  delete pkg.devDependencies;
  pkg.scripts = { start: "node app.js" };
  pkg.main = "app.js";
  require("fs").writeFileSync("release/package.json", JSON.stringify(pkg, null, 2) + "\n");
'
echo '{ "type": "module" }' > "$OUT/dist/package.json"

echo "release/ assembled:"
find "$OUT" -maxdepth 2 -mindepth 1 | sort
