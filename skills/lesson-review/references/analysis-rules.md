# Analysis rules — from the prompt's sections to the `review` object

The prompt (`review-prompt.md`) says what a good review contains. This file says how each
section becomes a field the service validates, which enum each field takes, and the rules the
prompt states in prose that the service now enforces. Read it before writing any review; the
service refuses a review that breaks the schema and names the field, and a refused review is
a session logged without one.

Teaching rules are **not** here — they are `gcse-progress-tracker/references/study-principles.md`.
Promotion bars are **not** here — they are `gcse-progress-tracker/SKILL.md` § Status rules. This
file is only about analysing and recording.

## 1. Evidence, interpretation, unknown

Every claim in a review carries one of three bases, and the `process[]` entries carry it as a
field (`basis`: `observed` / `interpretation` / `unknown`):

- **observed** — it happened in the transcript and the entry cites it: the question number, her
  exact answer, the score, the prompt that was given. "Q3: wrote 2(3x²+4x), said 'done', did not
  check the factor" is observed.
- **interpretation** — a reading the evidence permits; written with "may", "suggests", "an
  emerging signal is". Never "definitely", never "learns best by".
- **unknown** — the honest value when the transcript does not settle it. A claim with no
  citation is `unknown`, not `interpretation`.

Where a field has no `basis` (progress, errors, helped/hindered, confidence), the `evidence`
string must itself be observed — a citation, not a characterisation. "Exit ticket 3/4, missed
the negative coefficient in Q4" passes; "understood well" does not, and it passes the length
check, so this is on the writer.

## 2. The self-review problem

The tutor and the reviewer are the same model in the same conversation, and a model reviewing
its own lesson is generous. Three mitigations, all mandatory:

1. **Third person.** The review refers to "the tutor" and "the learner". Never "I taught",
   never "we".
2. **Cite or downgrade.** Every `progress`, `process`, `helped`, `hindered` and `confidence`
   entry quotes or cites the transcript. If the citation cannot be found, the entry is written
   at `basis: unknown` or not at all.
3. **Count before judging.** Before writing section 2, tally from the transcript: questions
   set, attempted, correct unaided, correct after a hint, blank. Put the numbers in the evidence
   strings. A review with no number in it is a review that has not looked.

In audit mode a draft is a claim to be tested, not a document to be tidied. Re-derive the
tally from the transcript first, then compare.

## 3. Section → field mapping

| § | Field | Type and enum | Notes |
|---|---|---|---|
| header | `topic_refs[]`, `objective`, `resources[]` | refs must exist in the subject; resources are stored titles, or `{title, unlisted: true}` | subject, date, block and duration come from the session row |
| 1 | `one_sentence` | 10–200 chars | required |
| 2 | `progress[]` | `{ref, status_seen, evidence, implication, proposed_status?}` — `status_seen` ∈ gap/notstarted/developing/secure/examready | see §4 below |
| 3 | `independent` | 10–600 | required; demonstrated independent performance only |
| 4 | `supported` | 10–600 | required; the prompts and scaffolds still needed |
| 5 | `errors[]` | `{ref, error_type, what, why_type, response}` | `error_type` ∈ §6 list; `why_type` says why that classification |
| 6 | `retention` | `{retrieved[], prompted[], not_retrieved[], schedule[]}` of `{ref, evidence}` | each ref needs a matching `retrieval_outcome` in `updates[]` — §5 below |
| 7 | `process[]` | `{area, basis, evidence, interpretation, implication}` — `area` ∈ §7 list | only areas with evidence; `avoidance` states blanks against attempts as numbers |
| 8 | `helped[]` | `{method, effect: helpful, evidence}` — `method` ∈ §8 list | |
| 9 | `hindered[]` | same, `effect: difficulty` | `neutral` may appear in either |
| 10 | `confidence[]` | `{ref, confidence, accuracy, evidence, implication}` — levels `high`/`low` | **omit the key** when there is no evidence |
| 11 | `signals[]` | `{key, kind, statement, strength, direction?, evidence, next_test?}` | §9 below |
| 12 | `big_picture` | `{readiness, why}` — `readiness` ∈ progress / progress_with_retrieval / consolidate / partial_reteach / significant_reteach | required |
| 13 | `next_what` | `{opening_retrieval[], reteach[], consolidate[], new[], misconception_check[], challenge[]}` refs | `new[]` may not name a secure/examready ref |
| 14 | `next_how.stages[]` | `{stage, method, why}` — `stage` ∈ start / teach / check / practise_scaffolded / practise_independent / review / finish; `method` ∈ §8 list | |
| 15 | `do_differently[]` | ≤ 3 strings | |
| 16 | `continue[]` | ≤ 3 strings | |
| 17 | `watch[]` | ≤ 3 `{key, what_to_observe}` | stored as `watch`-kind signals |
| 18 | `learner_voice[]` | `{quote, context?}` | exact words only; empty list when nothing quotable |
| 19 | `planner` | `{priority, start_with, teach_using, avoid, check_whether, success}` each ≤ 200 | all six required; copied to `next_steps` when that was left empty |
| end | `missing_evidence[]` | strings | required, may be empty |

Required keys: `one_sentence`, `independent`, `supported`, `big_picture`, `planner`,
`missing_evidence`. Everything else is included when there is evidence and omitted when there
is none — an omitted section is honest; an invented one is not.

## 4. Section 2 and the statuses

`status_seen` is what **this lesson's evidence** supports, in tracker vocabulary. It is a
description, not a write: the review never moves a topic. Statuses move only through the
session's `updates[]`, adjudicated against the promotion bars.

- The evidence meets the bar → `status_seen: secure` **and** an `updates[]` entry with
  `status: secure`.
- The evidence points further than the bar allows (one strong exit ticket, one good paragraph)
  → `status_seen` at the current status, `proposed_status` at the higher one, `updates[]` with
  evidence and no status. `gcse-progress-tracker` adjudicates.
- Performance was fragile (inconsistent, cue-dependent, recognition not recall) → status
  unchanged, `watch` on the `updates[]` entry naming exactly what was inconsistent.
- A misconception → `errors[]` entry with `error_type: misconception`, `watch` naming it, and a
  demotion in `updates[]` only with evidence like any other change.
- No evidence either way → no `progress[]` entry for that ref.

The audit flags a `status_seen: secure` whose ref did not move and carries no
`proposed_status`; write one or the other.

## 5. Retention and retrieval outcomes

Section 6 is not prose about memory; it is the record that drives spacing. Every ref listed
under `retrieved` needs `retrieval_outcome: correct` on its `updates[]` entry; `prompted` needs
`retry`; `not_retrieved` needs `incorrect`. The service refuses a mismatch. `schedule[]` is the
"needs spaced retrieval" list and carries no outcome. Retrieval runs from the block skills (read
via `tracker_list_practice` and the queue) are evidence for this section too; cite the run.

## 6. Error types — the prompt's list, as stored

`knowledge_gap` · `misconception` · `forgotten_prior` · `procedure` · `calculation` ·
`vocabulary` · `instruction_misread` · `missed_information` · `working_memory` · `sequencing` ·
`rushed` · `transcription` · `unchecked` · `right_reasoning_wrong_execution` · `undetermined`

`why_type` must say why this one and not its neighbours: "procedure, not knowledge_gap — she
stated the rule correctly when asked, then applied it to the wrong term". `undetermined` is the
correct answer more often than it is chosen; use it when the transcript does not distinguish.

## 7. Learning-process areas

`readiness` · `task_initiation` · `instructions` · `attention` · `persistence` ·
`response_to_error` · `retry` · `self_correction` · `checking` · `organisation` · `use_of_notes`
· `use_of_examples` · `resource_selection` · `help_seeking` · `independence` ·
`processing_time` · `cognitive_load` · `pace` · `accuracy` · `recall` · `confidence` ·
`frustration` · `avoidance` · `resilience` · `metacognition` · `transfer` · `uncued_retrieval`

Rules the prompt states that bite here:

- **No diagnosis.** No condition, difference or disorder is named or implied, whatever the
  project instructions say about her. Describe behaviour in the lesson; nothing else.
- **`avoidance` is the blanks metric.** Whenever timed or exit-ticket work ran, one `avoidance`
  entry states "n blank of m attempted" in `evidence`, even when n is zero — zero is the trend
  the parent is watching for.
- **`frustration`** is recorded only from her words or an explicit break; never inferred from
  a wrong answer.
- Confidence goes in `confidence[]` (section 10) when there is a ref to attach it to, and in
  `process[]` as `area: confidence` when it is general. Never infer confidence from accuracy.

## 8. Teaching methods

`direct_explanation` · `short_text` · `long_text` · `video` · `diagram` · `visual_model` ·
`worked_example` · `modelling` · `step_by_step` · `discovery` · `questioning` ·
`retrieval_questions` · `multiple_choice` · `scaffolded_task` · `independent_task` ·
`immediate_feedback` · `delayed_feedback` · `repetition` · `interleaving` · `quiz` · `writing` ·
`speaking` · `practical` · `note_taking` · `copying` · `timed_task` · `organiser` · `say_it_back`
· `checklist` · `chunk_break`

The last four are the study-principles methods. Include them when they ran: the only way the
non-negotiables get refined is a record of whether they helped *today*. A method is
`helpful` when accuracy or independence rose after it in the transcript, `difficulty` when it
fell or she stalled, `neutral` when nothing changed. Never a learning-style label: "the diagram
helped today" is the ceiling.

## 9. Signals — patterns as rows, with the count rule enforced

A signal is one observation about how she learns (`learning_process`), how a method lands
(`teaching_method`), a recurring wrong idea (`misconception`), confidence-vs-competence
(`confidence`), a retention pattern (`retention`) or something to observe (`watch`).

- **Key** is a stable slug (`model-then-immediate-practice`, `blank-on-worded-problems`).
  **Before coining one, call `tracker_signals(subject)`** and reuse the existing key; a
  near-duplicate splits the evidence and neither half ever reaches `established`.
- **Strength** is the prompt's rule as a schema rule: `one_off` = 1 supporting session,
  `emerging` = 2 distinct sessions, `established` = 3 distinct sessions with no uncontradicted
  `contradicts` newer than the last `supports`. Claim what the count allows; the service refuses
  more and says the count. Never write `established` on a signal you opened today.
- **Direction** defaults to `supports`. When today's lesson cut against an existing signal,
  write it with `direction: contradicts` and the evidence — that is how a wrong pattern dies.
- **`next_test`** is the teaching experiment (`experiments.md`). A signal without one is an
  observation nobody will check.
- Section 11's three headings are read straight from the signal table: established = the
  established rows, emerging = emerging rows, one-off = today's new rows and existing one_offs.
  "Insufficient longitudinal evidence yet" is the honest section 11 when the subject has fewer
  than three reviewed sessions.

## 10. Learner voice

`quote` is her exact words from the transcript, including "I can't" and "skip it" — those are
the most useful quotes there are. `context` says when. Never a paraphrase, never a composite.
The audit flags a quote that also appears in the session summary, because that usually means the
tutor wrote it.

## 11. The planner

Six fields, each a sentence another teacher could act on without reading the review:

- `priority` — the one thing the next lesson is for.
- `start_with` — the opening retrieval, with the rule: "three no-notes questions on X; fewer
  than two correct → reteach before anything new".
- `teach_using` — the method sequence, from `next_how`.
- `avoid` — what must not be repeated from this lesson, and why in five words.
- `check_whether` — the diagnostic the next review must answer; the audit checks it was.
- `success` — the observable outcome: "4/4 unaided on structurally new examples".

The planner is what the tutor reads in `last_review` at the top of the next queue. Generic
planners ("more practice", "build confidence") are the prompt's named failure; the service
cannot refuse them, so the audit does.
