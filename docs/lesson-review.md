# Lesson reviews — service contract

The specification the service is built against (`php/lib/review.php`,
`php/lib/mcp_review.php`, `php/lib/dashboard_review.php`, the `lesson reviews`
section of `Store`), and the decisions taken where the specification left
room. The skill pack (`lesson-review`, the tutor skill amendments,
`parent-weekly-review`, `gcse-progress-tracker`) lives with the skills, not in
this repository; §7 below is the contract they are written to.

Written against schema step 12 and the `mcp.php` tool set as of this change.

## 0. Implementation notes — where the service decided

These are the calls made while building it. Each is a one-line change if the
decision turns out wrong.

- **Which kinds require a review** is a `review_required` column on
  `block_kind_rules` (§10, first open decision). Seeded true for `teach`,
  `practise`, `consolidate`, `coding` and `writing`; false for `retrieval`,
  `timed_handwritten` (a marked paper is an attempt, which has no session to
  review), `spanish`, movement and the review block. A session with no block
  requires one at `duration_minutes >= 30` (`REVIEW_EXTRA_MINUTES`).
- **An over-claimed strength does not refuse the log.** §3.2 says a claim the
  counts do not support is refused with the counts; §5.1 says the response
  gains one line per refused strength. Reconciled in favour of the session:
  the evidence row is kept, the strength is held where it was, and the reply
  says `REFUSED strength on <key>: claimed established; established needs 3
  distinct supporting sessions, and this signal has 2. Held at one_off`. It
  is never silent and it never raises past the evidence, and the student's
  "logged" is never blocked by it. Shape and record validation (unknown key,
  missing field, retention without its outcome, `new[]` naming a secure
  topic) does refuse the whole call.
- **One evidence row per (signal, session)**, as the primary key says. A later
  write from the same session replaces the earlier row, so a session cannot
  both support and contradict one signal.
- **A fourth table, `review_signal_events`**, records openings, strength
  changes, status changes, next_test edits and evidence rows. §3 names three
  tables; the ledger is what `SIGNAL MOVEMENT` in `tracker_week_report` and
  the week page read, and replaying it from evidence rows would be a guess
  because strength is requested-and-allowed, not purely derived. Movement is
  dated by the session it came from, falling back to the clock for movement
  with no session (a parent's decision).
- **The audit queue never hides a session owed a review.** Sessions logged
  before the last stamp that still have no review stay listed, marked
  *carried over*. Drafts and consistency checks over reviews are scoped to
  reviews written since the stamp, as §5.6 says.
- **`proposed_status` is refused when the session's `updates[]` moved that
  ref** — a proposal is for a status the updates did not move. `status_seen`
  is free.
- **Re-versioning derives its context from the record**: which refs the
  session moved from `topic_changes`, and the retrieval outcomes from the
  previous version's snapshot, else from the retrieval history dated the
  session's day.
- **The planner reaches `sessions.next_steps`** when the caller left it empty,
  as §4.2 asks. `next_steps` was already public on the session page, so the
  planner's six lines are visible there to anyone; the review itself, the
  signals and the error history are parent-only.
- **`check_whether` answered?** The drift block names the following session,
  whether it has a review, and that review's `one_sentence` beside the
  `check_whether` line. Whether it *answers* it is left to the reader; the
  service does not pretend to judge prose.
- **Cross-subject signals** (§10) are supported by the schema (`subject_slug`
  NULL, a unique index over `COALESCE(subject_slug, '')`) and read by
  `tracker_signals`; nothing writes one yet. The weekly review promotes by
  writing a new signal with no subject, as the tool description says.

---

## 1. Design principles

1. **Self-evident, not remembered.** No skill has to know that reviews exist to
   benefit from them. The existing session opener (`tracker_review_queue`) is
   extended so the last review's planner snapshot, open signals, pending teaching
   tests and things-to-watch arrive in the same call the tutor already makes.
   Nothing new to call at the start of a session.
2. **One write closes a session.** `tracker_log_session` grows an optional
   `review` argument and writes the session row, its topic changes and the
   review atomically. A second tool exists only for re-versioning (audit, parent
   correction).
3. **Mirror `weekly_reviews`.** Versioned, staged, `written_by`, server-frozen
   snapshot, validated sections. The two review kinds behave identically so
   nobody learns two models.
4. **The record adjudicates, the review proposes.** A review never bypasses the
   promotion bars in `gcse-progress-tracker`. Topic statuses still move only
   through `updates[]`; the review explains why and records what the bars did
   not yet allow.
5. **Patterns are rows, not prose.** Learning-process and teaching-method
   observations are stored as *signals* with a strength the server checks
   against the count of distinct sessions cited. "One occurrence is not a
   pattern" becomes a schema rule, not a hope.
6. **Private by default.** Reviews and signals render only behind the parent
   gate (`$isParent`). The public dashboard shows nothing beyond a "reviewed"
   tick on the session.
7. **No duplication across skills.** Teaching rules live in
   `study-principles.md`; promotion bars in `gcse-progress-tracker`; the
   timetable contract in `timetable.md`; the analytical rules of the review in
   `lesson-review/references/`. Each is referenced, never restated.

## 2. Vocabulary — re-mapped to tracker conventions

| Prompt term | Tracker expression | Rule |
|---|---|---|
| Secure | `secure` | Only if the promotion bar is met. Otherwise `status` omitted in `updates[]` and the review's `proposed_status` names it. |
| Developing | `developing` | Partial, or succeeded with prompting/scaffold. |
| Fragile | current status unchanged + `watch` set | Inconsistent, cue-dependent, recognition not recall. |
| Not yet demonstrated | `notstarted`, or no update | No evidence either way. Never a status change. |
| Misconception identified | `gap` + `watch` naming the misconception | A demotion requires evidence like any other change. |
| Retention | `retrieval_outcome`: `correct` / `retry` / `incorrect` | Retrieved independently → correct; with prompting → retry; not retrieved → incorrect. Feeds `retrieval_state`. |

## 3. Data model — migration step 12

All additive; nothing existing changes shape.

- `lesson_reviews` (session_id, subject_slug, version, stage ∈ draft/audited/parent,
  written_by ∈ session/audit/chat, written_at, snapshot_json, sections_json, note;
  UNIQUE (session_id, version)).
- `review_signals` (subject_slug NULL = cross-subject, kind, key, statement,
  strength ∈ one_off/emerging/established, status ∈ open/resolved/refuted,
  next_test, opened_session, updated_at; unique over (COALESCE(subject_slug,''), key)).
- `review_signal_evidence` (signal_id, session_id, direction ∈ supports/contradicts,
  evidence; PRIMARY KEY (signal_id, session_id)).
- `review_signal_events` (signal_id, session_id, at, change ∈
  opened/strength/status/next_test/evidence, from_value, to_value, detail).
- `review_errors` (session_id, subject_slug, ref, error_type, what, why_type, response).
- `sessions.review_required` (set at log time from the block kind or the duration)
  and `sessions.review_id` (the latest version's id; presence is the flag).
- `block_kind_rules.review_required`.

**Strength rule (server):** `one_off` needs 1 supporting session, `emerging` ≥2
distinct sessions, `established` ≥3 distinct sessions and no uncontradicted
`contradicts` row newer than the last `supports`. `signal_strength_refusal()`
in `review.php` is the one place it is written.

**Snapshot (server):** the session row, the block and how the board judged it,
every mentioned ref's status before and after the session's updates, the
retrieval outcomes recorded, the practice runs that day, the open unfinished
item, and the session summary (for the paraphrase flag).

## 4. `sections_json`

Free text is min 10 / max 600 unless noted. Unknown keys refuse the call.

| # | Key | Shape | Validation |
|---|---|---|---|
| — | `topic_refs[]`, `objective`, `resources[]` | header | refs validated; resources match stored titles or carry `unlisted: true` |
| 1 | `one_sentence` | string ≤ 200 | required |
| 2 | `progress[]` | `{ref, status_seen, evidence, implication, proposed_status?}` | ref in `topic_refs`; `proposed_status` only when `updates[]` did not move the ref |
| 3 | `independent` | string | required |
| 4 | `supported` | string | required |
| 5 | `errors[]` | `{ref, error_type, what, why_type, response}` | → `review_errors`; type ∈ §4.3 |
| 6 | `retention` | `{retrieved[], prompted[], not_retrieved[], schedule[]}` of `{ref, evidence}` | retrieved ⇔ outcome `correct`, prompted ⇔ `retry`, not_retrieved ⇔ `incorrect` on that ref in `updates[]` |
| 7 | `process[]` | `{area, basis, evidence, interpretation, implication}` | area ∈ §4.4; basis ∈ observed/interpretation/unknown; `avoidance` evidence must carry a number |
| 8 | `helped[]` | `{method, effect, evidence}` | method ∈ §4.5; effect helpful/neutral |
| 9 | `hindered[]` | same | effect difficulty/neutral |
| 10 | `confidence[]` | `{ref, confidence, accuracy, evidence, implication}` | high/low; omit the key when no evidence |
| 11 | `signals[]` | `{key, kind, statement, strength, direction?, evidence, next_test?}` | upserted by (subject, key); strength rule |
| 12 | `big_picture` | `{readiness, why}` | readiness ∈ progress / progress_with_retrieval / consolidate / partial_reteach / significant_reteach |
| 13 | `next_what` | `{opening_retrieval[], reteach[], consolidate[], new[], misconception_check[], challenge[]}` | refs validated; `new[]` refs not secure/examready |
| 14 | `next_how` | `{stages[]: {stage, method, why}}` | stage ∈ start/teach/check/practise_scaffolded/practise_independent/review/finish |
| 15 | `do_differently[]` | ≤ 3 strings | |
| 16 | `continue[]` | ≤ 3 strings | |
| 17 | `watch[]` | ≤ 3 `{key, what_to_observe}` | stored as `watch`-kind signals |
| 18 | `learner_voice[]` | `{quote, context?}` | quote required; empty when none |
| 19 | `planner` | `{priority, start_with, teach_using, avoid, check_whether, success}` | all six, ≤ 200 each; copied to `next_steps` when empty |
| — | `missing_evidence[]` | strings | required, may be empty |

Required keys: `one_sentence`, `independent`, `supported`, `big_picture`,
`planner`, `missing_evidence`. Every other section defaults to empty.

### 4.3 Error types
`knowledge_gap`, `misconception`, `forgotten_prior`, `procedure`, `calculation`,
`vocabulary`, `instruction_misread`, `missed_information`, `working_memory`,
`sequencing`, `rushed`, `transcription`, `unchecked`,
`right_reasoning_wrong_execution`, `undetermined`.

### 4.4 Learning-process areas
`readiness`, `task_initiation`, `instructions`, `attention`, `persistence`,
`response_to_error`, `retry`, `self_correction`, `checking`, `organisation`,
`use_of_notes`, `use_of_examples`, `resource_selection`, `help_seeking`,
`independence`, `processing_time`, `cognitive_load`, `pace`, `accuracy`,
`recall`, `confidence`, `frustration`, `avoidance`, `resilience`,
`metacognition`, `transfer`, `uncued_retrieval`.

### 4.5 Teaching methods
`direct_explanation`, `short_text`, `long_text`, `video`, `diagram`,
`visual_model`, `worked_example`, `modelling`, `step_by_step`, `discovery`,
`questioning`, `retrieval_questions`, `multiple_choice`, `scaffolded_task`,
`independent_task`, `immediate_feedback`, `delayed_feedback`, `repetition`,
`interleaving`, `quiz`, `writing`, `speaking`, `practical`, `note_taking`,
`copying`, `timed_task`, `organiser`, `say_it_back`, `checklist`, `chunk_break`.

## 5. Tools

- `tracker_log_session(…, review?)` — one transaction; validation refuses the
  whole call; `REVIEW REQUIRED and not written` when owed and absent.
- `tracker_save_lesson_review(subject, session_id, stage, written_by, sections, note?)`
  — re-versioning; draft refused over audited/parent; duplicate adds no
  version; note required from version 2.
- `tracker_get_lesson_review(subject, session_id, version?, planner_only?)` —
  the standard layout (`review_render_text()`), the snapshot, the drift.
- `tracker_list_lesson_reviews(subject, since?, limit?, stage?, missing?)`.
- `tracker_signals(subject?, kind?, status?, min_strength?)` and
  `tracker_update_signal(id, status?, evidence?, next_test?, session_id?, direction?)`.
- `tracker_review_audit_queue(subject)` and `tracker_audit_stamp(subject, note)`.
- Extended: `tracker_review_queue` (`### last_review`), `tracker_today`
  (`reviewed` / `review missing`), `tracker_history` (`[review v2 audited]`,
  error rows in `ref` mode), `tracker_get_state` (`ref`, error tally),
  `tracker_week_report` (`REVIEWS THIS WEEK`, `SIGNAL MOVEMENT`).

## 6. Dashboard

- `/s/{slug}/session/{id}`: parent — the rendered review (latest, `?v=` to
  switch, each version's note), then-and-now, the signals touched. Everyone —
  a "reviewed" tick.
- `/s/{slug}/reviews` (parent): newest first, readiness chip, one sentence, stage.
- `/signals` (parent): every signal, grouped by strength then kind, with its trail.
- `/week/{iso}` (parent): the week's readiness chips and the signal movement.
- `/s/{slug}/t/{ref}` (parent): error-type tally and the last three errors.

## 7. Skill contract

What the skills are written to; none of it lives in this repository.

- **`lesson-review` Mode A (wrap-up)**: every Shape A/C session and any Shape B
  that changed a status closes with one `tracker_log_session` call carrying
  `updates[]`, `retrieval_outcome`s and `review`. The student sees only the
  one-line "logged".
- **Mode B (audit, scheduled)**: opens with `tracker_review_audit_queue`; for
  each session owed a review, finds the chat and saves one with
  `written_by: audit`; for each draft, re-derives from the transcript and saves
  an `audited` version whose `note` lists corrections; corrects statuses
  through `tracker_update_topic`; moves signals with `tracker_update_signal`;
  closes with `tracker_audit_stamp`. A transcript that cannot be found is
  saved `audited` with note `transcript unavailable; verified against record only`.
- **Mode C (parent, on demand)**: read tools; a correction saves a `parent` version.
- **Tutor skills**: replace their logging body with a call to `lesson-review`
  Mode A; read `last_review` in the queue and say in one line what the session
  will observe.
- **`parent-weekly-review`**: reads `REVIEWS THIS WEEK` and `SIGNAL MOVEMENT`;
  names any signal that reached `established` with its trail; puts any
  `next_test` open three or more sessions to the parent as a decision.
- **`gcse-progress-tracker`**: a review's `proposed_status` is a proposal to
  adjudicate; `status_seen = secure` is not evidence that the bar was met.

## Appendix — single fallback block (connector unavailable)

```
=== LESSON REVIEW — [date] — [subject slug] — block [key|extra] — [x] min ===
ONE SENTENCE: …
PROGRESS: ref — status_seen — evidence — implication (one line each)
ERRORS: ref — type — what — response
RETENTION: correct: … | retry: … | incorrect: …
PROCESS: area — basis — evidence — implication
HELPED: method — evidence | HINDERED: method — evidence
SIGNALS: key — statement — strength claimed — next test
READINESS: …
PLANNER: priority / start with / teach using / avoid / check whether / success
LEARNER VOICE: "…"
MISSING EVIDENCE: …
=== END ===
```

Pasted into a tracker chat later, `gcse-progress-tracker` turns it into the one
`tracker_log_session` call.
