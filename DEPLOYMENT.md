# Deployment

Production is `https://education.rmmann.co.uk`, served by Apache's PHP on
DreamHost shared hosting. Merging into `main` deploys.

## How a deploy works

`.github/workflows/deploy.yml` runs on every push to `main` (and on
`workflow_dispatch`):

1. Lint every PHP file.
2. **Smoke test** — boot the service on PHP's own server and walk the whole
   connector handshake (dynamic client registration, the consent screen, PKCE,
   the token exchange, refresh rotation), then call every MCP tool, including
   the refusals the schema exists to enforce. A broken build never reaches the
   server.
3. Write `~/tracker-shared/.env` from the repository secrets.
4. `rsync --delete` the contents of `php/` into the domain's document root.
5. Poll `https://education.rmmann.co.uk/healthz` until it answers
   `{"ok":true,…}`, then re-run the read-only half of the smoke test against
   production. **The job fails if the live site does not come up**, so a green
   deploy means a running service, not just a successful copy.

There is no build step, no dependency install, no process to restart: PHP is
executed per request, so the next request runs the new code.

## Why PHP, and not the Node service this started as

The tracker began as a TypeScript/Express service (still in this repository's
history, up to commit `1dca9b9`). It cannot run on this host.

DreamHost's shared servers refuse V8 the executable memory a JIT needs. Any
JavaScript beyond a trivial expression dies with:

```
# Fatal error in , line 0
# Check failed: 12 == (*__errno_location ()).
  v8::base::OS::SetPermissions(...)
  v8::internal::MemoryAllocator::SetPermissionsOnExecutableMemoryChunk(...)
```

`errno` 12 is `ENOMEM`, and it is not a quota — `ulimit -v` and `-m` are both
`unlimited` on that account. `npm` dies this way, and so does a five-line
script that opens an in-memory SQLite database.

Measured on the box, flag by flag:

| Invocation | Result |
|---|---|
| `node -v` | works — barely executes any JavaScript |
| `node -e '<hot loop>'` | **ENOMEM** |
| `node --jitless -e '<hot loop>'` | works |
| `node --no-sparkplug --no-opt -e '<hot loop>'` | works |
| the real app, default flags | **ENOMEM** |
| the real app, `--no-sparkplug --no-opt --regexp-interpret-all` | starts |
| the real app, `--jitless` | starts, then `ReferenceError: WebAssembly is not defined` |

So a JIT-less Node can be coaxed into booting, but only with four V8 flags,
and `--jitless` is not among the workable ones: Node's `fetch` is undici, and
undici's HTTP parser is WebAssembly, which `--jitless` removes. That path also
still needs Passenger enabled on the domain and the web directory moved.

PHP needs none of it. No JIT, no Passenger, no long-running process, no panel
change — it already serves this domain over HTTPS, and mod_rewrite
front-controller routing already works. The service is the same one: same
SQLite schema (a database written by the Node version opens unchanged), same
nine MCP tools with the same descriptions and validation, same OAuth 2.1
server, same dashboard markup.

## What lives where on the server

```
~/education.rmmann.co.uk/     the domain's document root, and the deploy target
├── .htaccess       routes everything to index.php; nothing else is servable
├── index.php       the routing table
└── lib/            store, seed, oauth, mcp, dashboard

~/tracker-shared/
├── .env            PUBLIC_URL, TRACKER_PASSWORD, DASHBOARD_PUBLIC, DB_PATH
└── data/tracker.db the record
```

The database and the password sit outside the document root on purpose: they
are never web-reachable, and `rsync --delete` on a release can never remove
either of them. `.htaccess` rewrites every request to `index.php` with no
`-f` test, so `lib/` cannot be fetched even by exact URL.

## One-time setup

### 1. GitHub repository secrets

Settings → Secrets and variables → Actions → **Secrets**:

| Secret | Value |
|---|---|
| `DEPLOY_SSH_PASSWORD` | The SSH password for `edtrackerpaige` |
| `TRACKER_PASSWORD` | The consent-screen password. Generate one: `openssl rand -base64 24` |

`TRACKER_PASSWORD` is the only thing between the internet and the tracker.
Changing it here and re-running the deploy is how you rotate it.

The SSH account must be a **Shell user**, not an SFTP user — rsync spawns a
login shell. An SFTP-only account fails in the least helpful way possible: sshd
answers every command with `internal-sftp`, which exits 0 having done nothing,
so remote commands appear to succeed while changing nothing. The deploy checks
for this before it copies anything.

### 2. DreamHost panel

Nothing to do. The domain's default web directory is already where the deploy
lands, PHP is already enabled, and the Let's Encrypt certificate is already
issued. HTTPS is not optional — a Claude connector refuses an origin without a
publicly valid certificate.

Optional **Variables** (each has a working default, so set one only to change
it): `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_DIR`, `SHARED_DIR`, `PUBLIC_URL`,
`DASHBOARD_PUBLIC`.

`DEPLOY_HOST` is the SSH target and `PUBLIC_URL` is what browsers and Claude
reach; they default to the same hostname but do not have to be. Set
`DASHBOARD_PUBLIC` to `false` if you would rather the dashboard needed a token
too; the default leaves it readable to anyone with the link, while writes still
require OAuth.

## Connecting Claude

Settings → Connectors → Add custom connector, URL
`https://education.rmmann.co.uk/mcp`. Leave the client ID and secret blank —
the server registers Claude dynamically. Enter `TRACKER_PASSWORD` on the
consent screen.

On first request the service seeds the maths subject. Seeding only happens when
the database has no subjects, so later deploys never touch real progress.

## Running it locally

```bash
bash deploy/smoke-test.sh
php deploy/practice-test.php
```

The first boots the service on PHP's built-in server against a throwaway
database and exercises every endpoint and tool. The second runs the practice
acceptance tests and diffs the scoreboard golden snapshots in `tests/golden`
— run it after any change to a panel type, and `--update` to rewrite the
snapshots on purpose. Both run in CI on every push. To poke at it by hand:

```bash
mkdir -p /tmp/tracker/tracker-shared/data
cat > /tmp/tracker/tracker-shared/.env <<'EOF'
PUBLIC_URL=http://127.0.0.1:8110
TRACKER_PASSWORD=local-development-password
DB_PATH=/tmp/tracker/tracker-shared/data/tracker.db
EOF
cp -r php /tmp/tracker/site && cd /tmp/tracker/site && php -S 127.0.0.1:8110 index.php
```

## When a deploy fails

The job fails loudly rather than reporting a copy as a success, and on failure
it dumps the origin's response headers, the deploy directory and the tail of
Apache's error log. Work through it in this order:

- **"The SSH account … cannot run remote commands".** The account is SFTP-only.
  DreamHost panel → Manage Users → the deploy user → **Shell user (SSH)**.
- **"The domain is serving the DreamHost placeholder".** The deploy landed
  somewhere the web server does not read. Compare `DEPLOY_DIR` with the
  domain's web directory in the panel. Apache's log gives the real document
  root away: a denied request logs the path it resolved to, as in
  `AH01630: client denied by server configuration: /home/<user>/<dir>/.env`.
- **500 on every route.** Read the Apache error log the failure step prints. A
  missing `TRACKER_PASSWORD` in `~/tracker-shared/.env` is the usual cause; the
  service refuses to serve without one rather than running unprotected.
- **`/healthz` works but Claude says it "couldn't reach the MCP server".**
  Check `PUBLIC_URL` matches the origin exactly, with no trailing slash, and
  that `curl -si -X POST https://education.rmmann.co.uk/mcp -H 'content-type:
  application/json' -d '{}'` returns `401` with a `WWW-Authenticate: Bearer
  resource_metadata="…"` header. If the header is there but authenticated calls
  still fail, suspect the `Authorization` header being stripped: Apache does
  not pass it to PHP under CGI/FastCGI, and `.htaccess` copies it into the
  environment for exactly that reason.

## Backups

The database is one file and it is the only copy of the record.

```bash
ssh edtrackerpaige@education.rmmann.co.uk \
  'sqlite3 ~/tracker-shared/data/tracker.db ".backup ~/tracker-shared/data/backup.db"'
scp edtrackerpaige@education.rmmann.co.uk:tracker-shared/data/backup.db \
  ./tracker-backup-$(date +%F).db
```

Worth a weekly cron job on the DreamHost account.
