# Deployment

Production is `https://education.rmmann.co.uk`, served by Phusion
Passenger on DreamHost shared hosting. Merging into `main` deploys.

The `Dockerfile` and `docker-compose.yml` are still the easiest way to run the
service locally or on a box you control; they are not what production uses.

## How a deploy works

`.github/workflows/deploy.yml` runs on every push to `main` (and on
`workflow_dispatch`, for the first run or a re-deploy):

1. Typecheck, compile TypeScript, assemble `release/`.
2. Install production dependencies and **smoke test the release** — the runner
   boots it the way Passenger does and checks `/healthz`, both discovery
   documents, the `401` + `WWW-Authenticate` challenge on `/mcp`, and the
   dashboard. A broken build never reaches the server.
3. Prune `better-sqlite3`'s prebuilt binaries down to the one platform this
   deploys to.
4. Write `~/tracker-shared/.env` on the server from the repository secrets.
5. `rsync --delete` the release — `node_modules` included — into
   `~/education.rmmann.co.uk/`.
6. Run `deploy/remote-setup.sh` on the server: install Node via nvm if it isn't
   there, prove the native module loads, `touch tmp/restart.txt`.
7. Poll `https://education.rmmann.co.uk/healthz` until it answers
   `{"ok":true,…}`, then confirm `/mcp` still returns the discovery challenge.
   **The job fails if the live site does not come up**, so a green deploy means
   a running service, not just a successful copy.

### Why the release looks the way it does

Passenger loads `app.js` with `require()`, so it has to be CommonJS. The
service is ESM, because the MCP SDK ships ESM only. `deploy/build-release.sh`
therefore emits a root `package.json` with no `"type"` field and drops a
`{"type":"module"}` package.json inside `dist/`. `app.js` is CommonJS, reaches
the ESM service through a dynamic `import()`, and nothing depends on a Node new
enough to `require()` an ES module. The CI smoke test exercises exactly this
path.

### Why npm never runs on the server

The shared account's memory cap is low enough that V8 cannot allocate
executable pages for a process npm's size. It dies as:

```
# Fatal error in , line 0
# Check failed: 12 == (*__errno_location ()).
  v8::base::OS::SetPermissions(...)
```

errno 12 is `ENOMEM`. Plain `node` runs fine — `node -v`, and the service
itself, are far smaller — but `npm -v` and `npm ci` both die this way.

So `node_modules` is installed on the CI runner and rsynced whole. Nothing
needs compiling at either end: `better-sqlite3` carries prebuilt binaries for
every platform inside its own package, and the deploy prunes them to the one
it targets. The tree that runs in production is therefore byte-for-byte the
tree the smoke test exercised.

`remote-setup.sh` still tries to load the native module under the server's own
Node, but treats the result as information rather than a verdict. The same
ENOMEM shows up for any script large enough to trigger V8's baseline compiler,
including a five-line one that opens an in-memory database — so a failure there
does not distinguish a broken binary from a shell session capped below what
V8's JIT needs. The script therefore retries with `--jitless`, which needs no
executable memory at all: if that succeeds where the default failed, the binary
is sound and the cap is the story. Either way the deploy continues, and the
live health check gives the verdict.

### What lives where on the server

```
~/education.rmmann.co.uk/   deploy target (Passenger app root)
├── app.js          Passenger startup file
├── dist/           compiled service
├── node_modules/   installed on the CI runner and shipped whole
├── public/         document root — deliberately empty, everything falls through
└── tmp/restart.txt touch to restart

~/tracker-shared/
├── .env            PUBLIC_URL, TRACKER_PASSWORD, DASHBOARD_PUBLIC, DB_PATH
└── data/tracker.db the record
```

The database and the password sit outside the deploy directory on purpose, so
that `rsync --delete` on a release can never remove either of them.

## One-time setup

### 1. DreamHost panel (has to be done by hand — there is no API for it)

Websites → **Manage Websites** → `education.rmmann.co.uk` → Edit:

- **Web directory**: `education.rmmann.co.uk/public`
- Tick **Passenger (Ruby/NodeJS/Python apps only)**
- Under **HTTPS/SSL**, add the free Let's Encrypt certificate.

HTTPS is not optional: Claude.ai custom connectors refuse an origin without a
publicly valid certificate, and the OAuth issuer in `PUBLIC_URL` must match the
scheme it is reached on.

Then, under **Manage Users**, check that `edtrackerpaige` is a **Shell user
(SSH)** and not an SFTP user. The deploy does not merely copy files: it runs
node and restarts Passenger on the box, and rsync itself spawns a login shell.
An SFTP-only account fails this in the least helpful way possible — sshd
answers every command with `internal-sftp`, which exits 0 having done
nothing, so remote commands appear to succeed while changing nothing. The
"Probe the SSH account" step exists to catch exactly that and name it.

### 2. GitHub repository secrets

Settings → Secrets and variables → Actions → **Secrets**:

| Secret | Value |
|---|---|
| `DEPLOY_SSH_PASSWORD` | The SSH password for `edtrackerpaige` |
| `TRACKER_PASSWORD` | The consent-screen password. Generate one: `openssl rand -base64 24` |

`TRACKER_PASSWORD` is the only thing between the internet and the tracker.
Changing it here and re-running the deploy is how you rotate it.

Optional **Variables** (each has a working default, so set one only to change
it): `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_DIR`, `SHARED_DIR`, `PUBLIC_URL`,
`DASHBOARD_PUBLIC`.

`DEPLOY_HOST` is the SSH target and `PUBLIC_URL` is what browsers and Claude
reach; they default to the same hostname but do not have to be. If the DNS for
`education.rmmann.co.uk` ever points somewhere other than the DreamHost box —
behind a CDN, say — SSH will fail while the site keeps working. Set
`DEPLOY_HOST` to a name that resolves straight to DreamHost and leave
`PUBLIC_URL` alone.

Set `DASHBOARD_PUBLIC` to `false` if you would rather the dashboard needed a
token as well; the default leaves it readable to anyone with the link, while
writes still require OAuth.

### 3. First deploy

Merge into `main`, or run **Actions → Deploy → Run workflow**. The first run
takes noticeably longer than later ones — it installs Node via nvm and rsyncs
the whole dependency tree before anything answers.

On first start the service seeds the maths subject. Seeding only happens when
the database has no subjects, so later deploys never touch real progress.

## Connecting Claude

Settings → Connectors → Add custom connector, URL
`https://education.rmmann.co.uk/mcp`. Leave the client ID and
secret blank — the server registers Claude dynamically. Enter
`TRACKER_PASSWORD` on the consent screen.

## When the health check fails

The deploy job fails loudly rather than reporting a copy as a success. Work
through it in this order:

- **"The SSH account … cannot run remote commands".** The account is SFTP-only.
  DreamHost panel → Manage Users → the deploy user → set the type to **Shell
  user (SSH)**, then re-run the workflow. Nothing else in the deploy can work
  until this is right, and it fails quietly rather than loudly: `internal-sftp`
  exits 0 for every command it is handed, so `mkdir` and everything after it
  report success and change nothing.
- **`/healthz` times out, or the browser shows a directory listing or a
  DreamHost placeholder.** Passenger is not enabled on the domain, or the web
  directory is not `…/public`. Fix it in the panel (step 1) and re-run the
  workflow.
- **502 / "Web application could not be started".** Passenger found the app but
  Node failed. SSH in and read the reason:
  ```bash
  ssh edtrackerpaige@education.rmmann.co.uk
  cat ~/education.rmmann.co.uk/log/passenger.log 2>/dev/null
  cd ~/education.rmmann.co.uk && node -e "require('./app.js')"
  ```
  The second command reproduces Passenger's startup in the foreground and
  prints the real stack trace.
- **`node: command not found` in the Passenger log.** Passenger spawns through a
  login shell, so it needs `~/bin` on the PATH. `deploy/remote-setup.sh` adds
  that to `~/.bash_profile`; confirm the line is there and that
  `~/bin/node -v` works.
- **`Check failed: 12 == (*__errno_location ())` in `OS::SetPermissions`.**
  errno 12 is `ENOMEM`: V8 could not allocate executable memory. Read the
  `ulimit -v` the deploy prints just above it. From an SSH session this is
  expected on this host and is not fatal — the deploy carries on, and the
  `--jitless` retry beside it proves the binary itself is sound.

  **`--jitless` is not a fix for the app.** It loads the native module and the
  service does start under it, but Node's built-in `fetch` is undici, and
  undici's HTTP parser is WebAssembly, which `--jitless` removes. The service
  dies with `ReferenceError: WebAssembly is not defined` the moment anything
  touches `fetch`. If Passenger turns out to hit the same cap, the answer is to
  shrink V8's appetite for executable memory some other way, or to host the
  service somewhere without the cap — not to disable the JIT wholesale.
- **`better-sqlite3` fails to load even with `--jitless`.** Now it is the
  binary. If the error mentions `GLIBC_2.x not found`, the box is older than
  the prebuild requires; build the module on a matching base image in CI and
  ship that, rather than installing it on the server. If it mentions the Node
  ABI, `.nvmrc` and the server's Node have drifted — re-run the workflow.
- **The deploy is healthy but Claude says it "couldn't reach the MCP server".**
  Check `PUBLIC_URL` matches the origin exactly, with no trailing slash, and
  that `curl -si -X POST https://education.rmmann.co.uk/mcp -H
  'content-type: application/json' -d '{}'` returns `401` with a
  `WWW-Authenticate: Bearer resource_metadata="…"` header.

## Backups

The database is one file and it is the only copy of the record.

```bash
ssh edtrackerpaige@education.rmmann.co.uk \
  'cd ~/education.rmmann.co.uk && node -e "new (require(\"better-sqlite3\"))(process.env.HOME+\"/tracker-shared/data/tracker.db\").backup(process.env.HOME+\"/tracker-shared/data/backup.db\")"'
scp edtrackerpaige@education.rmmann.co.uk:tracker-shared/data/backup.db \
  ./tracker-backup-$(date +%F).db
```

Worth a weekly cron job on the DreamHost account.
