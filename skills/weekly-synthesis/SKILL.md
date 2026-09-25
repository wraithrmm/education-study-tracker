---
name: weekly-synthesis
description: Write and read the weekly learning synthesis for the home-educated GCSE student — the cross-subject analysis of how she learns, what progress she is making, and how that changes next week's teaching — from the week's audited lesson reviews, signals, errors, retrieval outcomes and attempts, saved to the Education Tracker with its decisions written back as week plans, test designs and learner-model changes. Use it as the Saturday scheduled task in the parent project (Mode A), when the parent asks to run or re-run a week's synthesis, and whenever the parent asks "what did the synthesis say", "show me the learner model", "how does she learn", "why is X a priority this week", "change hypothesis 2", or corrects a synthesis (Mode B). Never runs in a student chat. Do not use it for the Friday adherence review — that is parent-weekly-review — or for a single lesson — that is lesson-review.
---

# Weekly Synthesis

The learning half of the week, beside `parent-weekly-review`'s adherence half. The standard is
the qualified educator's prompt in `references/synthesis-prompt.md`; it is the text of record
and this skill does not restate it. `references/synthesis-rules.md` maps its twenty parts onto
the `sections` object the tracker validates and gives the writing order. Part 10's designs
follow `lesson-review/references/experiments.md`; the teaching rules are
`gcse-progress-tracker/references/study-principles.md`; the promotion bars are
`gcse-progress-tracker/SKILL.md`. Referenced, never duplicated. Read the prompt and the rules
before writing any synthesis in a conversation.

The Education Tracker (MCP connector, `tracker_*`) is the only store and the only source. A
synthesis is versioned (`draft` → `parent`), signed (`routine` / `chat`), frozen against a
server snapshot, and renders only behind the parent's login. Its decisions are not prose: Part
14 becomes the week plans `tracker_review_queue` prints as `this_week`; Part 10 becomes test
designs on signals; Part 12 changes the learner model; Part 17 opens watch signals. No tutor
skill needs to know the synthesis exists to be fed by it.

## Which mode

| Mode | Trigger | Writes |
|---|---|---|
| **A — routine** | The Saturday scheduled task in the parent project; "run the synthesis for W37" | one `tracker_save_week_synthesis` |
| **B — parent on demand** | "what did the synthesis say", "show me the learner model", "how does she learn", "why is X a priority", "change hypothesis 2", "that's not what happened Tuesday", "what's been synthesised" | reads; `tracker_save_week_synthesis(stage: "parent")` on a correction; `tracker_update_signal` on a rewritten test |

A student-facing chat never triggers either mode.

## Mode A — routine

1. **Open:** `tracker_week_synthesis_inputs(week)` — `week` omitted takes the week that ended
   most recently, which is what the Saturday task wants. Read it once. If it says the week
   already has a `parent` version, stop and say so; a routine draft may not be saved over the
   parent's.
1a. **Then, per subject taught:** `tracker_progress_forecast(subject)` and read its `SIGNALS`
   block as `progress-forecast` describes. A `stalled` or `no_new_topics` signal is a Part 13
   priority in its own right; `off_target` belongs in Part 18's big picture with the cone's
   band, never its most-likely figure alone; `stuck_topics` names the topics Part 14's week
   plan must decide about.
2. **Write** the twenty parts as the object in `synthesis-rules.md` §3, in the order §4 gives
   (Part 20 first, Parts 1 and 18 last), from the returned inputs only. Reuse existing signal
   keys (§5); quote learner voice verbatim (§6); no diagnosis, no learning-style labels, no
   counts the snapshot holds. Where the evidence is insufficient for a part, the part says so
   — `sufficient_evidence: false`, an empty optional array, "not enough evidence yet" in the
   text — rather than filling the gap.
3. **Quality check** — the prompt's twelve questions, against the object. A part that fails is
   rewritten.
4. **Save once:**

```
tracker_save_week_synthesis(
  week: "2026-W37",
  stage: "draft",
  written_by: "routine",
  sections: { glance: …, subjects: […], cross_subject: […], helped: […], hindered: […],
              retention: […], confidence: […], independence: {…}, errors: […],
              hypotheses: […], learner_voice: {…}, model: […], priorities: […],
              week_plans: […], architecture: {…}, stop_start_continue: {…}, observe: […],
              big_picture: {…}, planner: {…}, change: […], changes_worked: "…",
              collect_next_week: […] }
)
```

5. **Read the response.** A refusal names the part and field: fix that field and resend the
   whole object. On success the response lists the plans written, the tests set, the model rows
   changed, and any Part 10 test left in place because the parent had set one.
6. **Report in one paragraph:** the week, what was written, which tests were set on which
   signals, which were left because the parent set them, and any sessions the opener said were
   still owed a review. Nothing else is printed; the document lives at `/week/{iso}` behind the
   parent's login.

A thin week (fewer than three audited reviews) still runs: Parts 1 and 20 are still worth
having, and the caps and the insufficiency wording keep it short.

## Mode B — parent on demand

| Ask | Do |
|---|---|
| "what did the synthesis say" / "this week's synthesis" | `tracker_list_week_syntheses` for the week if unnamed, then `tracker_get_week_synthesis(week)`; relay the rendered parts the parent asked about, planner first, and the drift block (tests answered, plans read, model moved). |
| "how does she learn" / "show me the learner model" | `tracker_learner_model()`; lead with `established`, then `supported`, then `hypothesis` with the test that would move it; `weakened`/`disproved` only if asked. Each with the signals it rests on and its week history. |
| "why is X a priority" / "why reteach Y" | `tracker_get_week_synthesis` — read Part 13's `evidence` and Part 6's verdict for it, and cite the sessions. |
| "change hypothesis 2 to …" / "run this test instead" | `tracker_signals(subject)` for the id; `tracker_update_signal(id, next_test, test_design: {how, collect, supports, challenges}, test_week)`. The test is now the parent's; no routine synthesis will overwrite it. |
| "drop that signal" / "that pattern isn't real" | `tracker_update_signal(id, status: "refuted", evidence: "parent decision: …")`. |
| "that's not what happened Tuesday" / "Part 5 is wrong" | Read the version, correct the part, `tracker_save_week_synthesis(week, stage: "parent", written_by: "chat", sections, note: <what changed and why>)`. A topic status the parent says is wrong goes through `gcse-progress-tracker`, never through the synthesis. |
| "re-run last week" | Mode A for that week, unless it has a `parent` version — then say so and offer a correction instead. |
| "did last week's changes work" | `tracker_get_week_synthesis` for the newer week, Part 20 and `changes_worked`; the parent's own Friday verdict is in the weekly review's decisions and may differ — say both. |

Answer from what is stored. Where a part says insufficient or unknown, say so.

## Connector unavailable

Say so and stop. The synthesis has no fallback block: its inputs exist only in the tracker,
and a synthesis written from memory is the thing the prompt forbids. The Saturday task
re-runs the following week for both weeks, each saved separately.
