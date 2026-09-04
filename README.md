# Study tracker service

A small self-hosted service that holds GCSE topic state for any number of subjects and exposes it three ways:

- **A dashboard** at `/s/<subject>`, server-rendered from the database on every request. No regenerate-and-republish cycle. Attempts, sessions and topics are links: `/s/<subject>/a/<id>` is one sitting question by question, `/s/<subject>/session/<id>` is one session and what it changed, `/s/<subject>/t/<ref>` is one topic's whole history, and `/s/<subject>/practice` is the practice scoreboard.
- **A JSON API** at `/api/subjects` for scheduled jobs, token-guarded.
- **An MCP endpoint** at `/mcp`, so Claude can read the state at the start of a session and write status changes at the end.

It holds the syllabus too: strands, every topic with its spec reference and tier, and the teaching materials attached to each — BBC Bitesize pages, videos, worksheets, past papers. The review queue hands those back alongside the topics that need work, so a session can be planned from one call.

The database is the source of truth. Markdown topic-state files become an *export* (`tracker_export_markdown`), not a thing you hand-edit.

## Why it speaks OAuth

Claude.ai custom connectors don't take a static bearer token the way Claude Code does — static header auth is a beta feature entered by an organisation administrator. OAuth with Dynamic Client Registration is supported out of the box, so this service ships a minimal OAuth 2.1 authorisation server: DCR, PKCE with S256, refresh-token rotation, and both discovery documents.

There's one human user, so "log in" is a single shared password on the consent screen rather than a user table. `TRACKER_PASSWORD` is the only thing between the internet and the tracker. Make it long and random.

## Where it runs

Production is <https://education.rmmann.co.uk>, deployed automatically on every
merge into `main`. It is a PHP application served per request by Apache — no
build step, no daemon, no process manager. See [DEPLOYMENT.md](DEPLOYMENT.md)
for the pipeline, the repository secrets it needs, why it is PHP rather than
the Node service it began as, and what to check when a deploy goes red.

To run it locally, `bash deploy/smoke-test.sh` boots it against a throwaway
database and exercises every endpoint, and `php deploy/practice-test.php` runs
the practice acceptance tests and the scoreboard golden snapshots;
DEPLOYMENT.md has the recipe for poking at it by hand.

| Variable | Purpose |
|---|---|
| `PUBLIC_URL` | The exact public HTTPS origin, no trailing slash. Used as the OAuth issuer and the `resource` value, so a mismatch breaks the handshake. |
| `TRACKER_PASSWORD` | Consent-screen password. Required; the service refuses to serve without it. |
| `DASHBOARD_PUBLIC` | `true` (default) leaves the dashboard readable to anyone with the link. `false` puts it behind the same token as the API. |
| `DB_PATH` | Defaults to `../tracker-shared/data/tracker.db`, outside the document root. |

## Connecting Claude

1. Settings → Connectors → Add custom connector.
2. URL: `https://education.rmmann.co.uk/mcp` — exactly matching
   `PUBLIC_URL` plus `/mcp`.
3. Leave the OAuth client ID and secret blank. The server registers Claude dynamically.
4. Claude opens the consent screen; enter `TRACKER_PASSWORD` and allow access.

Verify from the command line first if the connector misbehaves:

```bash
curl -s https://education.rmmann.co.uk/.well-known/oauth-protected-resource | jq
curl -si -X POST https://education.rmmann.co.uk/mcp -H 'content-type: application/json' -d '{}' | head -3
```

The second must return `401` with a `WWW-Authenticate: Bearer resource_metadata="…"` header. If it doesn't, Claude can't discover the authorisation server and you'll get "couldn't reach the MCP server" no matter what else is right.

## The tools

| Tool | Purpose |
|---|---|
| `tracker_list_subjects` | Every subject with a coverage percentage. |
| `tracker_list_resources` | The materials stored for a topic or subject. |
| `tracker_get_state` | Full topic state, filterable by status or strand. Consult before teaching. |
| `tracker_review_queue` | Ageing secures, loose ends, priority gaps. Use this to open a session. |
| `tracker_list_attempts` | Every sitting, with its papers and the grade for the attempt as a whole. |
| `tracker_get_attempt` | One attempt question by question, with marks lost per topic. |
| `tracker_history` | The audit trail week by week: sessions, and every change each one made. |
| `tracker_export_markdown` | Renders the whole state as a markdown document. |
| `tracker_update_topic` | Change one topic. Evidence is mandatory. |
| `tracker_log_session` | Log a session and apply its updates in one call. The normal way to close a session. |
| `tracker_amend_session` | Correct or void a session already logged. |
| `tracker_log_attempt` | Record a sitting: its papers, and the questions, answers and marks behind them. |
| `tracker_add_resource` | Attach materials — Bitesize, videos, worksheets, past papers — to a topic or the whole subject. |
| `tracker_remove_resource` | Delete one stored resource by title. |
| `tracker_create_subject` | Add a subject, or extend one. Never resets existing topic statuses. |
| `tracker_log_practice` | Record practice runs — app games, drills, tutoring sessions — in one batched call. |
| `tracker_list_practice` | Every practice run, newest first, voided ones marked VOID. |
| `tracker_practice_stats` | How practice is going: totals, best run, pooled accuracy, per-topic breakdown. |
| `tracker_void_practice` | Mark one practice run as not counting, with a reason. Never hard-deletes. |
| `tracker_get_scoreboard` | Read a subject's scoreboard panel configuration. |
| `tracker_set_scoreboard` | Replace it. One invalid panel rejects the whole configuration. |

Every description leads with a `USE WHEN` line naming the situations that should trigger it, so the model reaches for a tool because the moment calls for it rather than inferring relevance from a description of mechanics.

Three rules are enforced rather than left to good intentions. Every status change requires an evidence string of at least ten characters, so the audit trail in `topic_changes` can't be empty. Topic checks are never grade-converted — only full papers are scaled against boundaries — so a good result on seven topics can't quietly become a projected grade. And a question breakdown that doesn't add up to the paper total is refused outright: one of the two figures is wrong, and keeping both would make the per-topic analysis lie.

## The audit trail

The record is meant to be readable backwards, not just forwards.

**Sessions.** Every status change is stamped with the session that made it, so
the trail says not only that a topic moved but which sitting moved it and on
what evidence. `tracker_history` groups all of it by ISO week, so a term reads
as a timeline. A session logged wrongly is corrected with
`tracker_amend_session` or voided with a reason — the row and the reason stay,
because a trail that can lose entries isn't one. A topic status is never
rewritten; correcting it means another change with evidence saying so, which
appends to the trail.

**Attempts.** One sitting is one *attempt*, and an attempt holds however many
papers were sat together. A three-paper mock is one attempt with three papers
and a single grade computed across all of them, because one paper of three
doesn't carry a grade. Under each paper sit its questions: the number, the
marks, the answer given, the marker's note, and the spec reference the question
tests. Those references are what turn a score into teaching information —
`tracker_get_attempt` adds up marks lost per topic and names the topics to
reteach.

Each of those views is a page as well as a tool call. The tools were always
able to report the question-by-question record; until these pages existed the
dashboard could only say how many questions had been recorded, which is a
count, not an audit trail.

Migrations run on first open as a numbered ladder (`meta.schema_version`),
each step in its own `BEGIN IMMEDIATE` transaction so concurrent requests
cannot double-apply one. Step 1 turned each old assessment into an attempt
with one paper; the original `assessments` table is left untouched as the
fallback copy. Step 2 added per-paper `sat_on` and grouped the three AQA 8300
June 2022 foundation papers into the single sitting they actually were — held
as three attempts, each 80-mark paper was scaled against the 240-mark boundary
table on its own and reported a grade for an exam only a third sat. That step
is guarded on the exact shape step 1 produces, so a database where those rows
have since been edited or built on is left alone. Step 3 adds the practice
tables, seeds the source registry and pins the Spanish and maths scoreboards.

## Practice

A **practice run** is one bounded stretch of practice: one Shooting Gallery
game in the Spanish app, one maths tutoring session. Both are the same shape of
thing — *she did some practice, here is how it went* — so they share one
storage model and one scoreboard engine.

A run records what was **attempted**, what was **correct** first time, what was
**correct_after_retry** (right eventually, after a retry, hint or reteach) and
what was **incorrect**. Those three must add up to `attempted`, enforced by the
tool and by a `CHECK` constraint, because every figure on the board is built on
that arithmetic. Two rates come out of it: **accuracy** is `correct /
attempted`, unassisted success; **solve rate** is `(correct +
correct_after_retry) / attempted`, success with support.

**A drill is not an attempt.** Attempts are marked papers that carry a grade
and drive the projection. Practice arrives dozens per week, has no mark scheme,
and is never grade-converted, never appears in `tracker_list_attempts`, and
**never moves a topic status** — status moves through sessions only, where the
thresholds and the no-downgrade rule already live. Two write paths for status
is how a record stops being trustworthy.

Ingest is one batched call at the end of a session rather than one call per
game, and it goes through MCP rather than a POST: the Spanish app runs inside a
Claude artifact sandbox that can only reach `api.anthropic.com`, so the model
makes the call. Every run carries a `client_run_id` from the client; repeating
a call with the same id is a silent no-op returning the existing row. The app
retries failed reports and a model-driven tool call can fire twice, and without
that one retry would put a phantom spike in the trend line.

Per-item detail is optional and sparse-friendly: the Spanish app supplies items
only for the words missed, a maths session supplies every question with its
topic reference and how many attempts it took. Topic figures roll up **from the
items when a run has them, and are apportioned across the run-level
`topic_refs` otherwise — never both**, or the counts double. That rule is one
function with tests behind it rather than logic scattered across queries.

### Scoreboards

Each subject's board is an ordered list of panels stored as JSON against the
subject: panel *types* are code, panel *instances* are configuration. Types are
`stat`, `line`, `table`, `topics` and `split`, every one of them taking a title
and an optional `source` filter, so one subject can show separate panels per
activity. `tracker_set_scoreboard` writes the configuration, which is the point
— a new chart for a new need needs no deploy. An invalid panel rejects the
whole configuration, so a bad edit cannot half-apply; an unknown panel type in
a stored configuration is skipped with a logged warning rather than breaking
the page.

The board is at `/s/<subject>/practice`, filterable by activity, date range and
topic, with the first four panels also shown on the subject dashboard. It is
server-rendered inline SVG: the app's chart is thirty lines of SVG, so the
tracker matches it rather than adding a charting dependency. Every chart
carries a `<title>` and repeats its numbers as a table — the chart is never the
only route to the data.

**The Spanish board is pinned.** It has to look and behave exactly as the app's
own view does, because that view is the delivery she actually cares about and
the reason she keeps playing. Its configuration is seeded by migration and
covered by a golden snapshot in `tests/golden`, rendered against a fixed
fixture and diffed on every build; the chart geometry — viewBox, padding,
baseline, stroke, point radius, label offset — is pinned in `practice.php` and
shared with the app. Any change to a panel type must re-run
`php deploy/practice-test.php`: a refactor that improves the maths board and
shifts the Spanish chart by two pixels is a failed change. Run it with
`--update` to rewrite the snapshots deliberately, and read the diff before you
commit it. Local browser history in the app stays as it is — the tracker is the
durable copy, browser storage is the fast one, so her chart still renders
instantly and offline even if the tracker is slow.

Accuracy on the board is always **pooled** — total correct over total attempted
— not the mean of the per-run percentages. Over the four fixture games those
are 76.8% and 77.2%; they differ, and pooled is the honest one.

There are deliberately no leaderboards, streaks, badges or nudges, no
per-keystroke telemetry and no cross-subject comparison. The chart exists
because she likes seeing progress, not to manufacture obligation, and comparing
Spanish accuracy to maths accuracy would invite the wrong conclusion.

## Adding a subject

Ask Claude, once connected: *"Add GCSE Combined Science to the tracker — AQA 8464, Higher, strands for biology, chemistry and physics, seeded from this spec list."* It calls `tracker_create_subject`. Re-running it later adds new topics without touching statuses you've already earned.

Grade boundaries are per-subject. `boundary_max` is the total the boundaries are expressed against (240 for maths, 160 for English Language), and `boundaries` maps a tier to `[[grade, mark], …]`.

## Backups

The database is a single file and it is the only copy of the record; see
[DEPLOYMENT.md](DEPLOYMENT.md#backups). Worth a weekly cron.

## Known limits

- One password, one tenant. Fine for a household; don't hand the URL to a class.
- Stateless MCP: no server-initiated notifications or streaming. Tools are request/response, which is all these need — and all a per-request PHP process could offer anyway.
- Access tokens live an hour and refresh tokens don't expire until used. Revoking means deleting rows from `oauth_tokens`.
- The seed maps vague dates in the source document (`Jun 26`, `Aug 26`) to concrete ones so the ageing rule can compute. Topics with no recorded date stay null and the review queue says so rather than inventing one.
