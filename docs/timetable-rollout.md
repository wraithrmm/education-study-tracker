# Rolling out the weekly timetable

Two things have to happen **after** this is deployed, because both go through
tools that do not exist on the live service until then. Neither is code.

## 1. Seed the timetable

`docs/timetable-seed.json` is the timetable as agreed: 36 blocks, Monday to
Friday, 09:00–15:20, `valid_from: 2026-09-07`. The seed's `id` is the
`block_key` — the identity that has to stay stable across every later re-cut,
so that an excusal or a tick written against block 16 still resolves after the
week is re-cut.

Ask Claude, from the parent's chat:

> Read `docs/timetable-seed.json` and write it to the tracker with
> `tracker_set_timetable`, `valid_from` 2026-09-07. Show me the diff first.

It should report 36 blocks added. Check `/` afterwards.

**Do not seed any holidays.** The seed deliberately contains none: days off are
booked through `tracker_request_day_off` once the tools are live, so that every
one of them carries who asked, who decided, and why.

## 2. Correct the Spanish exam date

The `spanish` row says `exam_date: 2027-06-09`. That is wrong, and the wrong
date drives a countdown badge that implies an exam she is not sitting. Spanish
is foundation for a **2028** entry, not a 2027 one.

> Set the Spanish exam date to 2028-06-01 and note that it is a placeholder.

`tracker_create_subject` now amends in place, so this is one call with just the
slug and the fields being changed — the hundred topics and the strand map are
not sent and are not touched.

**`2028-06-01` is a placeholder.** The 2028 AQA series dates are not published
yet. The note on the row says so, and it needs replacing with the real date
once AQA publishes it. Until then every projection off that date is indicative
only.

## 3. The board

There is one board: the week strip, five columns of thin chips with today's
lifted off the paper. Two other designs were drawn and compared in
`design/timetable-abc/` before it was chosen; neither was kept, and neither
exists in the code.

Signed in as the parent (`/login`, `TRACKER_PASSWORD`), each day header offers
a day off and each status mark opens a small menu — done anyway, skipped,
clear. Signed out, the board is read-only.

## 4. Lunch and the Thursday group (September 2026)

Two block kinds were added after the first cut: `lunch` and `group`. Both are
untracked, like `break`, but the board labels them — with the start and end
time — instead of drawing them as a rule between chips. A `break` is still a
rule: it is not a slot anyone looks for. The seed carries the change:
blocks 6, 15 and 32 are `lunch` and run for an hour, with the afternoon
blocks after them fifteen minutes later than before; block 37 is **Group**,
every Thursday 11:45–15:20. Thursday's Spanish review (25) moves to
11:30–11:45 to make room and the Literature block before it (24) ends at
11:30. Thursday has no lunch block: lunch is at the group.

The live timetable is data, so this too has to be written **after** the deploy
that carries the new kinds — the service in force before it refuses them.
The live timetable is version 3, re-cut since the seed, so start from what is
there, not from the file:

> Read the timetable with `tracker_get_timetable`. Keep every block and its
> block_key. Change the three "Lunch + outside" blocks (6, 15, 32) to kind
> `lunch` and make each an hour, 12:00–13:00, moving every block after it
> that day fifteen minutes later (Monday 7, 8, 9; Tuesday 16, 17; Friday 33,
> 34, 35), so those days end at 15:00. On Thursday, end block 24 at 11:30,
> move block 25 to 11:30–11:45, and add block 37, kind `group`, label
> "Group", 11:45–15:20, no subjects, tracking `none`. Write it with
> `tracker_set_timetable`, `valid_from` the coming Monday. Show me the diff
> first.

Version 3's Thursday runs 09:00–12:00 with block 25 at 11:45–12:00, so the
Group block overlaps it until 25 is moved; the tool will refuse the write and
name the clash if that step is missed.

## Still outstanding

`skills/gcse-progress-tracker/references/timetable.md` — the block contract —
was not available when this was built, so it has not been copied into the repo
as `docs/timetable.md` as intended. The judging rules here were implemented
from the brief instead. When that file turns up, copy it in and check
`Store::judgeWeek()` against it: the binding order, the `kind` refinements and
the status precedence are the parts worth reading twice.
