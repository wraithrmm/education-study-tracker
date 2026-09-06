# Rolling out the weekly timetable

Two things have to happen **after** this is deployed, because both go through
tools that do not exist on the live service until then. Neither is code.

## 1. Seed the timetable

`docs/timetable-seed.json` is the timetable as agreed: 35 blocks, Monday to
Friday, 09:00–15:00, `valid_from: 2026-09-07`. The seed's `id` is the
`block_key` — the identity that has to stay stable across every later re-cut,
so that an excusal or a tick written against block 16 still resolves after the
week is re-cut.

Ask Claude, from the parent's chat:

> Read `docs/timetable-seed.json` and write it to the tracker with
> `tracker_set_timetable`, `valid_from` 2026-09-07. Show me the diff first.

It should report 35 blocks added. Check `/` afterwards.

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

## 3. The design switch

`meta.timetable_design` is set to `a` by the migration — the week strip, which
is what was approved to run first. All three designs are built and stay behind
the switch until someone says otherwise:

- `?design=a` — week strip (the stored default)
- `?design=b` — now / next, then the week
- `?design=c` — the register

`?design=` overrides the stored setting for one request only, so a design can
be tried without changing what anyone else sees. To change the default, set
`meta.timetable_design`.

## Still outstanding

`skills/gcse-progress-tracker/references/timetable.md` — the block contract —
was not available when this was built, so it has not been copied into the repo
as `docs/timetable.md` as intended. The judging rules here were implemented
from the brief instead. When that file turns up, copy it in and check
`Store::judgeWeek()` against it: the binding order, the `kind` refinements and
the status precedence are the parts worth reading twice.
