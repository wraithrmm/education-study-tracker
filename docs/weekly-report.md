# The weekly report

The implementation spec for `/week/{iso}`, `/weeks`, the three tools behind them, the Friday
routine and the tests. It builds on the timetable release (schema step 4, `judgeWeek()`, the nine
`tracker_*` timetable tools) which is unmerged at the time of writing on
`origin/claude/education-tracker-weekly-timetable-okec05`. Nothing here should be re-derived from
the design deck: the deck is the picture, this is the contract.

## 1. Purpose and the split

Every number on a weekly report is **computed**. `judgeWeek()` already decides, from the record,
which blocks were done, short, missed, excused or on a day off, what was logged outside the
timetable, and how many hours each subject actually got. The page runs that on every request, the
way `/s/{slug}` does. Two people opening `/week/2026-W37` an hour apart see the same figures
unless the record changed in between, and if Dad excuses a block on Saturday the page says so on
Saturday without anyone regenerating anything.

What Claude adds on a Friday is **written**: what held, what slipped, one carry-forward per
subject, what next week starts with. That is judgement, it is not derivable, and two runs of the
same routine would not word it the same way — which is exactly why it is stored as a dated,
signed margin note against the week rather than mixed into the figures. The note carries a
**snapshot** of the counts it was written against, so a note written before an excusal can say
"written when 2 were missed; 1 has since been excused" instead of quietly contradicting the
ledger beside it. Computed on the left, written in the margin, and the page never blurs the two.

A practical consequence, worth stating once: a note is never edited. Versions are appended, the
latest is shown, the earlier ones stay readable. A record that can lose entries is not one — the
same rule that governs sessions and practice runs.

## 2. Schema step 6

The ladder is in `php/lib/store.php`: `SCHEMA_VERSION`, `migrate()`, `migrateStep()`, each step in
its own `BEGIN IMMEDIATE` so two requests arriving together cannot both apply it. Step 6 adds one
table and nothing else — the week is judged live, so no existing row gains a column.

Bump the constant:

```php
private const SCHEMA_VERSION = 6;
```

Add the step, in the ladder's style:

```php
if ($v === 6) {
    // The weekly review: the one thing on these pages that a person or the
    // Friday routine writes rather than the tracker computing it. Versions are
    // appended and never edited, so "what did we think on the Friday" survives
    // the excusal that came after it.
    $this->createWeeklyReviewTable();
    return;
}
```

and the table, alongside `createTimetableTables()`:

```php
/**
 * Margin notes against a week. Append-only: one row per version, the latest
 * shown, the earlier ones still readable. snapshot_json is written by the
 * server from judgeWeek() and the week's record, never by the caller, so a
 * note cannot mis-state the week it was written about.
 */
private function createWeeklyReviewTable(): void
{
    $this->db->exec(
        "CREATE TABLE IF NOT EXISTS weekly_reviews (
           id            INTEGER PRIMARY KEY AUTOINCREMENT,
           week          TEXT NOT NULL,
           version       INTEGER NOT NULL,
           stage         TEXT NOT NULL CHECK (stage IN ('draft','reviewed')),
           written_by    TEXT NOT NULL CHECK (written_by IN ('routine','chat')),
           written_at    TEXT NOT NULL DEFAULT (datetime('now')),
           snapshot_json TEXT NOT NULL,
           sections_json TEXT NOT NULL,
           note          TEXT,
           UNIQUE (week, version)
         )"
    );
    $this->db->exec(
        'CREATE INDEX IF NOT EXISTS idx_weekly_reviews_week ON weekly_reviews(week, version DESC)'
    );
}
```

Idempotent twice over: `CREATE TABLE IF NOT EXISTS` / `CREATE INDEX IF NOT EXISTS`, and the ladder
itself re-checks `schemaVersion()` inside the write lock before running the step. `week` is the
ISO label `2026-W37`, not a date, because that is what every caller says and what
`tt_week_monday()` already parses.

Writes go through one store method, which allocates the version inside the same transaction:

```php
public function addWeeklyReview(array $r): array   // week, stage, written_by, snapshot, sections, note?
public function weeklyReview(string $week, ?int $version = null): ?array   // latest, or one version
public function weeklyReviewVersions(string $week): array                  // id, version, stage, written_at, written_by
public function latestWeeklyReviews(array $weeks): array                   // week => latest row, for /weeks
```

`addWeeklyReview()` runs `BEGIN IMMEDIATE`, reads `MAX(version)` for the week, inserts
`version + 1`, commits. `UNIQUE (week, version)` is the belt to that braces: two writers cannot
produce two version 3s.

## 3. The snapshot contract

`snapshot_json` is written **by the server, at save time, from the database**. The tool has no
`snapshot` argument at all — not an ignored one — because a key that does nothing is how a record
ends up not matching what someone believes they wrote. The model supplies the words; the tracker
supplies every number those words sit next to.

Captured, all of it for the Monday–Sunday of `week`:

| Key | From |
|---|---|
| `schema` | `1`. The shape version, so a later reader knows what it is looking at. |
| `week`, `monday`, `friday` | `judgeWeek()['week']`, `['monday']`, Monday + 4. |
| `captured_at` | `gmdate('Y-m-d H:i:s')` — UTC, like every other stamp. |
| `timetable_version_id` | `timetableVersionOn($monday)['id']`, or null when no timetable was in force. |
| `counts` | `judgeWeek()['counts']` verbatim: `done, short, missed, excused, day_off, now, pending, upcoming, optional, declared, extra, judged`. |
| `counts_by_tracking` | The same counts partitioned into `evidence`, `self_report` and `review`, each carrying every status including `optional` and `declared` — see the partition rule in §6. |
| `hours_by_subject` | `judgeWeek()['hours_by_subject']` verbatim (hours, 2dp, done blocks plus extras). |
| `planned_by_subject` | Planned hours per subject for **this** week, computed from the timetable version in force — see below. |
| `blocks[]` | Every judged block of the week (`status !== 'n/a'`), flattened from `judgeWeek()['days']`: `date, weekday, block_key, start, end, label, kind, tracking, subjects, subject, status, short, minutes, length, reason, evidence[]`. This is the missed list, and it is what the drift line diffs against. |
| `extras[]` | `days[].extras`: `date, type, id, subject, label, at, minutes`. |
| `days_off[]` | `listDaysOff($monday, $sunday)` plus every row still `requested` on any date, each with `id, date_from, date_to, kind, reason, requested_by, status`. |
| `changes[]` | Topic changes with `date(changed_at)` inside the week, across every subject: `subject_slug, ref, topic_name, from_status, to_status, evidence, session_id, changed_at`. |
| `coverage{slug}` | `{pct_start, pct_end, topics}` — coverage at Monday 00:00 and Sunday 24:00, and the topic count the percentage is against. |
| `attempts[]` | Attempts whose papers were sat in the week (`COALESCE(sat_on, date)`): `subject_slug, attempt_id, name, kind, date, score, max, blanks, papers[]`. |
| `practice{slug}` | Runs played in the week: `runs, attempted, correct, correct_after_retry, first_time_pct, best_score`. Pooled, never a mean of percentages. |
| `timed` | `{this_week: [...], last: {...}|null}` — see below. |
| `queue_top{slug}` | One line per subject from the review queue: the top ageing secure, else the top loose end, else the top gap. |

Four of those need their derivation pinned, because they are not a single query:

**Planned hours are the timetable's, not the skills' split.** `planned_by_subject` is the sum of
the lengths of every judged Monday–Friday block in the version in force that resolves to exactly
one subject — the same attribution `judgeWeek()` uses for `hours_by_subject`, so a block that
counts for nobody when it is done counts for nobody when it is planned. Blocks with several
subjects (the retrieval warm-ups, the Tuesday timed rotation) attribute to no subject on either
side. Alternating blocks resolve by ISO week parity through `tt_subjects_for()`, so the planned
figure for an odd week differs from an even one and the page compares like with like.

Against `docs/timetable-seed.json` in an odd week that is maths 4.83 · lit 3.33 · lang 1.42 ·
cs 2.75 · spanish 1.0 — which is **not** the split the skills quote (Maths 5 · Lit 4.5 · Lang 3.5
· CS 3.5 · Spanish 1.75). Read against that split, English Language would be amber every week for
ever, through nothing anyone did. So the page reads hours against the timetable and says so, and
`meta('timetable_targets')` — which does exist, written by `tracker_set_timetable`'s optional
`targets` and read by `tracker_week_status` — is deliberately not used here. Reconciling the two
is open decision 4 in §10.

**Coverage over time is replayed, not stored.** There is no coverage history table and there does
not need to be one: `topic_changes` holds `from_status` and `to_status` for every move, and
`STATUS_POINTS` turns each into a delta. Points at an instant `t` = points now − the sum of
`points(to) − points(from)` over every change after `t`. The denominator is `count(topics) * 3`
as it stands today, so a subject seeded with more topics later reads as coverage *against today's
syllabus* all the way back; say so in the sparkline caption rather than pretending otherwise.

**Timed / handwritten.** `this_week` is every done block of kind `timed_handwritten`:
`date, label, evidence, minutes, blanks`. Minutes are the bound session's `duration_minutes` when
the evidence is a session; when it is an attempt paper there is no duration recorded, so the field
is the block length flagged `measured: false` — a page that prints a block length as "25 minutes
sustained" is inventing stamina data. Blanks come from the attempt's papers, else null. `last` is
the most recent such block before this Monday, found by walking back at most 8 weeks and stopping
at the first hit; null if there is none in that window.

**The review queue** is currently computed inline in `mcp_call_tool()`'s `tracker_review_queue`
case. Extract the selection into `Store::reviewQueue(string $slug, int $ageingWeeks = 8): array`
returning `['ageing' => [...], 'loose' => [...], 'gaps' => [...]]` and have both the tool and the
page call it, so the queue line on the page and the queue in chat cannot drift apart.

Rule, stated in the tool description as well as here: **the model never supplies the snapshot.**
If the note says three blocks were missed and the snapshot says two, the snapshot is right and
the note is a note.

## 4. The sections contract

`sections_json`, the written half. One shape, validated on the way in:

```json
{
  "held":    "Lit, Lang and CS on their timetabled hours; A17 to exam-ready on a spaced, worked re-test; zero blanks on everything marked.",
  "slipped": "Thursday's deep block missed — second miss in three weeks. No timed handwritten piece, so stamina has no new reading.",
  "next":    "Tuesday's timed piece is the Lit essay. Thursday's deep block is CS (W38 is even).",
  "carry_forward": {
    "maths":               "The Thursday deep block, and A4's second retest.",
    "english-literature":  "An Inspector Calls not yet opened; the tracker expects it Tuesday.",
    "english-language":    "Second unaided Level-5 piece to secure W1.",
    "computer-science":    "Trace tables — proposed for Tuesday's timed rotation.",
    "spanish":             "Wednesday's slot lost to the dentist; Thursday and Friday held."
  },
  "rotation_next": "Lit essay",
  "decisions": [
    { "kind": "day_off",  "ref": "12",            "decision": "approved",    "note": "Birthday; blocks moved to Saturday morning." },
    { "kind": "excusal",  "ref": "2026-09-09#20", "decision": "excused",     "note": "dentist" },
    { "kind": "excusal",  "ref": "2026-09-08#16", "decision": "not_excused", "note": "Dad declined to excuse it." },
    { "kind": "excusal",  "ref": "2026-09-10#22", "decision": "not_excused", "note": null }
  ]
}
```

Validation, all of it refusing the whole call rather than dropping a field — one invalid section
rejects the write, the way one invalid panel rejects a scoreboard:

- `held`, `slipped`, `next` — required, 10–600 characters each, no newlines. All three must be
  present and non-empty: a review with nothing under *slipped* is a review that has not looked.
  If a week genuinely slipped nowhere, say that in a sentence; the field is not optional.
- `carry_forward` — required, an object, 1–12 entries. **Every key must be a slug
  `getSubject()` resolves**; an unknown slug is refused by name, listing the known ones, like
  `mcp_resolve()` does. Each value is one line: 3–200 characters, no newlines. One line per
  subject is the rule the skill sets and the card layout assumes; a paragraph breaks the grid.
- `rotation_next` — required, 1–80 characters, no newlines. Free text rather than an enum,
  because the rotation is a convention (Lang Q5 → Lit essay → Maths section → CS program) and not
  a stored list; the routine reads what was last from the attempts and names the next.
- `decisions` — optional, 0–20 entries. Each is `{kind, ref, decision, note?}`.
  - `kind` ∈ `day_off | excusal`.
  - `ref` for `day_off` is the `days_off.id` as a string; it must resolve, or the write is
    refused naming the id. `ref` for `excusal` is `YYYY-MM-DD#block_key`; the date must fall in
    the week and `mcp_find_block()` must find that block on that weekday, or refused, with the
    same two-sided message `mcp_check_block()` gives.
  - `decision` ∈ `approved | declined | excused | not_excused | deferred`.
  - `note` optional, ≤ 200 characters.
  - A decision **records** what was decided; it never performs it. `tracker_save_weekly_review`
    writes no excusal and no day-off decision. Those go through `tracker_excuse_block` and
    `tracker_decide_day_off` in the review chat, on the parent's word, exactly as today. If a
    decision names an excusal as `excused` and no excusal row exists, the save still succeeds —
    and the page's drift line will show the disagreement, which is the point.
- Anything else at the top level is refused by key name. A misspelled key that does nothing is
  how a note ends up not saying what someone thought they wrote.
- `note` (the column, not a section) is an optional free line about the save itself, ≤ 300
  characters — "written by the Friday routine before the 14:15 review", say.

## 5. The tools

Three, defined in `mcp_tools()` in the existing style: `name`, `title`, a description leading with
`USE WHEN` and carrying a `DO NOT`, an `inputSchema`, and `$readOnly` / `$write` annotations. The
smoke test counts `USE WHEN` occurrences against tool count, so the count there goes 30 → 33.

### `tracker_week_report`

```php
[
    'name'  => 'tracker_week_report',
    'title' => 'Everything a week is judged on, in one call',
    'description' =>
        "One read for a whole week: every block with its status, the extras, the counts, the hours "
        . "against what the timetable planned, each subject's topic movement with the evidence behind it, anything sat, "
        . "the practice runs, the top of each review queue, pending days off, and any weekly review "
        . "already saved for that week.\n\n"
        . "USE WHEN: writing or preparing the Friday weekly review, or answering \"how did the week "
        . "go\" across all subjects. This replaces the dozen calls that used to open a review — "
        . "week_status, then history, attempts, practice and review_queue per subject — with one.\n\n"
        . "DO NOT use it for a single day (tracker_today is cheaper) or for one subject's progress "
        . "(tracker_get_state and tracker_review_queue answer that). Do not treat a missed block as "
        . "a verdict on her: it means nothing was logged, which is sometimes a logging failure.\n\n"
        . "Args: optional week (ISO, e.g. '2026-W37') or date (any day in it). Defaults to the "
        . 'current week. Read-only.',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'week' => ['type' => 'string', 'pattern' => '^\\d{4}-W\\d{2}$',
                'description' => "ISO week, e.g. '2026-W37'"],
            'date' => $isoDate,
        ],
        'required' => [],
    ],
    'annotations' => $readOnly,
]
```

**Returns** one text block, in the order the page shows it, so the model reading it and the parent
reading the page see the same week in the same order: the week and its dates; the counts line;
the register day by day using `mcp_block_line()` (unchanged, so one format for blocks
everywhere), study blocks first with movement and the review block under them; the missed blocks
named plainly, day, time, label and what was absent; extras; pending days off; hours against the
timetable's planned hours; timed/handwritten this week and the last one; per subject a movement
block (`ref name from→to — evidence`), anything sat with blanks, practice totals, coverage and its
delta, and the queue top; the rotation's last and next; and finally, if a review is already saved,
its stage, version, `written_at` and its sections, so a second run can see what the first wrote.
It ends with the same caution `tracker_week_status` ends with, about a missed block sometimes
being a logging failure.

**Refusals.** A malformed week → `week must look like '2026-W37'.` A week with no timetable in
force → the sessions, attempts, practice and movement are still reported, with one line saying no
timetable was in force so no block was judged. A week entirely in the future → the same report
with every block `upcoming` and a line saying so; it is not an error to look ahead.

### `tracker_save_weekly_review`

```php
[
    'name'  => 'tracker_save_weekly_review',
    'title' => 'Save the written half of a week',
    'description' =>
        "Stores the margin note for a week — what held, what slipped, one carry-forward per subject, "
        . "what next week starts with, and the decisions the parent made — as a new version against "
        . "that week. The tracker attaches its own snapshot of the counts, hours, blocks, movement, "
        . "attempts and practice as they stand at save time, so the note can never disagree with the "
        . "record about the week it describes.\n\n"
        . "USE WHEN: the Friday routine has read tracker_week_report and written the draft, or the "
        . "review with the parent is finished and the decisions are settled — then stage 'reviewed'.\n\n"
        . "DO NOT use it to change the record. Excusing a block is tracker_excuse_block, deciding a "
        . "day off is tracker_decide_day_off, ticking the review block is tracker_tick_block; this "
        . "tool only records what was decided. Do not restate counts inside the sections — they are "
        . "computed beside your words and will be right when yours have aged.\n\n"
        . 'Args: week, stage, written_by, sections { held, slipped, next, carry_forward{slug: line}, '
        . 'rotation_next, decisions[]? }, optional note.',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'week'       => ['type' => 'string', 'pattern' => '^\\d{4}-W\\d{2}$'],
            'stage'      => ['type' => 'string', 'enum' => ['draft', 'reviewed']],
            'written_by' => ['type' => 'string', 'enum' => ['routine', 'chat'],
                'description' => "'routine' from the Friday scheduled run, 'chat' when a person is in the conversation"],
            'note'       => ['type' => 'string', 'maxLength' => 300],
            'sections'   => [
                'type' => 'object',
                'properties' => [
                    'held'          => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                    'slipped'       => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                    'next'          => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                    'carry_forward' => ['type' => 'object',
                        'description' => 'One line per subject slug, 3-200 characters'],
                    'rotation_next' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
                    'decisions'     => [
                        'type' => 'array', 'maxItems' => 20,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'kind'     => ['type' => 'string', 'enum' => ['day_off', 'excusal']],
                                'ref'      => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40,
                                    'description' => "day_off: the id. excusal: 'YYYY-MM-DD#block_key'"],
                                'decision' => ['type' => 'string',
                                    'enum' => ['approved', 'declined', 'excused', 'not_excused', 'deferred']],
                                'note'     => ['type' => ['string', 'null'], 'maxLength' => 200],
                            ],
                            'required' => ['kind', 'ref', 'decision'],
                        ],
                    ],
                ],
                'required' => ['held', 'slipped', 'next', 'carry_forward', 'rotation_next'],
            ],
        ],
        'required' => ['week', 'stage', 'written_by', 'sections'],
    ],
    'annotations' => $write,
]
```

**Returns** the version it wrote, its stage, the local time it was written, the counts the
snapshot captured — partitioned as §6 requires — and the page URL: `Saved version 2 (reviewed) for
2026-W37, written Fri 11 Sep 14:52. Snapshot: study blocks 18 of 21 done (1 short), 2 missed,
1 excused, 1 extra; movement ticked 5 of 5; review block ticked. Read it at /week/2026-W37.`

**Refusals**, each naming what to do instead:

- Every section rule in §4, refused with the offending key or slug named.
- A week that has not started (`monday > today`) — there is nothing to review yet.
- Stage `draft` for a week that already has a `reviewed` version: refused, telling the caller to
  save `reviewed` instead. A routine that fires late must not replace a reviewed stamp with a
  draft one.
- An identical re-save — same stage, same `written_by`, and `sections_json` byte-identical to the
  latest version — is a silent no-op returning that version, not a new row. A model-driven call
  can fire twice, and the ledger must not grow a phantom version because of a retry. The same
  rule `client_run_id` serves for practice.
- `written_by` is the one field the server cannot check, because both the routine and the chat
  arrive over the same connector as the same client. It is required rather than defaulted so the
  claim is deliberate, and the page prints it as a claim ("written by the Friday routine"), not as
  proof.

### `tracker_get_weekly_review`

```php
[
    'name'  => 'tracker_get_weekly_review',
    'title' => 'Read a saved weekly review',
    'description' =>
        "The margin note stored against a week: its sections, its stage, who wrote it and when, the "
        . "snapshot of the record it was written against, and how that snapshot now differs from the "
        . "live record.\n\n"
        . "USE WHEN: continuing a review the routine drafted, checking what was said about an earlier "
        . "week, or before saving a new version — read what is there rather than writing over the "
        . "top of it.\n\n"
        . "DO NOT use it for the week's figures; those are computed live by tracker_week_report and "
        . "the snapshot here is deliberately frozen at the moment it was written.\n\n"
        . 'Args: week. Optional version (defaults to the latest). Read-only.',
    'inputSchema' => [
        'type' => 'object',
        'properties' => [
            'week'    => ['type' => 'string', 'pattern' => '^\\d{4}-W\\d{2}$'],
            'version' => ['type' => 'integer', 'minimum' => 1],
        ],
        'required' => ['week'],
    ],
    'annotations' => $readOnly,
]
```

**Returns** the version header (`version 2 of 2 · reviewed · written by the Friday routine ·
Fri 11 Sep 14:52`), the list of versions available for that week, the sections in the page's
order, the decisions, the snapshot's counts and hours, and the drift sentence from §6 when the
live record has moved since.

**Refusals.** No review for that week → plain text saying so and naming
`tracker_save_weekly_review`, not an error. A version that does not exist → the versions that do.

## 6. The pages

Both are dashboard pages: `dash_shell()`, `DASH_CSS`, the exercise-book paper, behind
`$dashboardGuard()` like every other dashboard route. Routes in `php/index.php`, next to the
existing `/s/...` block:

```php
if ($path === '/weeks') { $dashboardGuard(); send_html(render_weeks($store)); }

if (preg_match('#^/week/(\d{4}-W\d{2})$#', $path, $m)) {
    $dashboardGuard();
    $monday = tt_week_monday($m[1]);
    if ($monday === null) { send_html(render_weeks($store), 404); }
    send_html(render_week($store, $m[1]));
}
```

A malformed or impossible week falls back to `/weeks` with a 404, the way a missing attempt falls
back to the subject page rather than a dead end.

### `/week/{iso}` — sections in order, and where each comes from

| Section | Built from |
|---|---|
| Header: kicker `WEEK 37 · MON 7 – FRI 11 SEP 2026`, h1 "The week", prev/next links, stamp | `tt_week_monday`, `tt_pretty`; prev/next by ±7 days through `tt_iso_week`. The stamp is the latest `weeklyReview($week)`: reviewed → solid ink-stone rotated badge with the local `written_at`; draft → the same badge, dashed; none → no stamp. |
| Four headline cards: study blocks with the segmented bar, hours, timed/handwritten, topics moved | `judgeWeek($monday)['counts']` partitioned as below, `['hours_by_subject']` against `planned_by_subject`, the timed derivation in §3, and the week's `changes[]` counted up (↑ promotions, ↓ demotions, "evidence only on N more" = changes where `from_status === to_status`). |
| The register: five day columns, each judged block a row | `judgeWeek($monday)['days']` — nothing else. Breaks (`status === 'n/a'`) are not rendered. Extras are appended to their day as dotted rows. |
| Missed, named plainly | The `missed` and `short` rows of the same `days` array. Day, time, label, and what was absent, derived from the block's `tracking` and `kind`: no session / no attempt / no practice. Only study blocks can be here: a movement block that was not ticked is `optional`, and a `declared` block is listed under **Marked by hand** below it — day, time, label, "marked done by Dad, no work was logged" and his note. |
| Decisions for Dad | `listDaysOff(status: 'requested')` and the missed rows; in the reviewed state, `sections.decisions[]` instead, matched by `ref`. Caption: decided in the review chat, never here — there is no button. |
| Hours against the timetable | `hours_by_subject` against `planned_by_subject` (§3). Under the planned figure is amber, at or over is emerald. Hours, never a percentage of a percentage. |
| By subject, five cards | Per slug: `progressFor()` for coverage; the replay in §3 for the 8-week sparkline and the delta; `changesBetween($monday, $sunday)` filtered to the slug for the movement chips; attempts sat in the week for "Sat this week"; `listPracticeRuns($slug, ['since' => $monday, 'until' => $sunday])` for the practice line; `Store::reviewQueue($slug)` for the queue top; and `sections.carry_forward[$slug]` for the margin line. |
| In the margin — the week's review | The latest `weeklyReview($week)`: *Held*, *Slipped*, *Next week*, the drift line, and version chips linking `?v=1`, `?v=2`. Nothing here is computed except the drift line. |
| Footer | Links to `/weeks` and each `/s/{slug}`; the generated-at line every dashboard page carries. |

Three new store methods carry this: `changesBetween(string $from, string $to, ?string $slug =
null)` for the movement section — the same query `history()` uses, with `date(c.changed_at)`
bounded at both ends and the topic name joined; `plannedBySubject(string $monday)` for the hours
denominator (§3); and `weeksWithActivity(int $limit)` for `/weeks`.

**The partition rule.** The headline count is **study blocks only** — the judged blocks whose
`tracking` is `evidence`. Movement is counted and shown separately, and the Friday review block
separately again. "23 of 27" puts a walk and a maths block in the same fraction, and the fraction
then means nothing to the person reading it. So: a headline of `18 of 21 study blocks`, a line
under it reading `movement ticked 5 of 5 · review block ticked` (or `pending`), and the same partition
everywhere a count appears. The movement clause is only printed when a movement block is actually
self-reported: since timetable version 6 the Move blocks are untracked like lunch, so the line reads
`review block ticked` alone rather than `movement ticked 0 of 0`. The same partition holds — the stat card, the save tool's return, the digest subject line, the
`/weeks` row and the snapshot's `counts_by_tracking`. Against `docs/timetable-seed.json` a full
Monday–Friday week is 21 evidence blocks, 5 movement blocks and 1 review block; breaks
(`status === 'n/a'`) are not judged and are not counted anywhere.

`optional` and `declared` are folded into nothing. A self-reported block that was not ticked is
`optional`: it is neither done nor missed, it never appears in a missed list, and not ticking one
costs her nothing. A study block the parent ticked without any work logged is `declared`: it is
counted, named as `N marked by hand` beside the fraction and never inside it, and it is not a
miss. `done` is what the record can prove; `done + declared` is what is said to have happened.

The counts themselves are `judgeWeek()`'s and are never recomputed in the renderer — the page
partitions them, it does not re-derive them. Hours likewise come from `hours_by_subject` as-is;
that figure already includes extras and already counts a short block's actual minutes, and a
second opinion computed in the page would be a second answer.

**The worked week, 2026-W37**, used by the deck, the fixtures and every example below:

| | Draft, Fri 11 Sep 14:05, routine | Reviewed, Fri 14:52, chat |
|---|---|---|
| Study blocks | 18 of 21 done, 1 short (Fri 09:45 Maths consolidate, 28 of 75 min) | 18 done, 1 short |
| Missed | 3 — Tue 13:00 Timed handwritten (no attempt logged); Wed 09:45 Spanish vocab + listening (no practice logged); Thu 09:25 Deep block Maths (no session logged) | 2 — the Tuesday and Thursday blocks |
| Excused | 0 | 1 — Wed 09:45 Spanish, "dentist" |
| Extra | 1 — Wed 16:10 Spanish Shooting Gallery, 12 min | 1 |
| Movement | 5 of 5 ticked | 5 of 5 |
| Review block | pending | ticked |
| Hours vs timetable | maths 3.0 / 4.8 · lit 3.3 / 3.3 · lang 1.4 / 1.4 · cs 2.75 / 2.75 · spanish 0.95 / 1.0 — 11.4 of 13.3 across the single-subject blocks | unchanged |
| Waiting on Dad | day off Mon 14 Sep, Paige, "friend's birthday" | approved, blocks moved to Saturday morning |

The hours total counts only the blocks that resolve to one subject: the retrieval warm-ups and the
Tuesday timed rotation belong to several, so they are in neither the done total nor the planned
one — which is why 11.4 of 13.3 is less than the week's wall-clock time.

### The register is shared code

The dashboard skill has the index page showing the current week's timetable — today marked, each
block done / short / missed / excused / extra, hours by subject. That is the same component. Put
it in one function used by both:

```php
// php/lib/week.php
function render_register(array $days, array $opts = []): string
```

`$days` is `judgeWeek()['days']` (or `[judgeDay($date)]` for a single day). `$opts` carries
`mark_today` (the index marks it; a past week has no today), `columns` (five columns on
`/week/{iso}`, the current-day-first list on `/`), and `link_evidence` (both link a done block to
`/s/{slug}/session/{id}` or `/s/{slug}/a/{id}`). The glyphs, the hand-drawn cross, the strike-
through and the dotted extra row are defined once here, so the index and the week page cannot
disagree about what a short block looks like. `render_index()` and `render_week()` both call it;
neither owns it.

### The drift line

One sentence, always, plus a second only when the record has moved:

> Written from the record at Fri 14:05 (3 missed · 0 excused). Since then: Wed 09:45 Spanish
> excused — dentist.

That is the draft version of 2026-W37 read after the review: it was written before the excusal, so
it drifted by exactly one block. The reviewed version, saved at 14:52 after the excusal, has no
second sentence.

Rule:

1. Build a map `date#block_key → status` from `snapshot.blocks`, and the same from a live
   `judgeWeek()` of that week.
2. Differences, in this fixed order: excusals gained, excusals removed, missed → done, missed →
   declared (`<Day> <time> <label> marked done by Dad[ — note]`), done → missed, day-off
   approvals, extras added, blocks added or removed by a timetable re-cut.
3. Render each as `<Day> <start> <label> <verb>[ — <reason>]`. At most three, then
   `; and N more changes.`
4. No differences → no second sentence. Silence means the note and the record still agree; a
   line saying "nothing has changed" would be noise on every clean week.
5. The same comparison drives a small ● beside the stamp in the `/weeks` Review column, so drift
   is visible from the term view without opening the week.

### `/weeks` — the term ledger

One table, one row per ISO week, newest first, from the earliest week with any activity (a
session, an attempt, a practice run or a timetable) to the current one. Columns: **Week** (link),
**Study blocks** (a mini segmented bar + `18/21`), **Missed**, **Movement** (`5/5`), **Hours**
(`11.4 / 13.3`), **Timed** (`25 min · 0 blanks` or `—`), **Moved** (`↑5 ↓0`), **Coverage** (five
small numbers, one per subject, with their deltas), **Review** (stamp mini: reviewed / draft /
none, plus the drift dot). The review block is not a column: it is the tick on the stamp.
Weeks before the first timetable version read `— no timetable yet` in stone across the block
columns, while their sessions, attempts and movement still show: the record predates the board.
The current week's row is highlighted. Caption under the table:

> The ledger is computed on every request. The Review column is the only thing a person or the
> routine ever writes.

Cost: one `judgeWeek()` per row. Cap the table at 26 weeks with a `?from=` for older terms, and
build the coverage numbers from one `changesBetween()` over the whole span replayed backwards
rather than one query per week per subject.

### Names — what exists, what this adds

Everything in the left column is on the timetable branch (or on `main`) and is called, not
written. Everything in the right column is new work in this feature.

| Already there | New here |
|---|---|
| `judgeWeek()`, `judgeDay()`, `judgeDates()`, `timetableVersionOn()`, `timetableBlocks()`, `listDaysOff()`, `setExcusal()`, `setTick()` — `php/lib/store.php`, branch | `createWeeklyReviewTable()`, `addWeeklyReview()`, `weeklyReview()`, `weeklyReviewVersions()`, `latestWeeklyReviews()` |
| `tt_zone()`, `tt_now()`, `tt_today()`, `tt_monday()`, `tt_week_monday()`, `tt_iso_week()`, `tt_pretty()`, `tt_add_days()`, `tt_parity()`, `tt_subjects_for()`, `tt_local()` — branch | `plannedBySubject(string $monday)`, `changesBetween(string $from, string $to, ?string $slug = null)`, `weeksWithActivity(int $limit)` |
| `progressFor()`, `weeksSince()`, `gradeFor()`, `listPracticeRuns()`, `listAttempts()`, `listPapers()`, `history()`, `changesForSession()` — `main` | `Store::reviewQueue()` — not new logic, but a new name: the selection is extracted from the `tracker_review_queue` case in `mcp_call_tool()` so the page and the tool share it |
| `mcp_block_line()`, `mcp_find_block()`, `mcp_check_block()`, `mcp_resolve()`, `mcp_str()`, `mcp_num()`, `mcp_date()`, `mcp_text()` — `php/lib/mcp.php`, branch | `render_week()`, `render_weeks()`, `render_register()`, in a new `php/lib/week.php` required by `php/index.php` |
| `dash_shell()`, `DASH_CSS`, `render_index()`, `detail_head()`, `h()`, `send_html()`, `$dashboardGuard` — `main` | The margin, stamp, segmented-bar and hand-drawn-cross rules added to `DASH_CSS` |
| `check`, `contains`, `lacks` in `deploy/smoke-test.sh` — `lacks` arrives with the timetable branch, not on `main` | The `== weekly report ==` section, and the register golden in `deploy/practice-test.php` |

`meta('timetable_targets')` also exists on the branch — written by `tracker_set_timetable`'s
optional `targets` and read by `tracker_week_status` — and these pages deliberately do not read
it; see §3 and open decision 4.

## 7. The email digest

Plain text, no HTML, no charts — the page is where the visuals live and the record has one home.
Subject line:

```
Week 37 — 18 of 21 study blocks · 3 missed · 1 decision waiting
```

(`{done} of {evidence blocks} study blocks · {missed} missed` and, when there are pending day-off
requests or undecided misses, `· {n} decision(s) waiting`. Movement and the review block are not
in the subject line; they are one line in the body.)

Body, mirroring the `parent-weekly-review` output shape exactly, so the Friday email and the
Friday chat read the same:

```
Week 2026-W37 (7–11 Sep) · 18 of 21 study blocks done · 3 missed · 0 excused · 1 extra
Movement 5 of 5 · review block pending

MISSED
- Tue 13:00 timed handwritten — no attempt logged
- Wed 09:45 Spanish vocab + listening — no practice logged
- Thu 09:25 maths deep block — no session logged

DAYS OFF — pending your decision
- Mon 14 Sep, requested by Paige: "friend's birthday" → approve / decline?

HOURS vs timetable
maths 3.0/4.8 · lit 3.3/3.3 · lang 1.4/1.4 · cs 2.75/2.75 · spanish 0.95/1.0

TIMED / HANDWRITTEN
- none this week (last: Tue 1 Sep, Lang Q5, 25 min sustained, 0 blanks)

CARRY-FORWARD (one per subject)
- maths: the Thursday deep block — second miss in three weeks
- lit: An Inspector Calls not yet opened; tracker expects it Tuesday
- lang: second unaided Level-5 piece to secure W1
- cs: trace tables — proposed for Tuesday's timed rotation
- spanish: Wednesday lost to the dentist; Thursday and Friday held

Next Tuesday: Lit essay (rotation).

Open the week → https://education.rmmann.co.uk/week/2026-W37
```

What the digest deliberately leaves out, because the page has it and the email would go stale:
the register block by block, the movement lines with their evidence, coverage, the sparklines, and
the note's *Held* / *Slipped* paragraphs. The digest is the headline, the misses, what is waiting
on Dad, and the link.

## 8. The routine

The scheduled task today is **Paige's Maths Report**: `0 17 * * 5`, prompt *"Using the
gcse-tracker-dashboard skill, generate an updated version of the Meths Dashboard and email it to
wraith.shadow@gmail.com"*, connectors **Google Drive** and **Claude Code Remote**. It is replaced,
not added to.

**Connectors.** It needs the **Education Tracker** connector (`tracker_*`) — it does not have it
today, which is why it cannot read the record — and a connector that can actually send mail
(**Gmail**). Google Drive sends nothing; if Gmail is not attached, the routine should write the
review and say in its final message that the digest could not be sent, rather than skipping the
save. Claude Code Remote is not needed and can come off.

**Cron.** Friday 14:05 Europe/London, ten minutes before the 14:15 review block, so the draft and
the digest are waiting when Dad sits down. Cron is UTC:

| Period | Cron | Local |
|---|---|---|
| BST (last Sun in March → last Sun in October) | `5 13 * * 5` | 14:05 |
| GMT (last Sun in October → last Sun in March) | `5 14 * * 5` | 14:05 |

The DST caveat matters here more than usual: leaving `5 13 * * 5` through the winter fires at
13:05 local, an hour early but harmless; leaving `5 14 * * 5` through the summer fires at 15:05,
*after* the review block, which defeats the point. Flip it twice a year — 25 Oct 2026 and 28 Mar
2027 — or accept the early-by-an-hour direction and pin `5 13 * * 5` year-round.

**Steps**, and nothing else: `tracker_week_report()` → write the margin note →
`tracker_save_weekly_review(week, stage: "draft", written_by: "routine", sections)` → email the
digest with the link. It writes exactly one row and sends one email.

**The handoff.** At 14:15 the review with Dad happens in chat under the `parent-weekly-review`
skill. That conversation is where excusals, day-off decisions and the review tick are written —
`tracker_excuse_block`, `tracker_decide_day_off`, `tracker_tick_block` — and it ends with a second
`tracker_save_weekly_review(stage: "reviewed", written_by: "chat")` carrying the same sections
updated with what was decided. The skill gains two lines: read the draft with
`tracker_get_weekly_review(week)` at the start, and save the reviewed version last, after the tick.

The replacement prompt is in [weekly-report-routine.md](weekly-report-routine.md), ready to paste
whole.

## 9. Tests

Smoke checks to add to `deploy/smoke-test.sh`, in a `== weekly report ==` section after the
timetable one, reusing `check` / `contains` / `lacks` (`lacks` is added by the timetable branch)
and the seeded timetable already loaded there. The clock is frozen with `TRACKER_NOW` — it is read per call by `tt_now()`, but the local
run boots one `php -S` process, so export it before the server starts (or run the page checks
against a second server started with it set) rather than expecting a mid-run change to take.

- `/week/2024-W37` renders the same done/missed/excused counts `tracker_week_status` reports.
- every missed block on the page is named with its day, time and label.
- a short block shows its minutes and is not counted as plainly done.
- the headline counts study blocks only, with movement and the review block reported separately.
- hours are read against the timetable's planned hours for that week, not the skills' split.
- an excused block shows the parent's reason and is in neither done nor missed.
- an extra appears on its day as an extra and never offsets a miss.
- the register on `/` and the register on `/week/{iso}` render the same block markup.
- `tracker_save_weekly_review` writes version 1 and reports it.
- a saved draft appears on the page with its stamp and the routine's name.
- saving again writes version 2 and the page shows the later one with both version chips.
- an identical re-save returns the existing version and adds no row.
- a `draft` after a `reviewed` is refused, naming `reviewed` as what to send instead.
- a `carry_forward` key that is not a subject slug is refused, listing the known slugs.
- an empty `held` is refused.
- a `decisions` entry whose excusal ref names no block on that date is refused, naming both sides.
- the snapshot is the server's: counts in the saved review match `judgeWeek`, whatever the note says.
- excusing a block after the save makes the drift line appear on the page.
- and the drift line quotes both the snapshot's counts and the change since.
- no drift line is rendered when the snapshot still matches the live record.
- `tracker_week_report` returns blocks, movement, attempts, practice and queue tops in one call.
- `tracker_get_weekly_review` returns the latest version, and `version: 1` returns the first.
- `/weeks` lists the week with its stamp and links to it.
- `/weeks` marks weeks before the first timetable version "no timetable yet" but still shows their sessions.
- `/week/2026-W99` falls back to `/weeks` with a 404.
- all 33 tool descriptions carry a `USE WHEN` trigger (the count check, 30 → 33).
- re-opening a populated database is idempotent at `schema_version` 6.
- the migration applies to an empty database (`5`).

**The golden snapshot of the register.** `deploy/practice-test.php` already owns the golden
harness — throwaway database, `--update`, `diff -u` on failure — so the register goes there rather
than into a second mechanism. Seed the timetable from `docs/timetable-seed.json`, log a fixed set
of sessions, one attempt, one practice run, one excusal and one day off across the week of
**2026-W37**, freeze the clock with `putenv('TRACKER_NOW=2026-09-11 15:30')` before judging so
Friday's blocks are past and nothing depends on the wall clock, then render
`render_register(judgeWeek('2026-09-07')['days'], ['columns' => 5])` into
`tests/golden/week-register.html`. Add the named assertions beside it, the way the Spanish board
does, so a diff says what broke: the two missed blocks by label, the short block's minutes, the
excusal's reason, the extra's dotted row, and the absence of any block whose tracking is `none`.
Any change to the register re-runs `php deploy/practice-test.php`, and `--update` is a deliberate
act whose diff is read before it is committed.

## 10. Open decisions for the parent

1. **The concept.** The ledger and the margin — computed figures, one written note per week,
   stamped and versioned — against any of the alternatives in the deck (a stored HTML report, the
   narrative interleaved through every section, the full report in the email).
2. **When the routine runs.** Friday 14:05, draft before the review, so Dad reads a prepared week
   and the reviewed version records what was decided; or Friday 17:00, one version written after
   the review, no draft and no drift between the two. 14:05 is drawn.
3. **How much margin.** Per-subject carry-forward lines *and* an overall *Held / Slipped / Next
   week* note, as drawn; or the overall note only, with the carry-forwards folded into it. The
   per-subject lines are what make the subject cards worth reading; the cost is five more lines to
   write every Friday.
4. **The split and the timetable disagree.** The skills quote Maths 5 · Lit 4.5 · Lang 3.5 ·
   CS 3.5 · Spanish 1.75 — 18.25 hours. The timetable in force plans 4.83 · 3.33 · 1.42 · 2.75 ·
   1.0 in an odd week, 13.33 hours, because multi-subject retrieval and the Tuesday timed
   rotation belong to no single subject and the week is shorter than the split assumes. Read
   against the split, English Language is amber every week for ever, through nothing anyone did.
   Until they are reconciled — term-planner's job, moving either the timetable or the split, not
   the page's — `/week/{iso}` reads hours against the timetable, because that is the figure the
   record can verify. The decision is which of the two moves.
