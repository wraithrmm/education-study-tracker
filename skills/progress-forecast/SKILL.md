---
name: progress-forecast
description: >
  Read and act on the Education Tracker's progress forecast for a subject — the aimline, the
  cone and the stall signals behind /s/{slug}/progress — through tracker_progress_forecast.
  Use whenever the parent asks whether she is on track, how progress compares with a few weeks
  ago, whether progress has stalled, what she will have covered by the exam, whether new
  topics are being opened, or why a topic has not moved; and once per subject inside every
  Friday weekly review and Saturday synthesis, so that a three-week stall, an off-target
  forecast or a run of weeks with no new topic is considered every week rather than noticed
  in May. Trigger on "is she on track", "will she be ready for the exam", "has maths
  stalled", "what does the forecast say", "how does this compare to three weeks ago", "is she
  opening new topics", "what's stuck". Do not use it to change a topic status — that is
  gcse-progress-tracker — or to re-cut the timetable — that is term-planner.
---

# Progress forecast

One tool, `tracker_progress_forecast(subject)`, gives the whole picture for a subject in the
unit the page uses: a **step** is one topic moving up one level (not started or gap 0,
developing 1, secure 2, exam-ready 3), so a subject with N topics has 3N steps and the
headline percentage is steps done over steps possible. Everything below is computed from the
record on every call; nothing is stored, so a corrected status corrects the history.

The page for the parent is `/s/{slug}/progress`. Link to it rather than describing the chart.

## When to call it

- **Every Friday weekly review** (`parent-weekly-review`): once per tracked subject, after
  `tracker_week_report`. Read the SIGNALS block and carry anything at WARN or ALERT into the
  review's "Slipped" line and the subject's carry-forward.
- **Every Saturday synthesis** (`weekly-synthesis`): once per subject that was taught, before
  writing Part 13 (priorities) and Part 14 (week plans). A stall or a no-new-topics run is a
  priority in its own right; an off-target forecast is Part 18's big picture.
- **On demand**: any of the trigger phrases above. Answer from the tool, not from memory or
  from the session prose.

Call it with `format: "json"` only when you need the numbers programmatically (a chart, a
comparison across subjects). The text form is what to read and quote.

## How to read it

The text comes in this order. Read the first two blocks before anything else.

1. **Header** — steps done of steps possible and the percentage; the same figure three weeks
   ago and on the first tracked day; what the aimline says today. The goal line says whether
   the goal is the default (every step, exam day) or one the parent set.
2. **SIGNALS (read these first)** — one line per signal, most serious first, each with a level
   and a code. The codes and what to do with them are in the table below.
3. **PACE** — steps a week over the window (the last eight weeks with timetabled blocks, or
   fewer while the record is younger), the pace needed to reach the goal over the blocks that
   remain, and two plain projections: where the last two weeks' pace lands, and where the whole
   window's pace lands. Quote the needed-versus-delivered pair whenever the question is "is
   she on track": it is the plain fact the cone sits on top of.
4. **THE CONE** — the forecast, or why there is none. Exam day as a most-likely figure with a
   likely band (15th–85th percentile) and a possible band (5th–95th). The goal and the
   "everything secure" milestone each with a chance of being reached by the horizon and the
   date it is typically reached. **Never quote the most-likely figure without its band**, and
   say "provisional" when the tool does: with four weeks of record the band is wide because
   four weeks are four weeks, and it narrows on its own as weeks are added.
5. **WEEK BY WEEK** — one row per ISO week: blocks the timetable gave the subject, sessions
   logged, touches (status changes where nothing moved), topics opened (taught for the first
   time), net steps, coverage at the week's end, the aimline at the week's end, and a reading:
   `ahead` / `N behind` (which side of the aimline the week ended), `stalled` (blocks and
   sessions, no steps), `nothing logged` (blocks, no sessions, week complete), `slipped` (net
   loss), `no blocks` (a holiday or before the timetable). Touches are not wasted — a topic
   needs several independent showings to go secure — but a week of many touches and few steps
   means consolidation with nothing new opened.
6. **IN FLIGHT** — every developing and gap topic with how long it has been in that status, how
   many times it has been touched without moving, and its loose end. "Since the seed" means it
   was set at the diagnostics and has not changed since. Below it, the cycle time: how many
   days a freshly opened topic typically takes from first taught to secure.
7. **CHART DATA** — the weekly series (actual, aimline, and the cone's low / most likely /
   high for future weeks) and the topics-by-status counts at each week end. This is the
   picture as numbers; use it for a comparison the parent asks for ("where was she a month
   ago") and for anything you would otherwise eyeball.

## The signals, and what each one asks of you

| Code | Level | It means | Do |
|---|---|---|---|
| `stalled` | WARN at 2 weeks, ALERT at 3+ | Consecutive weeks with timetabled blocks and no net steps: sessions that moved nothing, or nothing logged. A holiday week neither stalls nor breaks the run. | Name the weeks and the cause the record holds (sessions cut short, blocks unlogged, touches with no movement). Three running is the parent's decision point: put it to him plainly and propose one change — a new topic opened, a block re-cut through `term-planner`, or a stuck topic adjudicated through `gcse-progress-tracker`. |
| `behind_aimline` | WARN at 2, ALERT at 4 | Consecutive weeks that ended under the straight line from the first tracked day to the goal. Four is the curriculum-based-measurement rule: the teaching is not enough and should change, not wait. | At two, say so and watch. At four, the review's "Slipped" line carries it and the synthesis makes the change a priority. Do not soften it: the line is arithmetic. |
| `off_target` | WARN below 50%, ALERT below 25% | The cone's chance of reaching the goal by its date; or, before there is a cone, delivered pace below needed pace. | Quote needed against delivered and the band. Ask whether the goal is right (the default is every step on exam day, which is the strictest reading) — a parent-set goal goes on `tracker_create_subject(goal_pct, goal_date)` — and whether the timetable gives the subject enough blocks. |
| `no_new_topics` | WARN | Three or more weeks with blocks and no topic taught for the first time, while topics remain unopened. | The syllabus is not being advanced. Name what is unopened (the IN FLIGHT gaps and `tracker_get_state(status: ["notstarted","gap"])`) and propose the next one to open. Consolidation is not a reason to stop opening topics; the cone assumes both continue. |
| `regressions` | INFO for one, WARN for two or more | Topics moved down in the last four weeks. | Say which and when. Two or more in a month is a retention pattern for the synthesis, not a status question. |
| `stuck_topics` | WARN | Developing for three weeks or more and touched three or more times without moving. | These are the topics to decide about: reteach differently, split, or accept as developing and move on. Hand the decision to `gcse-progress-tracker` with the touches as evidence. |
| `idle_developing` | INFO | Developing, untouched for six weeks or more. | They are ageing in flight. Either schedule them or note that they are parked. |
| `wip_high` | INFO | Topics developing divided by weekly steps is four weeks or more of work in flight. | Opening more before these close makes each wait longer (Little's law). Prefer closing to opening this week. |
| `too_early` | INFO | Fewer than four weeks of pace; no cone. | Report the line and the aimline only. Do not extrapolate by hand. |
| `provisional` | INFO | Four to seven weeks of pace. | Say "provisional" beside any cone figure. |
| `on_track` | INFO | Nothing above fired. | Say so in one line and move on. |

Levels are the tool's, not yours: do not promote an INFO to a warning because the prose of a
session sounded bad, and do not demote an ALERT because the week had a reason. State the
reason beside the alert instead.

## Saying it to the parent

- Lead with the signal, then the number, then the band: "Maths has stalled three weeks
  running (W40–W42: eleven touches, no steps). At the last eight weeks' pace the cone gives a
  31% chance of exam-ready everywhere by 14 May, most likely 85% with a likely band of
  54–100%, provisional."
- Steps are the unit; say "steps", not "points" or "percent of a percent". One step is one
  topic up one level, and the parent can check any step on the topic page.
- Compare like with like: this week's end against the aimline at this week's end; today
  against three weeks ago (both in the header).
- The cone does not know that later topics may be harder, that exam-ready needs revision-phase
  work, or about holidays nobody has booked. Say the first two when the figure looks generous;
  say the third when a holiday is coming and is not in `tracker_days_off`.
- Never invent a forecast the tool did not give. If the cone is absent, the answer is the
  needed-versus-delivered pace and "too early for a cone".

## Connector unavailable

Say so, and stop. There is no fallback block for this skill: the numbers cannot be re-derived
in chat from session prose without the timetable and the audit trail, and a forecast made up
in conversation would be presented back as if the tracker had said it.
