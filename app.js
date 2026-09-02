/*
 * Phusion Passenger startup file (DreamHost shared hosting).
 *
 * Passenger loads this file with require(), so it has to be CommonJS. The
 * service itself is ESM — the MCP SDK ships ESM only — so the deploy places a
 * {"type":"module"} package.json inside dist/ and the root package.json is
 * emitted without a "type" field. That makes this file CJS and dist/ ESM, and
 * the bridge between them is a dynamic import(), which every Node since 14
 * understands. Nothing here depends on require(esm).
 *
 * Passenger patches net.Server.prototype.listen before this runs, so the PORT
 * the service picks is ignored and Passenger hands it the real socket.
 */
"use strict";

const fs = require("node:fs");
const path = require("node:path");

/*
 * Configuration lives outside the deploy directory so that rsync --delete on a
 * release can never take the password or the database with it.
 */
const envCandidates = [
  process.env.TRACKER_ENV_FILE,
  path.join(__dirname, "..", "tracker-shared", ".env"),
  path.join(__dirname, ".env"),
].filter(Boolean);

for (const file of envCandidates) {
  let raw;
  try {
    raw = fs.readFileSync(file, "utf8");
  } catch {
    continue;
  }
  for (const line of raw.split("\n")) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith("#")) continue;
    const eq = trimmed.indexOf("=");
    if (eq === -1) continue;
    const key = trimmed.slice(0, eq).trim();
    let value = trimmed.slice(eq + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    // A real environment variable always beats the file.
    if (process.env[key] === undefined) process.env[key] = value;
  }
  break;
}

if (!process.env.DB_PATH) {
  process.env.DB_PATH = path.join(__dirname, "..", "tracker-shared", "data", "tracker.db");
}

import("./dist/index.js").catch((err) => {
  console.error("Tracker failed to start:", err);
  process.exit(1);
});
