# Progress over time and the forecast — service contract

The specification the service is built against (`php/lib/progress.php`,
`php/lib/dashboard_progress.php`, `php/lib/mcp_progress.php`,
`Store::progressInputs`), the decisions taken where the design left room, and
what the numbers mean. The design deck is `design/progress-forecast/index.html`;
the skill that reads the tool is `skills/progress-forecast`.

Written against schema step 15, which adds `goal_pct` and `goal_date` to
`subjects`.

## 1. The measure

The unit is the **step**: one topic moving up one level. It is the same
weighting the headline percentage already uses (`STATUS_POINTS`: not started
and gap 0, developing 1, secure 2, exam-ready 3), so a subject with N topics
has 3N steps and coverage is steps done over steps possible. Forecasting in
steps makes the pace needed a whole number a week, and every step is one
visible event on a topic page with its evidence beside it.

Coverage on any past day is **replayed** from `topic_changes`: the points as
they stand, less every change dated after that day. Nothing is stored. A
change is dated by its session's date where it has one (sessions are logged
after the fact, so the wall-clock stamp can land a day late); a standalone
update is dated by when it was made. A voided session's changes still count,
as they do everywhere else: voiding is a note on the session, not a reversal.

The record starts on the first session or change; the series starts on that
day's Monday. Statuses set at the seed with no change row are the baseline.

## 2. The calendar

A **block** is a timetabled block, in the version in force on that date, that
resolves to exactly this subject (`tt_subjects_for`, so an alternating block
counts on the weeks it falls), is not `tracking: none`, and is not one of the
quiet kinds (retrieval, review, movement, break, lunch, group). A
multi-subject block counts for nobody, as `plannedBySubject` already decides.
An approved day off has no blocks. Weekends have whatever the timetable gives
them, which is normally nothing.

Blocks are the denominator of pace and the currency of the future: a holiday
week has no blocks and so gets no steps, without anyone having to model it.

## 3. The aimline

A straight line from the **baseline** — coverage at the end of the day before
the first tracked day — to the **goal**. The goal defaults to every step on
the subject's `exam_date`. The parent can set `goal_pct` (1–100) and
`goal_date` on `tracker_create_subject`; either alone changes only that half;
null puts it back. No exam date and no goal date means no aimline.

Each week is judged at its end (or today, for the current week) as `ahead` or
`N behind`, on the unrounded comparison. `behind_run` counts consecutive
weeks with blocks that ended under the line, latest first. The decision rule
is curriculum-based measurement's: two under is a warning, **four under is
the rule to change the teaching** (Fuchs, Fuchs & Hamlett 1990; Shapiro
2004).

## 4. Week by week

One row per ISO week from the series' first Monday to this week:

| Field | Meaning |
|---|---|
| `blocks` / `blocks_so_far` | Blocks the timetable gave the subject that week; and up to today, for the current week |
| `sessions` | Sessions logged (not voided) dated in the week |
| `touches` | Changes whose from and to are the same: evidence logged against a topic that stayed put |
| `ups` / `downs` / `net` | Steps gained, lost, and net |
| `opened` | Topics taught for the first time: not started or gap → developing or better |
| `end_pct` / `aim_pct` / `under` / `vs_aim` | Coverage and the aimline at the week's end |
| `flag` | `no_blocks` · `missed` (blocks, no sessions, week complete) · `slipped` (net loss) · `stalled` (sessions, no net gain) · `ahead` · `behind` · `moved` (no aimline) · `quiet` (current week, nothing yet) |

`stalled_run` counts consecutive weeks with blocks and no net gain (stalled,
missed or slipped), latest first. A week with no blocks neither stalls nor
breaks the run; nor does the current week while nothing has been logged in
it.

## 5. Pace and the cone

**Pace** is steps per block, one rate per week with blocks, over the last
eight such weeks (`PROGRESS_WINDOW_WEEKS`); the current week contributes its
blocks so far. `per_week` is the mean rate times the mean blocks a week;
`needed_per_week` is the steps remaining to the goal over the blocks the
timetable has before the goal date, times the same. Two plain projections sit
beside it: where the last two weeks' pace lands by the horizon, and where the
whole window's does.

The **cone** needs four weeks of pace (`PROGRESS_MIN_WEEKS`) and a horizon
(the goal date or exam date, whichever is later) ahead of today. It runs the
future 5,000 times (`PROGRESS_RUNS`), week by week, with two levels of
resampling:

1. draw n rates with replacement from the n past weeks (which weeks count);
2. for each future week, draw one rate from that set and add
   `round(rate × blocks)`, clamped to [0, 3N].

The outer draw carries the uncertainty in the rate itself, which with four
weeks of record is most of the uncertainty there is; the inner draw carries
the week-to-week noise. A single-level daily bootstrap on the same four weeks
gave an exam-day band of 86–100% where this gives 46–100%: the narrower one
is not more accurate, only more confident than the record allows.

Per future week the bands are the 5th, 15th, 50th, 85th and 95th percentiles.
For the goal and for "everything secure" (two thirds of the steps) the cone
reports the chance of reaching it by the horizon and the dates by which 50%
and 85% of runs have reached it. The generator is seeded by subject and
date (`mt_srand(crc32(slug|today))`), so the page holds still within a day
and a test can pin it. The cone is `provisional` until eight weeks
(`PROGRESS_SETTLED_WEEKS`).

What the cone does not know: that later topics may be harder than the ones
already taught, that exam-ready needs revision-phase work, and about holidays
nobody has booked. The page says so. Modelling the three transitions as
separate rates is the upgrade path once a term of data exists; the sampler is
one function and can be swapped without touching the chart.

## 6. The flow, what is in flight, cycle time, limits

The **cumulative flow** is the count of topics in each status at each week's
end, replayed the same way, plus the day before the first week. Read as any
CFD: the yellow band's width is work in progress, its length how long a topic
waits there, the green edge's slope the throughput.

**In flight** is every developing and gap topic with the date it last entered
that status (null and `seeded` when it has never changed), its age, and the
touches since. Sorted by touches, then age.

**Cycle time** is days from a topic's first opening (0 → 1+) to secure
(< 2 → 2+), median over the topics that made it; topics secured without an
opening in the record are reported separately from the series' first Monday.

**Natural process limits** (Wheeler's XmR) on the weekly net steps — mean and
±2.66 × mean moving range, lower floored at zero — appear once six complete
weeks with blocks exist (`PROGRESS_LIMITS_WEEKS`). Fewer would flag nothing.

## 7. Signals

`progress_signals()` reads the model and returns a list, most serious first,
each `{code, level, text}`:

| Code | Level | Fires when |
|---|---|---|
| `stalled` | warn / alert | `stalled_run` is 2 / 3 or more |
| `behind_aimline` | warn / alert | `behind_run` is 2–3 / 4 or more |
| `off_target` | warn / alert | the cone's chance of the goal is under 50% / 25%; before a cone, delivered pace under needed pace (warn) |
| `no_new_topics` | warn | 3 or more consecutive weeks with blocks and `opened = 0`, with unopened topics remaining |
| `regressions` | info / warn | 1 / 2 or more topics moved down in the last four weeks |
| `stuck_topics` | warn | developing, 3 or more touches, 21 days or more in status |
| `idle_developing` | info | developing, no touches since entering, last touched 42 days or more ago |
| `wip_high` | info | developing count ÷ weekly steps is 4 weeks or more |
| `too_early` | info | fewer than four weeks of pace |
| `provisional` | info | four to seven weeks of pace |
| `on_track` | info | nothing else fired |

The levels are the service's. The skill tells the model what each asks of it.

## 8. Surfaces

- **`/s/{slug}/progress`** — the page. The line, the aimline, the flow and
  the week table are public like the rest of the subject page; the cone, the
  exam-day and everything-secure cards, the in-flight table and the
  off-target signal are shown to the signed-in parent, because a probability
  of missing the goal is his to read. Every chart has a "Chart data" table.
  The hover on the burn-up reads the week under the cursor.
- **The subject page** — one line under "Spec conquered": the cone's exam-day
  figure (parent), the aimline reading, a stall of two weeks or more, and the
  link.
- **The index** — each subject row carries the aimline reading. No cone: it
  would cost a simulation per subject on the page she opens most.
- **The week page** — each subject card says the aimline at the week's end,
  which side of it the week ended, and touches against steps.
- **`tracker_progress_forecast(subject, format?, weeks?)`** — the whole model
  as text (signals first, then pace, cone, weeks, in flight, chart data) or as
  JSON. Read-only.

## 9. Tests

`deploy/progress-test.php`: eight topics, a four-block week, a week off,
three points in time under the frozen clock (three, seven and twelve weeks
in), the goal override, determinism, the tool's two formats, the page for the
parent and for everyone else, and the entry points. The smoke test covers the
route, the 404 and the tool over the wire.
