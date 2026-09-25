---
name: parent-weekly-review
description: Run the parent's Friday weekly review of the home-educated student's GCSE week across every tracked subject — timetable adherence block by block, missed blocks named plainly, pending day-off requests put to the parent, hours done against the target split, handwriting stamina and blanks from timed work, topic movement and one carry-forward per subject — then tick the review block in the Education Tracker. Use whenever the parent says "weekly review", "how did the week go", "what did she miss this week", "Friday review", or asks for last week's or a named ISO week's review. Do not use for a single-subject progress report or grade projection — that is gcse-progress-tracker.
---

# Parent Weekly Review

One sweep, all five subjects, one screen of output. The Education Tracker (MCP connector,
`tracker_*`) is the only source; the contract for how blocks are judged is
`gcse-progress-tracker/references/timetable.md`, and the status and evidence rules are
`gcse-progress-tracker/SKILL.md`. This skill reads a lot and writes little: day-off decisions,
excusals the parent states, and the review tick. It never changes a topic status — anything
that needs adjudicating is handed to `gcse-progress-tracker` with the evidence.

## Which week

Default: the current ISO week (Mon–Fri, Europe/London). "Last week" → the previous ISO week.
A named week (`2026-W37`) → that week. Say which week at the top of the output.

## Reads — in this order, all before writing anything

1. `tracker_week_status(week)` — every block with status, extras, hours by subject, counts.
2. `tracker_days_off(status: "requested")` — pending requests, any date.
3. `tracker_week_report(week)` — read five of its blocks. `EXAM PRACTICE` (one line per
   Wednesday sitting: status, minutes sat against the duration, who closed it, and once marked
   the section marks with blanks). `REVIEWS THIS WEEK` (one line per
   taught session: readiness and one-sentence, plus any `REVIEW MISSING`). `SIGNAL MOVEMENT`
   (signals opened, strengthened, resolved or refuted). `SYNTHESIS` (whether this week's
   synthesis has been saved, its version and stage — on a Friday it will not have been; it runs
   Saturday). `LAST WEEK'S DECISIONS` (the previous synthesis's planner and each test it set,
   with answered / untested from the reviews). These are computed from the lesson reviews and
   the synthesis; do not re-derive patterns from session prose, and do not propose tests of your
   own — proposing is the synthesis's job.
4. `tracker_list_subjects()` then, per subject: `tracker_history(subject, weeks: 1)` for the
   sessions logged and every status that moved; `tracker_list_attempts(subject, limit: 3)` for
   anything sat this week (blanks per paper, handwritten pieces); `tracker_review_queue(subject)`
   for the top gap, the ageing secures, and — in its `last_review` block — the subject's latest
   planner, which is where that subject's carry-forward comes from.
5. `tracker_list_practice(subject: "spanish", since: <Monday>)` — the Spanish slots are practice,
   not sessions, and would otherwise look empty.
5a. `tracker_progress_forecast(subject)` per subject — read its `SIGNALS` block the way
   `progress-forecast` says: a stall of two weeks or more, a run under the aimline, an
   off-target cone or no new topic opened goes into "Slipped" and that subject's
   carry-forward, with the tool's own wording and level. Quote the needed-versus-delivered
   pace when the question is whether she is on track.
6. `tracker_signals(status: "open", min_strength: "established")` only when `SIGNAL MOVEMENT`
   names a signal that reached established this week — to fetch its full trail for the parent.

Do not skip a subject because it looks quiet; a quiet subject is a finding.

## Output — this shape, every time

Every exam code is glossed on every appearance — "Lang Q5 (the 40-mark description piece)",
"Q4 (the 20-mark 'do you agree' question)" — from the subject appendix's plain-names table.
The parent reads this on a phone and does not carry the paper's numbering in his head.

```
Week 2026-W37 (7–11 Sep) · 13 of 16 blocks done · 2 missed · 0 excused · 1 extra

MISSED
- Tue 13:00 timed handwritten — no attempt logged
- Thu 09:25 maths deep block — no session logged

DAYS OFF — pending your decision
- Mon 14 Sep, requested by Paige: "friend's birthday" → approve / decline?

HOURS vs split
maths 3.5/5 · lit 4.5/4.5 · lang 3.5/3.5 · cs 3.5/3.5 · spanish 1.0/1.75

TIMED / HANDWRITTEN
- none this week (last: Tue 1 Sep, Lang Q5 — the 40-mark description piece, 25 min sustained, 0 blanks)

EXAM PRACTICE
- Wed 23 Sep, "Exam practice — week 39" — marked, sat 45 of 45 min, closed by timer: maths 8/12 · lang 9/12 · cs 4/10 (2 blank)
- technique: Q7 took 11 min of a 4-min guide and scored 1; two blanks in the CS section with 6 min unused

MOVEMENT THIS WEEK
- maths: A17 developing→secure (Mon exit ticket 4/4); A4 evidence only
- lit: N3 gap→developing; quotation recall 4/5 (Macbeth)
- lang: W1 evidence only — Level 5 descriptor reached on one piece (needs a second)
- cs: two topics notstarted→developing (loops, selection)
- spanish: 3 runs, 34 items, 71% first-time

REVIEWS
- Mon maths s142 progress_with_retrieval · Tue lit s143 consolidate · Thu maths s147 partial_reteach
- REVIEW MISSING: cs session 145 (Tue) — the audit will pick it up; nothing to decide

SIGNALS
- ESTABLISHED this week — maths/model-then-immediate-practice: accuracy rose after one step
  modelled then practised (s131 3/4, s138 4/4, s142 4/4; no contradiction). Teach that way by
  default now.
- emerging: lit/quote-recall-cue-dependence (2 sessions)

LAST WEEK'S DECISIONS — did the changes work?
- Planner said success would be "0 blanks on worded items, A18 exit 4/4". Happened: 1 blank Mon,
  0 Thu; A18 exit 3/4.
- Test #12 blank-on-worded-problems (set by synthesis): answered Mon s142 supports, Thu s147
  supports → change_worked / did_not_help / mixed?
- Test #15 quote-recall-mcq-first (set by synthesis): untested — no Lit session ran it → untested,
  or carry to next week?

CARRY-FORWARD (one per subject, from the latest planner)
- maths: priority "N2 signs before A18"; start with three no-notes sign questions
- lit: priority "second Inspector paragraph unaided"; avoid re-modelling the first
- lang: second unaided Level-5 piece to secure W1
- cs: trace tables — proposed for Tuesday's timed rotation
- spanish: below split; drop to Wed + Fri only if the exam subjects overrun again

Next week's Tuesday timed piece: Lit essay (rotation).
https://education.rmmann.co.uk
```

Rules for the output:

- **Every missed block is named** with day, time, label and what was absent (no session / no
  attempt / no practice). Never "a couple of misses". Never a streak or a "nearly".
- **Short** blocks (done but under half the block length) are listed under MISSED with the
  duration, not hidden under done.
- **Extras** are named too — work is work — but do not offset a miss in the same subject unless
  the parent says so.
- **Hours** are actual done-block durations plus extras, against the split (Maths 5 · Lit 4.5 ·
  Lang 3.5 · CS 3.5 · Spanish 1.75). One line.
- **Timed/handwritten** reports minutes sustained and blanks from this week's attempts; if none,
  say so and give the last one, so stamina drift is visible.
- **Movement** quotes real refs and real evidence from the history, one line per subject.
- **Reviews** is one line per taught session from `REVIEWS THIS WEEK` — readiness word and
  session id — and names any `REVIEW MISSING` plainly. A missing review is the audit's job, not
  the parent's; say so and move on unless the audit's stamp note says the chat could not be found.
- **Signals**: any signal that reached `established` this week is named with its statement and
  its trail — the session ids and the one-line evidence from each — because that is the moment a
  pattern becomes something to teach by. Emerging signals get one line each. Never propose a
  test here.
- **Last week's decisions**: every test the previous synthesis (or the parent) set for this week
  is put to the parent with what the reviews recorded — answered with which direction, or
  untested — beside the planner's `success` line and what actually happened. The parent's answer
  to "did the changes work" is recorded per test (`change_worked` / `change_did_not_help` /
  `mixed` / `untested`), and once for the week. Saturday's synthesis answers the same question
  from data in its Part 20; the two may differ, and both are kept.
- **Carry-forward** is one line per subject, drawn from that subject's latest lesson-review
  planner (`last_review` in its review queue: the `priority` and `start_with` lines) — then from
  misses if there is no planner, then from the review queue's top item. The *week's* priorities
  are the synthesis's (Part 13) and are not restated here; if the parent asks for them, read
  `tracker_get_week_synthesis(week, planner_only: true)` for the previous week.
- **Rotation**: the Tuesday timed block rotates Lang Q5 (the description piece) → Lit essay → Maths section → CS program.
  Read which was last from the attempts and name the next.
- Keep the whole thing on one phone screen if the week was clean; two if it wasn't.

## Writes — only these, only on the parent's word

- **Excusals**: for each missed block, ask once, in the DAYS OFF/MISSED block, whether to excuse.
  On a stated reason, `tracker_excuse_block(date, block_key, reason)`. The model never excuses
  on its own.
- **Days off**: for each pending request, record the parent's answer with
  `tracker_decide_day_off(id, decision, note?)`. A day off the parent announces now is booked
  with `tracker_request_day_off(requested_by: "parent")`.
- **Adjudication**: if the history shows evidence logged without a status where a promotion was
  proposed (including a review's `proposed_status`), hand it to `gcse-progress-tracker` in the
  same turn, and say what you accepted.
- **Synthesis verdicts**: the parent's answers go into the weekly review's `decisions[]` as
  `{kind: "synthesis_verdict", ref: <signal key or "week">, decision: change_worked |
  change_did_not_help | mixed | untested, note?}` on `tracker_save_weekly_review`. A test the
  parent wants rewritten goes through `tracker_update_signal(id, next_test, test_design,
  test_week)` — it is then the parent's and no routine synthesis overwrites it; a signal the
  parent drops is `tracker_update_signal(id, status: "refuted", evidence: "parent decision: …")`.
  Promotion to cross-subject is the synthesis's (`weekly-synthesis` Mode B, or
  `tracker_update_signal(id, promote: true)` on the parent's word).
- **The review tick**: last, `tracker_tick_block(date: <Friday>, block_key: <review block>,
  by: "parent", note: "weekly review done")`. Read `tracker_today()` on the Friday for the key.
- **Verify**: `tracker_week_status` again and confirm the counts changed the way the writes said.

## What not to do

- Do not write anything about the student beyond the work — the dashboard is public.
- Do not backdate, re-label or void anything to tidy the board; a wrong record is corrected
  through `gcse-progress-tracker` with evidence that says it is a correction.
- Do not turn the review into a timetable edit. If the pattern says the timetable should change
  (the same block missed three weeks running), say so in one line and offer `term-planner`.
- Do not offer reassurance the numbers don't support.

## Fallback

Connector unavailable: say so, do not fabricate the week, and ask the parent for the dates of any
sessions or attempts so they can be entered when it returns.
