# Weekly learning synthesis — service contract

The specification the service is built against (`php/lib/synthesis.php`,
`php/lib/mcp_synthesis.php`, `php/lib/dashboard_synthesis.php`, the
`weekly learning synthesis` section of `Store`), and the decisions taken where
the specification left room. The skill pack (`weekly-synthesis` and the
amendments in §8) lives with the skills, not in this repository.

Written against schema step 13, beside `docs/lesson-review.md`.

## 0. Implementation notes — where the service decided

- **A new learner-model row opens as `hypothesis`** whatever its signals'
  strength. Part 12's `new` is the row's first appearance; `strengthened`
  the following week moves it to the highest status its signals allow
  (`supported` on an emerging signal, `established` on an established one).
  A row cannot be `strengthened` before it exists, nor opened twice.
- **`retaining` is refused** when the topic's retrieval record shows a wrong
  streak *or* a last outcome of `retry` or `incorrect`. §6 names only the
  streak; a `retry` never increments it, and a last outcome of retry is the
  spec's own definition of `false_secure`.
- **A test answered by any twin counts.** A session answers a test by citing
  the key in its review, which lands on its own subject's signal. `testsDue`
  and the drift look for an evidence row on any signal with that key in the
  week, so a cross-subject twin or a sibling in another subject reads the
  answer too.
- **The drift reads a synthesis's tests from its own Part 10**, not from the
  signals' current `test_week`, so a later synthesis re-setting a test does
  not erase the record of whether the earlier one was answered.
- **The snapshot's model and signals are taken after the synthesis's own
  writes**, so drift measures what moved *after* it. The reviews, statuses
  and retrieval rows are what it read.
- **Cross-subject promotion joins same-key signals.** A per-subject signal's
  evidence sessions are all in its subject, so `promote` gathers every
  per-subject signal with the key, requires their evidence sessions to span
  two subjects, and opens the twin with those rows as evidence and
  `promoted_from_json` naming the sources. Part 3 with an existing
  per-subject key does the same; with a new key it opens the twin from the
  cited sessions.
- **Part 10 sets the test on every signal with the key** (each per-subject
  row and the cross-subject twin). A parent-set test on any of them is left
  in place and reported.
- **The study-principle headings** the server checks Part 15 against are a
  default list in `synthesis.php`, overridden by the meta key
  `study_principles` (a JSON list) when the parent sets one. The principles
  themselves live with the skills.
- **A review may set or replace its own test** on a signal, never one the
  synthesis or the parent set; those are answered by citing the key.
- **`tracker_week_synthesis_inputs` with no week** takes the week that ended
  most recently (last week, until Sunday has passed), which is the week the
  Saturday routine wants.
- **The weekly review's `decisions[]`** gains kind `synthesis_verdict` with
  `ref` a signal key or `week` and `decision` ∈ `change_worked`,
  `change_did_not_help`, `mixed`, `untested` — the parent's Friday answer.

---

## 1. Design principles

1. **Roll-up, not re-derivation.** One opener returns every input, computed
   server-side. The synthesis interprets; it does not recount.
2. **Two weekly artefacts, one week page.** `weekly_reviews` stays the Friday
   adherence review; the synthesis is a second, separately versioned document.
3. **The synthesis is where teaching changes.** Hypotheses become test designs
   on signals; per-subject instructions become week plans the queue prints;
   the observation plan becomes watch signals; the learner model evolves by
   deltas. Part 20 next week reads what was decided and reports whether it
   worked.
4. **Only the synthesis writes the learner model and promotes to
   cross-subject.**
5. **Strength is still derived.** A synthesis claim is validated against the
   signal table at save time.
6. **After the audit, before Monday.** Saturday morning, reading audited
   reviews; its plans are in Monday's first `tracker_review_queue`.
7. **Private.** Synthesis, learner model and week plans render only behind
   the parent's login.
8. **The study principles are not the synthesis's to change.**

## 2. Vocabulary

| Prompt term | Tracker expression |
|---|---|
| Established / developing pattern | signal `established` / `emerging` |
| Possible signal | signal `one_off` with a `next_test` |
| One-off observation | `one_off` without a test; not carried into Part 12 |
| Part 2 recommended status | `readiness` enum |
| Part 6 verdicts | `retaining` / `needs_spacing` / `false_secure` |
| Part 4 verdicts | `use` / `use_and_test` / `insufficient` |
| Part 16 | `stop` / `start` / `continue` against a method or a practice |
| Part 12 change kinds | `new` / `strengthened` / `weakened` / `disproved` / `uncertain` |
| Part 20 categories | `improved` / `unchanged` / `harder` / `new_pattern` / `pattern_stronger` / `hypothesis_unsupported` / `change_worked` / `change_did_not_help` |
| Lesson stages | `REVIEW_STAGE_KEYS`: start, orientate, teach, model, check, practise_scaffolded, practise_independent, review, exit, finish |

## 3. Data model — migration step 13

- `weekly_syntheses` (week, version, stage ∈ draft/parent, written_by ∈
  routine/chat, snapshot_json, sections_json, note; UNIQUE (week, version)).
- `week_plans` (synthesis_id, week planned, subject_slug, the nine fields,
  refs_json, read_at; UNIQUE (synthesis_id, subject_slug)). The latest
  synthesis of a week owns that week's plans.
- `learner_model` (key UNIQUE, statement, status ∈ hypothesis / supported /
  established / weakened / disproved, signal_ids, opened_week, updated_week,
  history_json). Status ≤ signal support: `established` needs an established
  signal, `supported` an emerging one, `weakened`/`disproved` a contradicting
  row or refuted signal.
- `review_signals` rebuilt with `test_design_json`, `test_set_by`,
  `test_week`, nullable `opened_session`, `opened_by`, `opened_week`,
  `promoted_from_json`. Every row and id is carried across; the two child
  tables are rebuilt so their foreign keys end on the new table.
- `meta.last_synthesis_week`, `meta.study_principles` (optional override).

## 4. `sections_json`

Twenty parts as fields; free text min 10 / max 800 unless noted. Required:
`glance`, `subjects`, `hypotheses` (may be empty only with
`architecture.sufficient_evidence: false`), `priorities`, `week_plans`,
`planner`, `collect_next_week`, and `change` + `changes_worked` iff a previous
synthesis exists.

| Part | Key | Validation |
|---|---|---|
| 1 | `glance {picture, most_important}` | picture 4–6 sentences ≤ 1200; most_important ≤ 300 |
| 2 | `subjects[]` | one per subject with a taught session, no more; refs validated; `readiness` enum |
| 3 | `cross_subject[]` | `judgement` ∈ one_off_untested/one_off/emerging/established; emerging+ needs cited sessions in ≥ 2 subjects; judgement ≤ the strength the rows allow |
| 4 | `helped[]` | `method` ∈ REVIEW_METHODS; `verdict` ∈ use/use_and_test/insufficient |
| 5 | `hindered[]` | `confidence` ∈ strengths |
| 6 | `retention[]` | ref needs a retrieval_state row or a review retention entry this week; `retaining` refused on a wrong streak or a last outcome of retry/incorrect |
| 7 | `confidence[]` | optional; evidence cites a review confidence ref, a quote, or "session N" |
| 8 | `independence` | optional; `trend` ∈ more/less/unchanged/mixed |
| 9 | `errors[]` | ≥ 2 `review_errors` rows of the type this week |
| 10 | `hypotheses[]` ≤ 3 | `signal_key` exists or is opened by Part 3 / 17 |
| 11 | `learner_voice` | every quote equals a review quote of the week |
| 12 | `model[]` | deltas under the status rule |
| 13 | `priorities[]` ≤ 5 | ranks 1..n contiguous |
| 14 | `week_plans[]` | one per subject with a taught block next week; refs validated |
| 15 | `architecture` | stage ∈ enum; `why` names a principle heading or "evidence this week: session N" |
| 16 | `stop_start_continue` | ≤ 5 each |
| 17 | `observe[]` ≤ 5 | opened as watch signals |
| 18 | `big_picture` | nine fields ≤ 400; a grade refused without a graded paper; `grade_basis` server-filled |
| 19 | `planner` | ten fields ≤ 200 |
| 20 | `change[]` + `changes_worked` | required iff a previous synthesis exists |
| end | `collect_next_week[]` | required, may be empty |

## 5. Tools

- `tracker_week_synthesis_inputs(week?)` — the twelve numbered inputs.
- `tracker_save_week_synthesis(week, stage, written_by, sections, note?)` —
  validates whole; in one transaction opens Part 3 / 17 signals, sets Part 10
  tests (never over a parent's), applies Part 12, saves the row with the
  snapshot, replaces next week's plans, stamps `last_synthesis_week`.
- `tracker_get_week_synthesis(week, version?, planner_only?)` — layout,
  snapshot, drift.
- `tracker_list_week_syntheses(limit?)`.
- `tracker_learner_model(status?)`.
- `tracker_update_signal` — `test_design`, `test_week` (the test becomes the
  parent's), `promote`.
- Extended: `tracker_review_queue` (`### this_week`, stale flag, TEST THIS
  WEEK, records the read), `tracker_week_report` (`SYNTHESIS`, `LAST WEEK'S
  DECISIONS`), `tracker_review_audit_queue` (`synthesis_test_unanswered`, and
  who set a stale test), `tracker_signals` (setter, week, design),
  `tracker_get_lesson_review` drift (synthesis tests due that week).

## 6. Validation the server owns

As the table in the specification, with the notes in §0.

## 7. Dashboard

- `/week/{iso}` (parent): the synthesis beneath the weekly review, `?sv=` to
  switch versions, planner pinned first, Part 20 as decided / happened.
- `/learner` (parent): the model by status with signals and history.
- `/signals` (parent): set by / for week / design, answered tick.
- `/s/{slug}` (parent): the current week plan card with the read tick.

## 8. Skill contract

None of it lives in this repository.

- **`weekly-synthesis`** Mode A: `tracker_week_synthesis_inputs` → write the
  twenty parts, Part 20 first and Parts 1 and 18 last → one
  `tracker_save_week_synthesis`. Mode B: read tools; corrections save a
  `parent` version; a rewritten hypothesis goes through
  `tracker_update_signal(test_design, next_test)`.
- **`parent-weekly-review`**: reads `LAST WEEK'S DECISIONS`; puts "did the
  changes work" to the parent; records the answer as `synthesis_verdict`.
- **`lesson-review`**: reads `this_week` like `last_review`; answers a due test
  in `signals[]` with the same key; a synthesis-set design is run as written.
- **Tutor skills**: one sentence under "Third call" about `this_week`.
- **`retrieval-block-session`**, **`gcse-progress-tracker`**, **`term-planner`**,
  **`study-principles.md`**: as the specification's §8.2.

## 9. Schedule

One, in the parent project: Saturday 06:00 Europe/London, running
`weekly-synthesis` Mode A for the week that ended the day before. No
student-project schedule.
