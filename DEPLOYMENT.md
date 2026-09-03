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
3. Throw away the runner's `node_modules` (`better-sqlite3` is native and must
   be built for the server's Node ABI and glibc, not the runner's).
4. Write `~/tracker-shared/.env` on the server from the repository secrets.
5. `rsync --delete` the release into `~/education.rmmann.co.uk/`.
6. Run `deploy/remote-setup.sh` on the server: install Node via nvm if it isn't
   there, `npm ci --omit=dev`, `touch tmp/restart.txt`.
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

### What lives where on the server

```
~/education.rmmann.co.uk/   deploy target (Passenger app root)
├── app.js          Passenger startup file
├── dist/           compiled service
├── node_modules/   installed on the server, never rsynced
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
`npm ci` and restarts Passenger on the box, and rsync itself spawns a login
shell. An SFTP-only account fails this in the least helpful way possible —
sshd answers every command with `internal-sftp`, which exits 0 having done
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
takes noticeably longer than later ones — it installs Node and builds
`better-sqlite3` before anything answers.

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
  exits 0 for every command it is handed, so `mkdir` and `npm ci` alike report
  success and change nothing.
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
- **`better-sqlite3` fails to load.** Its native binary was built for a
  different Node than the one Passenger is using. Re-run the workflow, which
  reinstalls it against the pinned version in `.nvmrc`.
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
