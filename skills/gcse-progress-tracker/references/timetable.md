# The weekly timetable — contract between the tracker and every skill

The Education Tracker holds the student's weekly timetable and judges, from the evidence it
already stores, whether each block actually happened. This file is the contract. The tutor skills
read it; the dashboard renders it; the parent's weekly review is driven by it.

Timezone for everything below: **Europe/London**. Days are Monday–Friday.

## What a block is

One row of the stored timetable:

| Field | Meaning |
|---|---|
| `id` | stable integer; sessions, attempts and practice runs may name it |
| `weekday` | 1 = Monday … 5 = Friday |
| `start`, `end` | `HH:MM` |
| `label` | what she sees, ≤ 40 chars — "Maths — new topic", "Timed handwritten practice" |
| `kind` | `movement` · `retrieval` · `teach` · `practise` · `timed_handwritten` · `exam_practice` · `coding` · `writing` · `consolidate` · `spanish` · `review` · `break` |
| `subjects` | zero or more subject slugs the block belongs to. `[]` for movement/break. Several slugs for a mixed retrieval or a rotating block |
| `tracking` | `evidence` — done when the tracker holds evidence (below) · `self_report` — done when someone ticks it through chat · `none` — shown, never judged (breaks, lunch) |
| `alternate` | optional: `{ "odd": ["maths"], "even": ["computer-science"] }` by ISO week, for the Thursday deep block |
| `note` | one line of guidance shown on hover/tap, e.g. "rotate: Lang Q5 / Lit essay / Maths section" |

The timetable is versioned: `tracker_set_timetable` replaces the whole set and stamps a
`valid_from` date, so past weeks are judged against the timetable that was in force then.

## Days off — booked and approved, never assumed

There is no fixed holiday list. Any non-study day is a **day off** record: a date range with a
`kind` (`holiday` · `day_off` · `sick` · `other`), a `reason`, who requested it and a `status` of
`requested` · `approved` · `declined`.

- **Anyone can request** through any chat: `tracker_request_day_off(date_from, date_to, reason,
  requested_by: student|parent)`. A tutor skill relays the student's request verbatim and says it
  goes to her parent; it never approves.
- **Only the parent approves or declines**, through `tracker_decide_day_off(id, decision, note?)`
  from the parent's tracker chat. A parent-made request is approved on creation.
- **Judging:** blocks inside an *approved* day off are never `missed` — the day renders as a
  "day off" ribbon with its label. A *requested* day off is shown as an outlined "requested —
  awaiting Dad" ribbon and its blocks are **still judged normally** until the decision; a
  *declined* one is kept, shown nowhere on the board, listed by `tracker_days_off`.
- Days off are never deleted; a wrong one is declined or un-approved with a note.

Keep this honest — a false "missed" teaches her the board lies, and then it is useless; a day off
approved after the fact is fine when it is true (she was ill on Tuesday), and the note says so.

## How a block is judged — the rule every skill must know

For a block with `tracking: evidence`, on a given date:

1. **Done** if any non-void **session** (`tracker_log_session`), **attempt**
   (`tracker_log_attempt`) or **practice run** (`tracker_log_practice`) exists for one of the
   block's `subjects` **dated that day**. Explicit linking beats inference: if the record carries
   `block_key`, it counts for that block only; otherwise the earliest unclaimed matching record on
   the day is attached to the earliest unclaimed matching block.
2. **Now** if today, and the current time is inside the block.
3. **Pending** if today and the block has not ended yet (no evidence yet — not a failure).
4. **Missed** if the date is past (or the block's end time has passed today), no evidence, not a
   holiday, not excused.
5. **Excused** if the parent has called `tracker_excuse_block(date, block_key, reason)`. Shown
   greyed with the reason, counted in neither done nor missed.
6. **Day off** if the date falls inside an approved day-off record (see below): not judged at all.
7. **Upcoming** for future dates.

`kind`-aware refinement (cheap, worth having): a `timed_handwritten` block is only satisfied by an
**attempt** (any `kind`, including `check`) or a session whose record names it (`block_key`), not
by a bare practice run. A `retrieval` block is satisfied by a practice run with a
`source` starting `retrieval_` or by a session carrying `block_key`. An `exam_practice` block is
met only by a sat exam-practice test — a fourth evidence type, `exam`, which the service writes
itself when the timer ends the sitting; a test still open past its deadline counts too, so the
board is right before anyone reloads the page. Everything else is satisfied by any of the three.

Evidence logged for a subject on a day with **no** block for that subject appears as an **extra**
row on the day — visible, counted toward the week's total, never hidden.

**Whether a session needs a lesson review** (`review_required` on the session row) is derived
by the server at log time, never stated by the caller:

- a session logged against a block takes the `review_required` flag of that block's kind, a
  column on `block_kind_rules` — teaching kinds require one; retrieval, movement, review and
  Spanish maintenance kinds do not;
- a session with no block (an *extra*) requires one when `duration_minutes` is 30 or more;
- everything else does not.

A required review missing from `tracker_log_session` does not refuse the call — the block is
still done — but the response names it, `tracker_today` shows the block as *review missing*,
and it sits in the audit queue until `tracker_save_lesson_review` supplies it. The tutor skills
close through `lesson-review`, which sends the review in the same call.

`self_report` blocks (movement, the Friday review) are ticked through chat: a tutor skill calls
`tracker_tick_block(date, block_key, note?)` when she says she did the walk, or the parent's
review chat ticks the review block when it is done. A tick has a `by` field (student/parent) and a
note; it is never inferred.

## What the tutor skills must do

**Opening — every session, every subject.** After `tracker_review_queue`, call
`tracker_today()`. It returns today's blocks with status, the current or next block, and any
missed block earlier in the day. Then:

- If the session she is asking for **matches a block** on today's timetable (same subject, or a
  rotating/mixed block that includes it): say so in one line — "this is your 9:45 maths block" —
  and run it. Note the `block_key`; pass it when logging.
- If it **doesn't match** (asks for maths on a day with no maths, or at a time that belongs to
  another subject): still help — the timetable is a plan, not a gate — but say in one line what
  the timetable expected, and when logging **do not** attach the work to a different block. It
  will show as *extra*, and the expected block will show as *missed* unless she also does it.
  Never quietly re-label work to make the board look clean.
- If `tracker_today` shows a **missed block earlier today** for this subject, mention it once,
  without reproach: "your 9:45 block shows as missed — this session will count as it if we do
  the same work now" (which is true: same subject, same date, block_key passed).
- If it is a **non-study day or a holiday**, say so and help anyway; log with today's date; it
  appears as extra.

**Closing — every session that produced work.** Log with the **actual date the work was done**
(`date:` on `tracker_log_session`; `played_at` on practice; `sat_on` on attempt papers). Work
done today is logged today. Work she reports from yesterday is logged with yesterday's date,
plainly stated in the summary. Pass `block_key` when the session ran against a block. A session
without a log is a missed block, by design — remind her once: "not logged yet — say 'log it' and
it'll show as done".

**Never:** log an empty or ceremonial session to tick a block; change a session's date to move
it onto a block; excuse a block yourself; describe a missed block as anything but missed.

**A stop request is a log request — mandatory, no exceptions.** When she says anything that
means she wants to stop — "wrap up", "end the session", "log it", "record the session", "log
this", "I need to go", "that's enough for today", "finish here", "can we stop" — the very next
action is the log call, in that turn. Not after the exit ticket, not after "just this last
question", not after a recap, not after asking whether she is sure: the session shape yields to
the stop. Deciding when she is finished is not the model's job; teaching and recording are.
Whatever was cut short is recorded as unfinished — `next_steps` names the exact task and says it
is finished first next time, with the same questions — and the log's figures are what actually
ran. An incomplete session is still a session. An unlogged session did not happen.

**"Logged" means the tool ran.** *"logged — you can see it at …"* may be said only after a
`tracker_log_session`, `tracker_log_practice` or `tracker_log_attempt` call in the current turn
returned without error. A summary typed into chat, a fallback block, or a description of what
*would* be logged is not a log and is never introduced as one. The fallback block exists for one
case only: a `tracker_*` call was attempted this turn and failed, or the `tracker_*` tools are
absent from the tool list. Never reach for it instead of trying the call.

**The week's plan reaches a block through the review queue.** The Saturday synthesis writes one
week plan per subject that has a taught block the following week (Part 14), and
`tracker_review_queue` prints it as `### this_week` beneath `last_review`, with `TEST THIS WEEK`
when a test is set for the subject. The block that opens on it is judged exactly as before — by
the evidence logged against it — but the plan says what to teach and how, and the queue records
that it was printed so the synthesis's drift can say whether it was read. When the week changes
and no newer synthesis exists, the last plan prints with `stale: true`.

**Closing a taught session is a review as well as a log.** A session whose block kind requires a
review is closed by `lesson-review` (Mode A), which writes the `review` argument into the same
`tracker_log_session` call. A tutor skill that logs such a session bare leaves it *review
missing* on the board and in the audit queue.

## What the parent-facing tracker skill must do

At the Friday review (and whenever asked "how did the week go"):

1. `tracker_week_status()` — the whole week, block by block.
2. Report **done / missed / excused / extra** counts and name every missed block with its day
   and subject. No softening, no streaks, no "nearly".
3. Ask, don't assume, whether any missed block should be excused. Excuse only with a stated
   reason via `tracker_excuse_block`; the reason is shown on the board. Then
   `tracker_days_off(status: "requested")` — put every pending request to the parent, one line
   each, and record the decision with `tracker_decide_day_off`.
4. Report hours by subject against the target split (Maths ~5 · Lit ~4.5 · Lang ~3.5 · CS ~3.5 ·
   Spanish ~1.5–2) from block durations actually done.
5. Note handwriting minutes sustained and blanks from the week's timed work.
6. Tick the review block itself with `tracker_tick_block(by: "parent")`.

## Tools (added in the timetable release)

| Tool | Arguments | Use for |
|---|---|---|
| `tracker_today` | `date?` | Today's blocks with status; current/next block; missed so far. **Opens every session after the review queue** |
| `tracker_week_status` | `week?` (ISO `2026-W37`), `date?` | Every block of the week with status, evidence ids, extras, hours by subject |
| `tracker_get_timetable` | `valid_on?` | The stored blocks in force on a date |
| `tracker_set_timetable` | `blocks[]`, `valid_from?`, `note?` | Replace the timetable (versioned). Parent-only by convention |
| `tracker_days_off` | `from?`, `to?`, `status?` | Every day-off record in range, with status and who asked |
| `tracker_request_day_off` | `date_from`, `date_to`, `reason`, `requested_by`, `kind?` | Book a day off. Student requests wait for the parent; parent requests are approved at once |
| `tracker_decide_day_off` | `id`, `decision` (approve/decline/unapprove), `note?` | Parent-only. The decision and note are kept |
| `tracker_excuse_block` | `date`, `block_key`, `reason` | Parent excuses one block on one date; reason shown. `reason: null` un-excuses |
| `tracker_tick_block` | `date`, `block_key`, `by`, `note?` | Self-report completion of a `self_report` block |

`tracker_log_session` also takes `review` (the lesson review, see `tracker-service.md`); whether
one is required is derived as above and reported back.

`tracker_log_session`, `tracker_log_attempt` (per paper) and `tracker_log_practice` (per run)
each gain an optional `block_key`. `tracker_log_session` also gains optional `duration_minutes`;
when present and under half the block's length the board shows the block as done with a
"short" mark rather than a full tick.
