# Study tracker service

A small self-hosted service that holds GCSE topic state for any number of subjects and exposes it three ways:

- **A dashboard** at `/s/<subject>`, server-rendered from the database on every request. No regenerate-and-republish cycle.
- **A JSON API** at `/api/subjects` for scheduled jobs, token-guarded.
- **An MCP endpoint** at `/mcp`, so Claude can read the state at the start of a session and write status changes at the end.

The database is the source of truth. Markdown topic-state files become an *export* (`tracker_export_markdown`), not a thing you hand-edit.

## Why it speaks OAuth

Claude.ai custom connectors don't take a static bearer token the way Claude Code does — static header auth is a beta feature entered by an organisation administrator. OAuth with Dynamic Client Registration is supported out of the box, so this service ships a minimal OAuth 2.1 authorisation server: DCR, PKCE with S256, refresh-token rotation, and both discovery documents.

There's one human user, so "log in" is a single shared password on the consent screen rather than a user table. `TRACKER_PASSWORD` is the only thing between the internet and the tracker. Make it long and random.

## Where it runs

Production is <https://education-tracker.dreamhosters.com>, deployed
automatically on every merge into `main`. See [DEPLOYMENT.md](DEPLOYMENT.md) for
the pipeline, the one-time DreamHost panel setup, the repository secrets it
needs, and what to check when a deploy goes red.

The Docker setup below is for running it locally or on a box you control.

## Running it locally

```bash
cp .env.example .env
# edit .env: set PUBLIC_URL and TRACKER_PASSWORD
openssl rand -base64 24        # a decent password
docker compose up -d --build
```

On first start it seeds the maths subject from `03-TOPIC-STATE.md` v1.1. Seeding only runs when the database has no subjects, so restarts and rebuilds never overwrite real progress. Data lives in the `tracker-data` volume.

| Variable | Purpose |
|---|---|
| `PUBLIC_URL` | The exact public HTTPS origin, no trailing slash. Used as the OAuth issuer and the `resource` value, so a mismatch breaks the handshake. |
| `TRACKER_PASSWORD` | Consent-screen password. Required; the server refuses to start without it. |
| `DASHBOARD_PUBLIC` | `true` (default) leaves the dashboard readable to anyone with the link. `false` puts it behind the same token as the API. |
| `DB_PATH` | Defaults to `/data/tracker.db`. |

## Getting a local instance on the internet

Production already has a public hostname and a certificate; this section is for
an instance you're running yourself. Compose binds to `127.0.0.1` deliberately — the box shouldn't be listening on the LAN. Put a tunnel in front so you get HTTPS and a stable hostname without opening a port:

```bash
cloudflared tunnel --url http://localhost:8080          # quick test, random hostname
```

For anything lasting, use a named tunnel on a domain you own, or Tailscale Funnel. Two constraints matter:

- **HTTPS is required** and the certificate must be publicly valid. A self-signed cert will fail the connector handshake.
- **`/.well-known/*` must be reachable at the origin root**, not just your MCP path. The discovery documents live there.

If you firewall the tunnel origin, Anthropic's outbound traffic comes from `160.79.104.0/21`.

## Connecting Claude

1. Settings → Connectors → Add custom connector.
2. URL: `https://education-tracker.dreamhosters.com/mcp` — exactly matching
   `PUBLIC_URL` plus `/mcp`.
3. Leave the OAuth client ID and secret blank. The server registers Claude dynamically.
4. Claude opens the consent screen; enter `TRACKER_PASSWORD` and allow access.

Verify from the command line first if the connector misbehaves:

```bash
curl -s https://your-host/.well-known/oauth-protected-resource | jq
curl -si -X POST https://your-host/mcp -H 'content-type: application/json' -d '{}' | head -3
```

The second must return `401` with a `WWW-Authenticate: Bearer resource_metadata="…"` header. If it doesn't, Claude can't discover the authorisation server and you'll get "couldn't reach the MCP server" no matter what else is right.

## The tools

| Tool | Purpose |
|---|---|
| `tracker_list_subjects` | Every subject with a coverage percentage. |
| `tracker_get_state` | Full topic state, filterable by status or strand. Consult before teaching. |
| `tracker_review_queue` | Ageing secures, loose ends, priority gaps. Use this to open a session. |
| `tracker_list_assessments` | Papers and checks with grade conversions. |
| `tracker_export_markdown` | Renders the whole state as a markdown document. |
| `tracker_update_topic` | Change one topic. Evidence is mandatory. |
| `tracker_log_session` | Log a session and apply its updates in one call. The normal way to close a session. |
| `tracker_log_assessment` | Record a paper or check. |
| `tracker_create_subject` | Add a subject, or extend one. Never resets existing topic statuses. |

Two rules are enforced in the schema rather than left to good intentions. Every status change requires an evidence string of at least ten characters, so the audit trail in `topic_changes` can't be empty. And topic checks are never grade-converted — only full papers are scaled against boundaries — so a good result on seven topics can't quietly become a projected grade.

## Adding a subject

Ask Claude, once connected: *"Add GCSE Combined Science to the tracker — AQA 8464, Higher, strands for biology, chemistry and physics, seeded from this spec list."* It calls `tracker_create_subject`. Re-running it later adds new topics without touching statuses you've already earned.

Grade boundaries are per-subject. `boundary_max` is the total the boundaries are expressed against (240 for maths, 160 for English Language), and `boundaries` maps a tier to `[[grade, mark], …]`.

## Backups

```bash
docker compose exec tracker \
  node -e "new (require('better-sqlite3'))('/data/tracker.db').backup('/data/backup.db')"
docker compose cp tracker:/data/backup.db ./tracker-backup-$(date +%F).db
```

Worth a weekly cron. It's a single file and it's the only copy of the record.

## Known limits

- One password, one tenant. Fine for a household; don't hand the URL to a class.
- Stateless MCP: no server-initiated notifications or streaming. Tools are request/response, which is all these need.
- Access tokens live an hour and refresh tokens don't expire until used. Revoking means deleting rows from `oauth_tokens`.
- The seed maps vague dates in the source document (`Jun 26`, `Aug 26`) to concrete ones so the ageing rule can compute. Topics with no recorded date stay null and the review queue says so rather than inventing one.
