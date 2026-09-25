# The Education Tracker service — reference

The tracker is a self-hosted service, not a document and not an artifact. It holds
the syllabus, topic state, teaching materials, sessions and marked papers for any
number of GCSE subjects, and it is the source of truth for all of them.

- **Site:** <https://education.rmmann.co.uk>
- **MCP endpoint:** `https://education.rmmann.co.uk/mcp`, connector name "Education Tracker"
- **Repository:** `wraithrmm/education-study-tracker` (PHP; merges to `main` deploy automatically)
- **Subject slugs:** one per subject; get the authoritative list from `tracker_list_subjects`.
  Never guess a slug from the subject name.

## Pages

Server-rendered from the database on every request — no regenerate step, no stale copy.

| Page | Shows |
|---|---|
| `/` | Every subject with a coverage percentage |
| `/s/{subject}` | The dashboard: countdown, coverage, per-strand bars, topic chips, loose ends, resources, attempts, sessions by week |
| `/s/{subject}/a/{id}` | One sitting: every paper, every question with its marks, the answer given, the marker's note, then marks lost per topic |
| `/s/{subject}/session/{id}` | One session: what happened, what was planned next, every status it changed with the evidence recorded at the time. Behind the parent's login: its lesson review (every version, with each version's note) and the signals it touched; public view shows only a "reviewed" tick |
| `/s/{subject}/reviews` | Parent only. Lesson reviews newest first: readiness, one-sentence, stage |
| `/signals` | Parent only. Every signal across subjects by strength then kind, with its evidence trail, pending test (who set it, for which week, its design) and an answered tick |
| `/week/{iso-week}` (parent) | Beneath the weekly review: the weekly synthesis, `?sv=` to switch versions, planner pinned first, Part 20 as decided / happened |
| `/learner` | Parent only. The learner model by status, each statement with its signals and week-by-week history |
| `/s/{subject}` (parent) | Adds the current week plan card for the subject, with a tick once the review queue has printed it |
| `/s/{subject}/t/{ref}` | One topic: every status it has held and why, the session behind each change, every marked question it has been examined by, and its materials |
| `/` (timetable section) | This week's timetable, today marked, every block done / short / missed / excused / extra, plus extras and hours by subject |
| `/week/{iso-week}` | Any past week judged against the timetable in force then |

The dashboard is public to anyone with the link; the API and the MCP endpoint are
token- and OAuth-guarded.

## Statuses

Stored as five values, in order. The emoji shorthand used in chat maps one to one.

| Emoji | Stored | Points toward "spec conquered" |
|---|---|---|
| 🔴 | `gap` | 0 |
| 🟠 | `notstarted` | 0 |
| 🟡 | `developing` | 1 |
| 🟢 | `secure` | 2 |
| 🔵 | `examready` | 3 |

## Tools

### Reading

| Tool | Arguments | Use for |
|---|---|---|
| `tracker_list_subjects` | — | Every subject with coverage |
| `tracker_get_state` | `subject`, `status[]?`, `strand?` | The topic table, filterable |
| `tracker_review_queue` | `subject`, `ageing_weeks?` | **Opens a session.** Priority gaps, ageing secures, loose ends, with materials attached |
| `tracker_list_resources` | `subject`, `ref?` | Stored materials for a topic (plus subject-wide ones) |
| `tracker_list_attempts` | `subject`, `limit?` | Every sitting with its papers and the grade for the sitting as a whole |
| `tracker_get_attempt` | `subject`, `attempt_id` | One sitting question by question, and marks lost per topic |
| `tracker_history` | `subject`, `weeks?`, `ref?` | The audit trail by ISO week; pass `ref` to follow one topic |
| `tracker_export_markdown` | `subject` | The whole state as a markdown document |
| `tracker_list_practice` | `subject`, `source?`, `since?`, `ref?`, `limit?` | Practice runs, newest first |
| `tracker_practice_stats` | `subject` | The figures behind the practice board |
| `tracker_today` | `date?` | **Opens every session after the review queue.** Today's timetable blocks with status, current/next block, missed so far |
| `tracker_week_status` | `week?`, `date?` | Every block of a week with status, evidence ids, extras, hours by subject |
| `tracker_get_timetable` | `valid_on?` | The stored blocks in force on a date |
| `tracker_days_off` | `from?`, `to?`, `status?` | Day-off records: requested / approved / declined |
| `tracker_get_lesson_review` | `subject`, `session_id`, `version?`, `planner_only?` | One session's review rendered in the standard layout, its snapshot, and the drift since |
| `tracker_list_lesson_reviews` | `subject`, `since?`, `limit?`, `stage?`, `missing?` | Reviewed sessions one line each; `missing: true` lists sessions owed a review |
| `tracker_signals` | `subject?`, `kind?`, `status?`, `min_strength?` | The signal spine: how she learns and what lands, with strength, trail and pending test |
| `tracker_review_audit_queue` | `subject` | **Opens the audit.** Sessions owed a review, drafts, consistency flags (incl. a synthesis- or parent-set test unanswered in its week), the last audit note |
| `tracker_week_synthesis_inputs` | `week?` | **Opens the weekly synthesis.** The week's reviews in full, signals and movement, tests due and answered, errors by type, retrieval rows and at-risk topics, attempts, topic movement, the learner model, the previous synthesis's decisions, next week's timetable, the principle headings |
| `tracker_get_week_synthesis` | `week`, `version?`, `planner_only?` | One week's synthesis in the twenty-part layout, its snapshot, and the drift (tests answered, plans read, model moved) |
| `tracker_list_week_syntheses` | `limit?` | One line per synthesised week: versions, stage, most-important sentence, tests answered |
| `tracker_learner_model` | `status?` | The evolving learner model: statements with status, the signals each rests on, week history. Never in a student chat |

### Writing

| Tool | Arguments | Notes |
|---|---|---|
| `tracker_update_topic` | `subject`, `ref`, `evidence`, `status?`, `watch?`, `last_touched?` | Evidence mandatory, ≥10 chars. Use for corrections outside a session |
| `tracker_log_session` | `subject`, `summary`, `date?`, `next_steps?`, `updates[]`, `review?` | **The normal way to close a session.** Stamps every change with the session; `review` writes the lesson review in the same transaction |
| `tracker_amend_session` | `subject`, `session_id`, `date?`, `summary?`, `next_steps?`, `void_reason?` | Correct or void a logged session; the row and reason are kept |
| `tracker_log_attempt` | `subject`, `name`, `papers[]`, `kind?`, `tier?`, `date?`, `note?` | One sitting, all its papers |
| `tracker_add_resource` | `subject`, `resources[]` | `{ref?, title, url?, kind?, note?}`. Re-adding a title updates it |
| `tracker_remove_resource` | `subject`, `title`, `ref?` | Delete one stored resource |
| `tracker_create_subject` | `slug`, `name`, `strands`, `topics`, `spec_code?`, `tier?`, `exam_date?`, `notes?`, `boundary_max?`, `boundaries?` | Add or extend a subject; never resets earned statuses. Owned by `gcse-tracker-dashboard` |
| `tracker_log_practice` | `subject`, `runs[]` | Practice runs (games, drills, retrieval starters), one call per session. Never changes a status |
| `tracker_void_practice` | `subject`, `run_id`, `void_reason?` | Void a run; kept and marked |
| `tracker_set_timetable` | `blocks[]`, `valid_from?`, `note?` | Replace the weekly timetable (versioned). Parent-only by convention |
| `tracker_request_day_off` | `date_from`, `date_to`, `reason`, `requested_by`, `kind?` | Anyone books; student requests wait for the parent |
| `tracker_decide_day_off` | `id`, `decision`, `note?` | Parent approves / declines / un-approves |
| `tracker_excuse_block` | `date`, `block_key`, `reason` | Parent excuses one block on one date; `null` un-excuses |
| `tracker_tick_block` | `date`, `block_key`, `by`, `note?` | Self-report a `self_report` block (movement, review) |
| `tracker_save_lesson_review` | `subject`, `session_id`, `stage`, `written_by`, `sections`, `note?` | Re-version a review, or write one for a session logged without it. `note` required from version 2 |
| `tracker_update_signal` | `id`, `status?`, `evidence?`, `next_test?`, `test_design?`, `test_week?`, `session_id?`, `direction?`, `promote?` | Resolve or refute a signal, set its test (from chat the test becomes the parent's), add an evidence row, or `promote: true` a per-subject key to a cross-subject twin. Strength is never an argument |
| `tracker_save_week_synthesis` | `week`, `stage`, `written_by`, `sections`, `note?` | Save the synthesis as a new version and, in the same transaction, write its decisions back: Part 14 → week plans (`this_week`), Part 10 → test designs, Part 12 → learner model, Part 17 → watch signals, Part 3 → cross-subject signals. `note` required from version 2 |
| `tracker_audit_stamp` | `subject`, `note` | Close an audit; empties the audit queue until new sessions arrive |

`tracker_log_session`, each paper in `tracker_log_attempt` and each run in `tracker_log_practice`
take an optional `block_key` linking the record to a timetable block; `tracker_log_session` also
takes optional `duration_minutes`. Without `block_key` the service infers the block from subject and
date. Full contract: `timetable.md`.

### `updates[]` on a session

```json
{ "ref": "A17", "status": "secure", "evidence": "exit ticket 4/4 unaided", "watch": "optional loose-end note" }
```

`status` is optional — omit it to record evidence against a topic without moving it.
`retrieval_outcome` (`correct` / `retry` / `incorrect`) records how a retrieval item went and
drives the spacing scheduler; a review's retention section requires one on each ref it lists.

### `review` on a session

The lesson review, written in the same transaction as the session so a session can never land
half-reviewed. Its fields are the prompt's nineteen sections, validated against the enums in
`review.php` — `lesson-review/references/analysis-rules.md` §3 maps every one. Required keys:
`one_sentence`, `independent`, `supported`, `big_picture`, `planner`, `missing_evidence`.
Whether a session needs one is `review_required`, derived by the server (see `timetable.md`);
a required review left out does not refuse the call but is named in the response and appears in
the audit queue until saved with `tracker_save_lesson_review`. The review's `planner` is copied
to `next_steps` when that is empty, and printed as `last_review` at the top of the next
`tracker_review_queue`.

`ref` values must be refs the subject actually holds — take them from `tracker_get_state`.
An unrecognised ref is stored but flagged, and silently drops out of the per-topic breakdown.

### `papers[]` on an attempt

```json
{
  "code": "8300/1H", "score": 40, "max": 80,
  "blanks": 2, "note": "…", "sat_on": "2026-08-01",
  "questions": [
    { "number": "4a", "score": 3, "max": 4, "topic_ref": "A4",
      "question": "Factorise 6x²+8x", "answer": "2(3x²+4x)",
      "note": "factor not fully taken out" }
  ]
}
```

## Rules the service enforces

These are schema-level, not conventions — the call is refused if broken.

- **Evidence ≥ 10 characters** on every status change, so the trail can never be empty.
- **Question marks must reconcile with the paper total.** A breakdown that doesn't add up would make the per-topic analysis lie, so it is refused rather than stored. Useful as an arithmetic check on marking.
- **A score above its maximum** is refused, for papers and for individual questions.
- **Checks are never grade-converted.** `kind: "check"` reports a percentage only, so a good result on seven topics cannot quietly become a projected grade.
- **Unknown topic refs** on resources or questions are stored but flagged — they won't appear in the per-topic breakdown, so fix them.
- **A signal's strength may not exceed its evidence.** `one_off` needs one supporting session, `emerging` two distinct, `established` three and no uncontradicted contradiction newer than the last support. A review claiming more is refused with the counts.
- **A review never moves a topic.** `status_seen` and `proposed_status` are descriptions; only `updates[]` changes state.
- **A retention entry needs its outcome.** Each ref under `retrieved` / `prompted` / `not_retrieved` must carry the matching `retrieval_outcome` in `updates[]`.
- **A draft cannot overwrite an audited or parent review version.** Identical re-saves add no version. The same for a synthesis: a routine `draft` never overwrites a `parent` version.
- **A synthesis is refused, naming the part and field,** for: a Part 2 entry missing for a taught subject or present for an untaught one; a cross-subject claim cited from one subject; a recurring error with fewer than two error rows; a learner-voice quote no review recorded; a grade with no graded paper; a retention verdict with no retrieval record, or `retaining` on a wrong streak or a last retry/incorrect; a week plan missing for a subject taught next week; Part 20 present without a previous synthesis or absent with one; a learner-model status above what its signals support.
- **A parent-set test is never overwritten by a routine synthesis.** It is left in place and reported.

## Design rules that matter when writing

- **One sitting is one attempt.** The grade belongs to the attempt, computed across all its papers, because one paper of several doesn't carry a grade. Splitting a mock into separate attempts scales each paper against the whole-qualification boundary table on its own and reports a grade for an exam only partly sat.
- **Scores stored are raw.** Where a subject scales components before boundaries are applied (MFL does; maths does not), store the raw mark per paper and apply the scaling only when converting to a grade.
- **Statuses are never rewritten, only appended to.** A correction is another change whose evidence says so.
- **Sessions are voided, not deleted.** An audit trail that can lose entries isn't one.
- **Reviews are versioned, never edited.** Each version carries who wrote it (`session` / `audit` / `chat`), its stage and a server-frozen snapshot of the record at save time, so a review can never disagree with the lesson it describes.
- **Signals are rows, not prose.** A pattern's strength is derived from the distinct sessions citing it, so "one occurrence is not a pattern" is a schema rule.
- **Decisions are rows too.** The synthesis's week plans, test designs, learner-model changes and watch items are written where the next session and the next synthesis are fed them — the review queue's `this_week`, the signal table, the learner model — not left as prose in a document.
- **The learner model evolves by deltas.** A new row opens as `hypothesis` whatever its signals' strength; it strengthens next week to what its signals then allow; a row not mentioned in a synthesis is unchanged.
- **Per-question `topic_ref` is the point.** It's what turns a score into teaching information — `tracker_get_attempt` totals marks lost per topic and names what to reteach.

## Changing the service itself

The dashboard's markup and styling live in `php/lib/dashboard.php`; the tools in
`php/lib/mcp.php`; schema and queries in `php/lib/store.php`; the review vocabulary, strength
rule and rendering in `php/lib/review.php`; the synthesis vocabulary, learner-model rule and
rendering in `php/lib/synthesis.php`. Change them in the
repository, open a PR to `main`, and merging deploys automatically and verifies
against production. `bash deploy/smoke-test.sh` runs the whole suite locally
against a throwaway database.

Schema changes go in the numbered migration ladder in `store.php` (`meta.schema_version`),
each step in its own transaction. The production database holds the only copy of
the record — prove a migration against a copy in production's shape before shipping it.
