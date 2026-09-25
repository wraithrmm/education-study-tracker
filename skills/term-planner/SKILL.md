---
name: term-planner
description: Plan and re-plan the home-educated student's GCSE study term for the parent — phase shifts toward the June 2027 exams (knowledge-building → transition → final), the checkpoint and mock calendar across all subjects, private-candidate entry deadlines and tier decisions, handwriting-stamina progression, and any change to the weekly timetable, written through the Education Tracker with a diff shown first. Use when the parent asks what's coming up, when the next mock is, about entry deadlines or the exam centre, to move or change a timetable block, to set term dates, or says the plan needs to change. Do not use for the weekly review — that is parent-weekly-review — or for adjudicating topic statuses — that is gcse-progress-tracker.
---

# Term Planner

Owns the calendar and the shape of the timetable over time. Reads the subject appendices
(`gcse-progress-tracker/references/subjects/<slug>.md`) for each subject's checkpoints; holds the
cross-subject calendar in `references/checkpoint-calendar.md`; writes timetable changes through
`tracker_set_timetable` after showing the diff. The teaching rules behind the timetable's shape
are `gcse-progress-tracker/references/study-principles.md`; the block contract is
`references/timetable.md` in the same skill.

## Three jobs

### 1. "What's coming up?" — the calendar

Read `references/checkpoint-calendar.md`, then every subject appendix, then
`tracker_list_subjects` for the stored exam dates. Answer with the next 8 weeks of dated items in
order, marking each as **confirmed** (from the appendix or the subject row) or **TO CONFIRM**
(placeholder). Any item within 6 weeks gets its lead-in actions listed underneath. Never invent a
date; if the calendar says TO CONFIRM, say TO CONFIRM and offer to search for the published one.

### 2. Phase shifts — re-cutting the timetable

Three phases, each with a target block mix. Move between them on the parent's say-so, prompted
by a checkpoint result, never silently.

| Phase | When | Block mix change |
|---|---|---|
| **Knowledge-building** | Sep–Dec 2026 | Current timetable. Teach blocks dominate; one timed handwritten block a week, short and lengthening ~5 min every 2–3 weeks; spacing weekly → fortnightly |
| **Transition** | Jan–Mar 2027 | Mon/Tue `teach` blocks become `practise` on past-paper sections; a second timed block appears (Thu deep block becomes timed on alternate weeks); timed pieces reach full paper length; spacing tightens to ~weekly; Spanish drops to two slots |
| **Final** | Apr–Jun 2027 | Full past papers under exam conditions Mon and Tue; Fri becomes marks-lost-driven reteach + retrieval; Wed hour is pure retrieval on the review queue's weakest; Spanish to one slot or paused; movement and breaks unchanged — they are not optional in the final phase |

`exam_practice` is a block kind like any other: one a week, Wednesday afternoon, subject
`exam-skills`. It is the only block the student starts herself, so moving it moves a link she
follows — re-cut it like any other block and tell her the new time. Its length is the paper's
length: 45 minutes through the knowledge-building phase, 60 from the transition phase, 90 in the
final phase as handwriting stamina allows. A scheduled test that has not been sat does not block
a re-cut; the test carries its own `block_key` and the paper simply follows the block.

Before proposing any change to the block set — a phase shift, a moved block, a different
kind — read the last four weekly syntheses: `tracker_list_week_syntheses(limit: 4)`, then
`tracker_get_week_synthesis(week)` for each, taking Part 13 (priorities), Part 15 (the lesson
architecture and whether the evidence was sufficient) and Part 5 (what hindered, with its
confidence). A timetable change rests on what those say — a block kind that Part 5 names as a
hindrance at `emerging` or above, a priority that needs a block the week does not have, an
architecture that no longer fits a block's length — and the proposal cites the synthesis week
for each change it makes. A change the syntheses do not support is still the parent's to make,
but the proposal says plainly that the evidence is silent. Four weeks, because a phase shift on
one week's synthesis is the one-lesson-one-characteristic error at the timetable's scale.

Re-cutting is: `tracker_get_timetable` → propose the new block set as a diff (added / removed /
changed, with times, each citing its synthesis week) → parent approves →
`tracker_set_timetable(blocks, valid_from, note)`.
Block keys are kept stable where the block survives, so history and excusals still resolve. The
`valid_from` is the Monday the new shape starts. Show the diff *before* writing, every time.

Constraints that survive every phase, because the research does: movement before the first hard
block; retrieval warm-up first; no whole-subject switching inside a block; 20–25-minute chunks
with breaks; Spanish loses every tie; handwriting on paper at least weekly from Transition on.

### 3. Handwriting stamina — the progression

Timed handwritten work starts at 20–25 minutes and lengthens by ~5 minutes every 2–3 weeks, so
that by the Transition phase she can sustain a full section, and by March a full paper (English
papers are 1 h 45 and 2 h 15; Maths 1 h 30; CS 2 h and 1 h 45). Track "minutes sustained" from the
attempts' notes; if it stalls for three weeks, flag it and propose an extra short handwritten
block rather than a longer one. Flag also, once, in Knowledge-building, the option of an
access-arrangements assessment (extra time / word processor) if handwriting speed rather than
knowledge is what the timed pieces are measuring — that is the parent's decision and a centre
matter.

## Entry logistics — a private candidate's calendar

Home-educated students sit GCSEs as **private candidates** at an exam centre that agrees to
host them. The centre sets its own internal deadline, usually weeks ahead of the board's. Items
the parent must not discover late:

- **Find and confirm a centre** for all four 2027 subjects by autumn 2026; ask their internal
  entry deadline and record it in `references/checkpoint-calendar.md`.
- **AQA summer entries close around 21 Feb 2027** (standard fee); late entries cost more and
  close later; very late entries later still with a much higher fee. Dates TO CONFIRM against
  the current AQA key-dates page each autumn — search, don't recall.
- **Maths tier** is decided with the entry, so the February Higher mock must land before the
  centre's deadline, not the board's.
- **English Language Spoken Language Endorsement** is a separate, centre-assessed component; a
  private candidate needs a centre willing to assess it. Confirm early; it does not affect the
  9–1 grade but is reported on the certificate.
- **CS 8525 has no NEA** — written papers only. Spanish (2028) will need a centre that conducts
  the speaking test; note for next year.

Every checkpoint report or calendar answer that lands within 6 weeks of any of these carries the
reminder, in plain words, with the date.

## Output shape

Lead with the next dated thing and what to do about it. Then the list. Then, if a timetable
change is proposed, the diff as a table (day · time · was · becomes) and one question: "write it
from Monday <date>?" Never write and then ask.

## Fallback

Connector unavailable: give the calendar from the references, mark everything as unverified
against the tracker, and do not describe any timetable change as made.
