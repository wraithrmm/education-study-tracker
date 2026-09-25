# Synthesis rules — from the prompt's twenty parts to the `sections` object

The prompt (`synthesis-prompt.md`) says what a good synthesis contains. This file says how each
part becomes a field the service validates, which enum each takes, which rules the prompt
states in prose the server now refuses on, and the order to write the parts in so the analysis
comes before the overview. Read it before writing any synthesis. A refused save names the part
and field; fix that field and resend the whole object.

Not here: the teaching rules (`gcse-progress-tracker/references/study-principles.md`), the
promotion bars (`gcse-progress-tracker/SKILL.md` § Status rules), how to write a test
(`lesson-review/references/experiments.md`). Referenced, not restated.

## 1. Where the inputs come from

`tracker_week_synthesis_inputs(week)` returns twelve numbered inputs; every part is written
from them and nothing else:

1. header — sessions taught, reviews and stages, sessions still owed a review;
2. the lesson reviews in full, audited or latest, in date order;
3. every open signal with strength, status, trail, `next_test`, `test_set_by`, `test_week`,
   and this week's signal events;
4. tests due this week and whether a session answered each;
5. `review_errors` for the week grouped by type with counts;
6. `retrieval_state` rows touched, and the topics at risk of being mistaken for secure;
7. attempts with blanks and marks lost per topic;
8. topic movement this week with evidence;
9. the learner model;
10. the previous synthesis's planner, priorities, hypotheses and observations;
11. next week's timetable by subject;
12. the study-principle headings.

Never fetch a review one by one, never recount what the snapshot holds, never reach for a
chat. If the opener names sessions still owed a review, Part 1 says so and the parts proceed
without them; the audit will supply them and the parent can re-run the week.

## 2. The rules the server refuses on

| Rule | What is refused |
|---|---|
| One lesson is not a characteristic | a Part 3 `judgement` above what the signal's evidence rows allow; a Part 12 status above what its signals support |
| Cross-subject means cross-subject | a Part 3 entry at `emerging` or above whose cited sessions are in one subject; a promotion with one subject |
| Do not claim a recurring error | a Part 9 entry with fewer than two `review_errors` rows of that type this week |
| Do not invent learner voice | a Part 11 quote that no lesson review of the week recorded, character for character |
| No grades without assessment | grade language in Part 18 when no graded paper is in the snapshot |
| Retention over completion | a Part 6 ref with no retrieval record or review retention entry; `retaining` on a wrong streak or a last outcome of `retry`/`incorrect` |
| Every taught subject, no more | a Part 2 entry missing for a subject with a taught session, or present for one without |
| Every subject running next week | a Part 14 plan missing for a subject with a taught block next week |
| Only when there is a last week | Part 20 present with no previous synthesis, or absent when one exists |
| Architecture inside the principles | a Part 15 stage whose `why` names neither a study-principle heading nor "evidence this week: session N" |
| Caps | more than 3 hypotheses, 5 priorities, 5 per stop/start/continue, 5 observations |
| The parent's decisions stand | a Part 10 test on a signal whose test the parent set — not refused, left in place and reported |
| Do not overwrite the parent | a `draft` over a `parent` version |

Two rules the server cannot enforce and this skill must: **no diagnosis** — no condition,
difference or disorder named or implied, whatever the project instructions say about her; and
**no learning-style labels** — "visual learner" and its family never appear; the ceiling is
"accuracy improved when X was followed by Y" with the sessions cited.

## 3. Part → field mapping

Free text 10–800 characters unless noted. Refs must exist in their subject; session ids must
be in the week (or earlier, for an `established` trail); keys are slugs `^[a-z0-9][a-z0-9-]{1,60}$`.

| Part | Key | Shape | Enums and notes |
|---|---|---|---|
| 1 | `glance` | `{picture, most_important}` | `picture` 4–6 sentences ≤ 1200; `most_important` one sentence ≤ 300 |
| 2 | `subjects[]` | `{slug, topics[], secure[], developing[], fragile[], gaps[], retention, independence, readiness, why, platform_vs_evidence?}` | `secure/developing/fragile/gaps` are `{ref, evidence}`; `readiness` ∈ progress / progress_with_retrieval / consolidate / partial_reteach / significant_reteach |
| 3 | `cross_subject[]` | `{key, observation, evidence, sessions[], judgement, implication}` | `judgement` ∈ one_off_untested / one_off / emerging / established; a new key opens a cross-subject signal from the cited sessions; an existing per-subject key is promoted |
| 4 | `helped[]` | `{method, evidence, subjects[], improved, verdict}` | `method` ∈ `REVIEW_METHODS`; `verdict` ∈ use / use_and_test / insufficient |
| 5 | `hindered[]` | `{issue, evidence, confidence, change}` | `confidence` ∈ one_off / emerging / established |
| 6 | `retention[]` | `{ref, subject, verdict, evidence, action}` | `verdict` ∈ retaining / needs_spacing / false_secure; `action` ∈ retrieve / reteach / none |
| 7 | `confidence[]` | `{belief, performance, meaning, response, evidence}` | omit the key when no evidence; `evidence` cites a review confidence ref, a quote, or "session N" |
| 8 | `independence` | `{trend, prompts_evidence, fade[], keep[], reasoning}` | `trend` ∈ more / less / unchanged / mixed; `fade[]`/`keep[]` are `{support, why}` |
| 9 | `errors[]` | `{error_type, examples[], explanation, response}` | `error_type` ∈ `REVIEW_ERROR_TYPES` |
| 10 | `hypotheses[]` ≤ 3 | `{signal_key, hypothesis, evidence, how, collect[], supports, challenges}` | written to every signal with the key as `next_test` (from `hypothesis`) and `test_design`; `how` follows `experiments.md` |
| 11 | `learner_voice` | `{groups[]: {theme, quotes[]}, perception_vs_evidence}` | quotes verbatim from the reviews |
| 12 | `model[]` | `{key, change, statement, signal_keys[], note}` | `change` ∈ new / strengthened / weakened / disproved / uncertain; deltas only — a row not mentioned is unchanged |
| 13 | `priorities[]` ≤ 5 | `{rank, priority, why, evidence, action}` | ranks 1..n contiguous |
| 14 | `week_plans[]` | `{subject_slug, next_content, retrieve_first, reteach_if?, approach, scaffolding, independent, check_for, exit_check, watch_for}` | one per subject with a taught block next week |
| 15 | `architecture` | `{stages[]: {stage, why}, sufficient_evidence}` | `stage` ∈ start / orientate / teach / model / check / practise_scaffolded / practise_independent / review / exit / finish; `sufficient_evidence: false` allows empty `stages[]` |
| 16 | `stop_start_continue` | `{stop[], start[], continue[]}` ≤ 5 each of `{practice, method?, why}` | `method` ∈ `REVIEW_METHODS` when the practice is one |
| 17 | `observe[]` ≤ 5 | `{key, look_for, why}` | opened as `watch` signals with no session behind them; reuse an existing key to attach instead |
| 18 | `big_picture` | `{coverage, secure, fragile, retention, application, independence, pace, coverage_vs_mastery, efficiency}` | each ≤ 400 |
| 19 | `planner` | `{learning_priority, teaching_priority, retrieve, reteach, ready, use_more, use_less, test, watch, success}` | all ten, each ≤ 200 |
| 20 | `change[]` + `changes_worked` | `{category, what, evidence}`; `changes_worked` ≤ 600 | `category` ∈ improved / unchanged / harder / new_pattern / pattern_stronger / hypothesis_unsupported / change_worked / change_did_not_help |
| end | `collect_next_week[]` | strings ≤ 300 | required, may be empty |

Required keys: `glance`, `subjects`, `hypotheses` (may be empty only when
`architecture.sufficient_evidence` is false), `priorities`, `week_plans`, `planner`,
`collect_next_week`, and `change` + `changes_worked` iff a previous synthesis exists (input 10
tells you).

## 4. The order to write the parts

The prompt's order is the reading order. The writing order puts the analysis before the
overview, and the question the week was run to answer first:

1. **Part 20** — against input 10 and input 4: what was decided, whether each test was
   answered, and by what evidence. `changes_worked` is written here, from data, before
   anything else can colour it.
2. **Parts 2 and 6** — from the reviews (input 2) and the retrieval rows (input 6). Every
   `fragile[]` entry should correspond to a review `watch`; every `false_secure` verdict to a
   retry/incorrect on a secure topic.
3. **Parts 3, 4, 5, 7, 8, 9** — from the signals (input 3), the reviews' `helped`/`hindered`,
   `confidence`, `process` and `learner_voice`, and the error rows (input 5). Part 3 is a
   reading of the signal table across subjects, not a new derivation.
4. **Part 12** — deltas to the learner model (input 9): `new` for a row that does not exist,
   `strengthened` only where a signal it rests on gained strength this week, `weakened` or
   `disproved` only where a contradiction landed. A new row opens as `hypothesis` whatever its
   signals say; it strengthens next week.
5. **Parts 10, 13, 14, 15, 16, 17, 19** — the decisions. Part 10 hypotheses come from Part 3
   and Part 12's uncertain rows, and are written as `experiments.md` says. Part 14 plans every
   subject in input 11. Part 19 is written last of these, from the others.
6. **Parts 1 and 18** — the overview and the big picture, written when there is an analysis
   to overview. Part 1's `most_important` is one sentence that Part 12 or Part 20 already
   supports.
7. **`collect_next_week`** — what input 1 said was missing, plus what a part had to mark
   unknown.

Then the prompt's final quality check, applied to the object; a part that fails it is
rewritten before the save, not softened.

## 5. Signals: reuse before coining

Before writing Part 3, Part 10 or Part 17, read the signal keys in input 3. A key that
already exists is strengthened by citing it; a near-duplicate splits the evidence so neither
half ever reaches `established`. Part 10 may set a test on a key the reviews opened; Part 17
may attach an observation to one. Coin a key only for something no session has named.

## 6. Learner voice

Part 11 quotes are the reviews' `learner_voice[].quote` strings, exactly. Grouping and the
perception-versus-evidence comparison are the synthesis's; the words are hers. A quote that
does not appear in a review of the week is refused, which is the prompt's "do not invent
comments" as a schema rule.

## 7. The planner

Ten lines, each one an instruction another planner could act on without the synthesis. `test`
names the Part 10 hypothesis by its key and its one-line manipulation; `retrieve` names refs;
`reteach` names refs or says none; `success` is observable — a count, a descriptor met, a blank
count at zero. Generic lines ("more practice", "build confidence") are the prompt's named
failure; the server cannot refuse them, so the parent will.
