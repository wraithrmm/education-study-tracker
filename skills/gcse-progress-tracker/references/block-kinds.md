# Block kinds — who owns each, what it looks like, how it progresses

Companion to `timetable.md` (the contract). Every tutor skill reads this before running a kind
it has not run before. The `kind` values are those on the stored timetable; `review_required`
is derived by the server from the kind, never stated by the caller.

| `kind` | Owner skill | Satisfied by | Shape | Progression |
|---|---|---|---|---|
| `movement` | none — `self_report` | `tracker_tick_block` when she says she did it | walk / exercise before the first study block | fixed |
| `break` | none — `tracking: none` | never judged | — | — |
| `retrieval` | `retrieval-block-session` | practice run with `source` starting `retrieval_`, or a session carrying `block_key` | 5–12 items, interleaved, marked together, no teaching | item difficulty rises per return; failed-twice items come back scaffolded |
| `teach` | subject tutor skill (`maths-tutor-session`, `english-tutor-session`, `cs-tutor-session`) | session with `block_key` (+ lesson review) | starter → teach → practise → exit ticket | scaffold withdrawn over sessions; queue decides the topic |
| `practise` | subject tutor skill | session or practice run | mixed practice on taught topics, 1–2 earlier topics interleaved | harder versions per return |
| `consolidate` | subject tutor skill | session or practice run | revisit the week's 🟡 topics; no new content | — |
| `coding` | `cs-tutor-session` | session with `block_key` | build/fix a program, parts taught first, she types | typed → handwritten programs by the transition phase |
| `writing` | `english-tutor-session` | session with `block_key` | one paragraph per exchange until Phase 3; typed drafting | paragraph → whole piece (Phase 3, from Mar 2027) |
| `timed_handwritten` | subject's **marker** skill logs it; tutor skill runs it (Tue 13:00 rotation) | an **attempt** (`tracker_log_attempt`, `kind: "check"`, with `block_key`) — a bare practice run does not count | one task under exam timing, on paper, photographed, marked same day, minutes sustained recorded | 20–25 min, +~5 min every 2–3 weeks toward full paper length |
| `exam_practice` | `exam-practice-session` in her own project; `exam-question-generator` builds the paper beforehand in the parent's | a **sat test** on the portal — the service records it when the timer ends, so nothing needs logging to tick the block | mixed-subject timed paper at `/exam/{id}`: questions hidden until Start, one server-run clock, no pause, auto-submit at zero, marked afterwards into one `check` attempt per subject | 45 min → 60 → 90 as stamina allows; one deliberately hard question per paper, to practise leaving it |
| `spanish` | `spanish-maintenance-session` | practice run (+ session) with `block_key` | 10–30 min: vocab retrieval, short listening/reading, one grammar point | little-and-often; cut first on an overrun |
| `review` | `parent-weekly-review` — `self_report`, parent only | `tracker_tick_block` from the parent's chat | Friday 14:15 sweep of all subjects | — |

Rules that cut across kinds:

- A block naming **more than one subject** is a retrieval block whatever its label; load
  `retrieval-block-session`.
- The **label and note on a block are for the board and the parent**. They are translated into
  plain words when spoken to her (study principle 12), never read aloud.
- Only the parent excuses a block (`tracker_excuse_block`); a missed block stays missed.
- The **exam-practice block is ticked by the sitting itself**, not by a session. Any record for
  `exam-skills` that day binds the block, but the shape is met only by a sat test — a session
  alone reads `done_shape_unmet`. So the technique session written after marking carries **no
  `block_key` and no `duration_minutes`**: the sitting is already the block's evidence and its
  hours, and a session claiming the block would take it and double the hours.
