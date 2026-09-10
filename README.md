# Study tracker service

A small self-hosted service that holds GCSE topic state for any number of subjects and exposes it three ways:

- **A dashboard** at `/s/<subject>`, server-rendered from the database on every request. No regenerate-and-republish cycle. Attempts, sessions and topics are links: `/s/<subject>/a/<id>` is one sitting question by question, `/s/<subject>/session/<id>` is one session and what it changed, `/s/<subject>/t/<ref>` is one topic's whole history, and `/s/<subject>/practice` is the practice scoreboard. `/week/<iso>` is one week judged against the timetable — blocks, hours, movement and the margin note written against it — and `/weeks` is the term as a ledger, one row per week.
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
database and exercises every endpoint, `php deploy/practice-test.php` runs
the practice acceptance tests and the scoreboard golden snapshots, and
`php deploy/continuity-test.php` covers session continuity, unfinished work,
block shape and retrieval scheduling against a frozen clock; DEPLOYMENT.md
has the recipe for poking at it by hand.

| Variable | Purpose |
|---|---|
| `PUBLIC_URL` | The exact public HTTPS origin, no trailing slash. Used as the OAuth issuer and the `resource` value, so a mismatch breaks the handshake. |
| `TRACKER_PASSWORD` | Consent-screen password, and the parent's sign-in for the board at `/login`. Required; the service refuses to serve without it. |
| `DASHBOARD_PUBLIC` | `true` (default) leaves the dashboard readable to anyone with the link. `false` puts it behind the same token as the API. |
| `DB_PATH` | Defaults to `../tracker-shared/data/tracker.db`, outside the document root. |

### Editing the board

The timetable board is readable by anyone with the link, deliberately — she
should be able to glance at it without signing in. Changing it is the parent's
alone: sign in at `/login` with `TRACKER_PASSWORD` and the board grows controls.

A day header gets an **off** button, which books an approved day off with an
optional reason. Each block's status mark becomes a button offering **done
anyway** — for work that really happened but was never logged — and **skipped**,
which excuses it with a reason. Both are reversible.

A block marked done by hand is never shown as a derived done: it gets its own
mark and its own line in the totals, because the board's whole claim is that a
tick means work was logged. Evidence always wins — log the session and the
assertion is superseded.

Sign-in is a signed, httpOnly cookie carrying its own expiry; there is no
session table. Changing `TRACKER_PASSWORD` signs every device out. Writes need
both the cookie and a CSRF token.

### The class bell

Under the header on the index page is a **class bell**. Switched on, the
browser chimes at every boundary in today's timetable — a block ending, the
next one starting — and the line beside it says what has just changed and
what is coming next. A study block starting is a rising three-note chime, a
break or a walk is a two-note ding-dong, and the end of the day falls away.
"Try the sound" plays both without waiting for a boundary.

It is hers, not the parent's: it ships off, the switch needs no sign-in, and
her choice is remembered only in that browser. It writes nothing to the
record — ringing cannot mark a block done, missed or anything else — and it
only rings while the page is open. Browsers refuse to play sound until a page
has been clicked, so if it is reloaded with the bell already on, the status
line asks for one click first.

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
| `tracker_review_queue` | The last session's plan, unfinished work, ageing secures, loose ends, priority gaps. Use this to open a session. |
| `tracker_list_attempts` | Every sitting, with its papers and the grade for the attempt as a whole. |
| `tracker_get_attempt` | One attempt question by question, with marks lost per topic. |
| `tracker_history` | The audit trail week by week: sessions, and every change each one made. |
| `tracker_export_markdown` | Renders the whole state as a markdown document. |
| `tracker_update_topic` | Change one topic. Evidence is mandatory. |
| `tracker_log_session` | Log a session and apply its updates in one call. The normal way to close a session. Carries `unfinished`, `resolves` and, for a taught session, its `review`. |
| `tracker_amend_session` | Correct or void a session already logged; set or clear its unfinished item. |
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
| `tracker_today` | Which block she is in now, what is next, what has been missed today — and each subject's last-session plan and open unfinished work. |
| `tracker_retrieval_due` | An ordered, interleaved set of retrieval items for a block, from the spacing schedule. |
| `tracker_week_status` | A whole week block by block, with counts and hours against target. |
| `tracker_get_timetable` | The timetable version in force on a date. Read before any re-cut. |
| `tracker_set_timetable` | Replace the timetable from a date. Refuses overlaps and unknown slugs. |
| `tracker_days_off` | Holidays and days off in a range, with who asked and what was decided. |
| `tracker_request_day_off` | Ask for time off. A student's request stays a request until the parent approves it. |
| `tracker_decide_day_off` | Parent-only: approve, decline or un-approve one. |
| `tracker_excuse_block` | Excuse one block on one date, with the parent's reason. Null un-excuses. |
| `tracker_tick_block` | Tick a self-reported block. Refused on study blocks — those are judged from logged work. |
| `tracker_week_report` | Everything a week is judged on in one call: blocks, extras, hours against the timetable, movement, attempts, practice, queue tops, blocks done in the wrong shape, unfinished work. |
| `tracker_save_weekly_review` | Save the written half of a week as a new version. The tracker attaches its own snapshot of the figures. |
| `tracker_get_weekly_review` | Read a saved review back, with the snapshot it was written against and how the record has moved since. |
| `tracker_save_lesson_review` | Save or re-version one session's lesson review. The normal path is the `review` argument of `tracker_log_session`; this is for the audit and the parent. |
| `tracker_get_lesson_review` | One lesson review in the standard layout, with its snapshot and the drift since. |
| `tracker_list_lesson_reviews` | Reviewed sessions one line each — or, with `missing: true`, the sessions still owed one. |
| `tracker_signals` | The signals: how she learns and what lands, each with a derived strength and its evidence trail. |
| `tracker_update_signal` | Resolve or refute a signal, set its next test, or add an evidence row. Never a bare strength change. |
| `tracker_review_audit_queue` | The auditor's opener: sessions owed a review, drafts to verify, consistency flags, the last audit's note. |
| `tracker_audit_stamp` | Close an audit with a note. |

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
Steps 5 and 8 re-run the source seed, which is how a source added later
(`retrieval_mixed`, `cs_code_lab`) reaches a database step 3 already seeded.
Step 9 adds the unfinished-work and `consolidates` columns to sessions, step
10 creates and seeds `block_kind_rules`, and step 11 adds `item_key` to
practice items, the `retrieval_state` and `retrieval_config` tables, and the
`retrieval_warmup` and `retrieval_subject` sources. None of them backfills:
no old summary is scraped for the word "unfinished", and no old practice run
is replayed into the schedule.

## Continuity between sessions

Two things used to depend on a model remembering to make a second call: what
the last session planned, and what it left half done. Both are now carried
by the service.

**The last session.** `tracker_review_queue` and `tracker_today` open with a
`last_session` block for the subject — the most recent non-void session, its
`next_steps` verbatim, the tail of its summary, any unfinished item it
carries, and `stale: true` when it is more than fourteen days old. Stale is a
flag, never a filter: the plan is still shown and the caller decides.

**Unfinished work.** A session that stopped before the end says so with
`unfinished` (at most 300 characters saying what is outstanding) and
optionally `unfinished_refs`. Presence is the flag; there is no separate
boolean. The item leads the review queue, ahead of the ageing secures, until
a later session passes `resolves: [id]`, which stamps the closure — when, by
which session, why — on the original row. Nothing is deleted: `tracker_history`
shows the item on the session that opened it and the closure on the session
that closed it. An item open more than 21 days is flagged stale and still
listed first; past 56 days it is auto-closed with the reason `auto-closed,
stale` and no closing session, and still shows in the history. Clearing one
by hand is `tracker_amend_session` with `unfinished: null`, which closes it
as `cleared by hand` rather than erasing it.

When a session is logged for a subject with an open item and neither
resolves it nor sets a new one, the write succeeds and the result carries a
warning naming the item and the `resolves` call that would close it. The
service never gates teaching; it makes the fact impossible to miss. Passing
`resolves` for an item already closed is a no-op, the rule `client_run_id`
already keeps for practice.

## Block shape

The board decides whether a block was done — a record exists for one of its
subjects on its date. `block_kind_rules` decides whether the work had the
shape the block asked for: a retrieval block satisfied by a three-item run, a
consolidation session that named no errors, a teach session that moved no
topic. Such a block is reported as **done_shape_unmet** with a `shape_reason`
that describes the work. It counts as done for adherence and hours, is listed
apart in `tracker_week_report` and on the week page, and is never converted
into a miss — a missed block still means nothing was logged.

The rules are rows, keyed by kind, so a new kind is a row and a rule that
turns out wrong is an `UPDATE`. A row is a list of alternatives, each an
object of conditions that must all hold (`min_items`, `min_updates`,
`min_updates_with_evidence`, `min_distinct_topics`, `consolidates_nonempty`,
`recent_error_update_days`, `min_duration_minutes`, `min_duration_fraction`,
`evidence_type`; the vocabulary is documented in `php/lib/shape.php`). A kind
with no row is judged as always met and the response says so, so it can be
added rather than improvised twice. The binding refinement — a timed block
takes an attempt, a retrieval block takes a `retrieval_` practice run — is
the `satisfied_by` column of the same row, and is deliberately no stricter
than it was before the rules existed.

A consolidation session states what it re-worked with `consolidates:
[{ ref, error_session_id? }]` on `tracker_log_session`, rather than the
service inferring it from prose.

## Retrieval scheduling

`tracker_log_practice` already stores one row per item. Scheduling runs over
those rows at two grains: **item**, where the client supplies a stable
`item_key` (a registry id, or a hash of the canonical prompt, unique per
subject), and **topic**, always, from `topic_ref` — which is what makes the
feature work on day one for a subject with no item bank. Per (subject, grain,
key) the service holds `last_asked`, `next_due`, the streaks,
`difficulty_level` (1 plain · 2 varied · 3 exam), `needs_scaffold` and
`retired`. Correct moves `next_due` out along the ladder; a retry is three
days; incorrect is tomorrow, and two in a row raise the scaffold. An item at
level 3 with four straight correct answers retires, and comes back when it is
answered wrongly or its topic is demoted.

The ladder is a row in `retrieval_config` — `*` holds the default
`[1, 3, 7, 14, 30, 60]` days, and a row for a subject overrides it — so
compressing the spacing for the final phase is a row, not a deploy.

Sessions feed the topic grain too. A status rise counts as correct and a
demotion as incorrect. An update that leaves the status where it was says
nothing on its own — on this record those are as often "left blank, walked
through" as "held" — so an update may carry an explicit `retrieval_outcome`.

`tracker_retrieval_due` returns the set for a block already ordered and
mixed: every subject named gets at least two slots, the rest weight toward
the subject with the most instability in the last fortnight, and no two
consecutive entries share a subject or a topic. Each entry carries a short
`why` ("failed twice, 4 Sep and 8 Sep") the caller can quote in its evidence.
Retrieval runs are logged with a source starting `retrieval_`
(`retrieval_warmup`, `retrieval_mixed`, `retrieval_subject`,
`retrieval_quotes`), which is what satisfies a retrieval block and keeps them
apart from app games in `tracker_practice_stats`.

## The weekly review

A week has two halves. The **computed** half — which blocks were done, short,
missed, excused or on a day off, what was logged outside the timetable, the
hours each subject actually got — is judged from the record on every request by
`judgeWeek()`, so two people opening `/week/2026-W37` an hour apart see the same
figures unless the record changed in between. The **written** half is what
Claude and the parent make of it on a Friday: what held, what slipped, one
carry-forward per subject, what next week starts with, and what the parent
decided. That is judgement, it isn't derivable, and it is stored as a dated,
signed margin note against the week rather than mixed into the figures.

`tracker_save_weekly_review` never takes the numbers from the caller. The
tracker attaches its own **snapshot** — counts, hours, every judged block,
movement, attempts, practice, coverage and the queue tops as they stand at save
time — so a note written before an excusal can say *written when 2 were missed;
1 has since been excused* instead of quietly contradicting the ledger beside it.
That comparison is the drift line, and `tracker_get_weekly_review` and the page
render it from the same `Store::weekDrift()`. If the note says three blocks were
missed and the snapshot says two, the snapshot is right and the note is a note.

Notes are never edited. Versions are appended, the latest is shown, the earlier
ones stay readable — the same rule that governs sessions and practice runs. A
re-save identical to the version already there is a silent no-op rather than a
phantom row, and a `draft` is refused once a `reviewed` version exists, so a
routine that fires late cannot stamp a draft over a review that has happened.
A decision recorded in a review only *records* it: excusing a block is still
`tracker_excuse_block`, deciding a day off still `tracker_decide_day_off`.

Counts are partitioned wherever they appear — study blocks, movement and the
Friday review block are three fractions, not one, because "23 of 27" puts a walk
and a maths block in the same number. Hours are read against what the timetable
actually planned for that week (the blocks that resolve to a single subject,
alternating blocks by ISO week parity), not against the target split the skills
quote: read against the split, English Language would be amber every week for
ever, through nothing anyone did.

## Lesson reviews

Every taught session ends with a review, the way every week ends with one.
A lesson review is the written half of one session — nineteen sections held
as fields (`docs/lesson-review.md` has the contract): what was seen for each
topic and what it implies, what she did unaided and with support, each error
typed (`procedure`, `misconception`, `instruction_misread`, …) with the
teaching response it requires, retention judged item by item, learning-process
observations marked `observed`, `interpretation` or `unknown`, which methods
helped and hindered, a readiness call, and a six-line **planner** the next
session reads first. It arrives as the `review` argument of
`tracker_log_session`: the session row, its topic changes, its retrieval
outcomes and the review are written in one transaction, and a review that
fails validation refuses the whole call naming the field, so a session never
lands half-reviewed. If a block's kind requires a review (a column on
`block_kind_rules`; extras of 30 minutes or more count too) and none is sent,
the session still logs — the student's "logged" is never blocked — and the
reply says so.

Three rules are the server's, not the skill's. **The review proposes; the
record adjudicates.** A review never moves a topic: `progress[].status_seen =
secure` moves nothing, and `proposed_status` is a proposal for
`gcse-progress-tracker` to test against the promotion bar. **One occurrence is
not a pattern.** Observations about how she learns and how methods land are
stored as *signals*, keyed by a stable slug and strengthened across sessions;
a signal may not claim `emerging` without two distinct supporting sessions or
`established` without three and no newer uncontradicted contradiction. A claim
above the evidence is reported back with the counts and held where it was.
**Retention is scheduled.** Every retention entry must be backed by a
`retrieval_outcome` on that ref in `updates[]`, which is what feeds the spacing
ladder.

Nothing new is called at the start of a session. `tracker_review_queue` gains a
`last_review` block — the planner, the things to watch, the open signals with
their pending tests, last time's errors and the readiness call — in the call
the tutor already makes. `tracker_today` marks each done block `reviewed` or
`review missing`; `tracker_history` tags sessions with their review version and,
in `ref` mode, lists the topic's error rows; `tracker_get_state` with `ref`
tallies its error types; `tracker_week_report` adds `REVIEWS THIS WEEK` and
`SIGNAL MOVEMENT` so the Friday review rolls patterns up without re-deriving
them.

Reviews are versioned like weekly reviews — `draft` from the session,
`audited` from the scheduled auditor, `parent` from the parent's chat — with a
server-built snapshot beside each: the block as the board judged it, every
mentioned topic's status before and after, the outcomes recorded, the practice
that day. `tracker_review_audit_queue` gives the auditor its list — sessions
owed a review, drafts to re-derive from the transcript, and the consistency
flags (a secure seen with no proposal and no move, a promotion whose evidence
carries no number, a two-level rise, a test untested for three sessions, a
watch unreferenced for five, a quote that also appears in the session summary)
— and `tracker_audit_stamp` closes it.

On the pages the review is private by default: the session page, the topic
page's error history, `/s/{slug}/reviews`, `/signals` and the week page's
readiness chips render only for the signed-in parent. Everyone else sees a
"reviewed" tick on the session and nothing more.

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
— a new chart for a new need needs no deploy.

| Type | Options beyond `title` and `source` |
|---|---|
| `stat` | `metric`, `window` (`all`, `lastN`, `Nd`), `agg`, `format`, `label` to replace the generated caption |
| `line` | `metric`, `limit`, `y_axis` (`auto` or `percent`), `label_points`, `format` |
| `table` | `columns`, `limit` |
| `topics` | `sort`, `limit`, `metrics` — which columns the breakdown shows |
| `split` | `limit`, `group_by` (`run`, `source` or `topic`) |

An invalid panel rejects the whole configuration, so a bad edit cannot
half-apply, and that includes an option the renderer does not read: a
misspelled key is refused rather than silently ignored, because a key that does
nothing is how a board ends up not matching the configuration someone believes
they wrote. An unknown panel *type* in an already-stored configuration is a
different case — it is skipped with a logged warning rather than breaking the
page, so a board written by a newer version still renders everything else.

The board is at `/s/<subject>/practice`, with the first four panels also shown
on the subject dashboard. Activities are picked from cards rather than a
dropdown: each card carries the two figures that decide whether the click is
worth making — how many runs are in view, and how many she got right first
time — so choosing an activity confirms something already on the screen instead
of revealing it. The counts ignore the activity the board is already narrowed
to, or every card but the selected one would read zero. An activity with
nothing logged is not a link at all: filtering to a guaranteed empty page is a
dead end, so it renders as an invitation to go and play it. The cards are
ordinary links, so the board works with JavaScript off.

Date ranges are chips writing `?window=30d`, a rolling window rather than a
frozen date, so a bookmarked board still means the last thirty days next month.
The two date boxes and the topic filter are kept behind a disclosure and open
automatically when they are what is filtering the board. `?from=`/`?to=` still
work and beat a window; the two are alternatives and setting either clears the
other.

Charts are server-rendered inline SVG with no charting dependency. Every chart
carries a `<title>` and repeats its numbers as a table — the chart is never the
only route to the data.

**The Spanish board is pinned.** It has to look and behave exactly as the app's
own view does, because that view is the delivery she actually cares about and
the reason she keeps playing. Its configuration is seeded by migration and
covered by a golden snapshot in `tests/golden`, rendered against a fixed
fixture and diffed on every build; the chart geometry — viewBox `0 0 600 232`,
padding 62, baseline y=192, span 132, stroke, star radius, label offsets — is
pinned in `practice.php`. Each run is drawn as a star sized by its value, so
the score is encoded twice, as height and as area; the best run in view is gold
under a dashed record line. Crowding is handled by geometry and never by
dropping runs: past about 34px between stars they shrink, only the record and
the latest run keep their value label, and the dates thin to six. Any change to
a panel type must re-run
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
per-keystroke telemetry and no cross-subject comparison. The gold star and the
record line are the one concession, and they stay inside that rule: they mark
her own best run against her own, which is the thing the chart was always for.
Nothing on the board counts consecutive days or withholds anything for missing
one. The chart exists
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
