---
name: retrieval-block-session
description: Run any retrieval block on the timetable — the daily warm-ups, the mixed cross-subject quiz, a single-subject quotation or vocabulary retrieval slot — for whatever subjects the tracker holds, weighted by the student's recent performance. Use this skill whenever the block being run has kind `retrieval`, whenever a block names more than one subject, whenever someone asks to be quizzed, tested, warmed up or "started off", and whenever a subject tutor skill is about to open a session with its starter. Owns item selection, interleaving order, the blanks script and the logging that ticks the block; never teaches new content. Do not use it for a taught session — that is the subject's tutor skill; do not use it for a timed paper — a past paper is the subject's marker, and the Wednesday exam-practice block is exam-practice-session.
---

# Retrieval Block Session

The shared engine for every `retrieval` block, for every subject, now and for subjects that do
not exist yet. It hardcodes **no subject**: everything it needs comes from the timetable block
and from the Education Tracker at <https://education.rmmann.co.uk> (MCP connector, `tracker_*`).

Companion: `gcse-progress-tracker` — its `references/study-principles.md` holds the shared
teaching rules this skill applies (principles 1, 5, 6 and 7 are the load-bearing ones here) and
its `references/block-kinds.md` says which skill owns which block kind. Read both before the
first retrieval block of a conversation.

## What retrieval practice is, and why these blocks exist

Retrieval practice means recalling something from memory with the answer *not* in front of you.
The act of recalling is what strengthens the memory — it is the learning event, not a check on
learning that happened elsewhere. Re-reading notes feels productive and is close to worthless;
being asked and struggling to answer feels harder and works. [strong; comparable effect size in
ADHD populations]

**Spacing** is the schedule that retrieval runs on: the same item comes back at widening
intervals — days, then a week, then a fortnight, then a month. Each successful recall after a
gap buys a longer gap. [strong]

**Interleaving** is the order items come in: mixed, so that consecutive items are unlike each
other. Its purpose is that the student has to *decide what kind of problem this is* before she
can answer it — which is exactly what an exam demands and what a blocked, one-topic-at-a-time
drill never asks for. Blocked practice produces better performance in the session and worse
performance a week later; interleaved practice does the reverse. [strong in maths, moderate
elsewhere]

So a retrieval block is not a warm-up in the social sense and not a test. It is the single
highest-yield twenty minutes on the timetable, and it is where the tracker's status record gets
its independent, unaided evidence.

## Which blocks this skill owns

Take the block from `tracker_today()`. This skill runs it if **either**:

- `kind` is `retrieval` — whatever its subjects, one or many; or
- the block names **more than one subject** and is not a `teach`, `coding`, `writing` or
  `timed_handwritten` block.

A subject tutor skill's own opening starter is also a retrieval event and follows the selection,
interleaving and blanks rules below — but it is logged as part of that session, not separately.

If the block has a single subject (a quotation-retrieval or vocabulary-retrieval slot), run it
here anyway: the machinery is the same, the item pool is just narrower.

## Opening — two calls, and no assumptions about which subjects exist

```
tracker_today()          # the block, its subjects, its block_key, its minutes
tracker_week_report()    # every subject's recent movement, evidence, queue tops, practice runs
```

`tracker_week_report` is the right opener for a mixed block: one read gives each subject's topic
movement with the evidence behind it, the top of each review queue and anything sat or practised
— which is precisely the "recent performance" this block must be built from. Do not call
`tracker_review_queue` once per subject unless the week report is thin and you need depth on one
of them.

If a subject appears on the block that you do not recognise, do not guess: `tracker_list_subjects`
returns the slugs, targets and topic counts. Never assume the five subjects on the board today
are the only ones the tracker will ever hold.

If the connector is unavailable — a `tracker_*` call was attempted this turn and failed, or the
tools are absent; never assumed — say so in one line, run the block from what she can tell you,
and end with the Fallback Update Block. Never claim a block was ticked that you did not see
recorded.

## Building the item set — recent performance decides, nothing else

**How many.** Roughly one item per 2 minutes of block, capped at 14 and floored at 5. A 15-minute
warm-up is 5–8 items; a 35-minute mixed quiz is 10–14.

**How they are shared out.** Every subject named on the block gets at least 2 items. Beyond that,
weight toward the subject with the most unstable recent evidence — a demotion this week, a
failed ageing check, a topic taught two days ago — not toward the subject with the most topics.

**What each item is drawn from**, in priority order. Aim for a mix across these five, not a run
of one:

1. **Failed or blank in the last fortnight.** The strongest claim on a slot. An item she got
   wrong comes back at the next retrieval block, and again after that, until it lands. Say
   nothing about it being a repeat.
2. **Ageing secures due a check** — the review queue names them. A pass here is what carries a
   🟢 toward 🔵; a fail is what demotes it.
3. **Loose ends** — the `watch` notes on otherwise-secure topics. The note usually names the exact
   question to ask; ask that question, not a softer one.
4. **Taught in the last week and still 🟡** — one clean unaided question to see whether it held.
5. **A long-untouched 🟢** — one, to keep the spacing schedule honest across the whole syllabus.

**Progression across sittings.** The same topic does not get the same difficulty forever:

- First return after teaching: the plainest version of the question.
- Second: numbers, wording or context changed so pattern-matching does not carry it.
- Third and after: the version that actually appears in the exam — harder numbers, negative
  values, an extra step, the wording that traps.
- An item failed **twice** comes back *scaffolded* — split into two questions, or with the first
  line given — so the third attempt succeeds, and the scaffold is withdrawn at the next sitting.
  Errorless build-up, never the same failure three times running.
- An item passed unaided at the hardest version, ≥ 3 weeks after it secured, is a proposed
  🔵 — proposed, not applied. Adjudication belongs to `gcse-progress-tracker`.

**Order — this is the interleaving, and it is the point of the block.** Never two consecutive
items from the same subject on a mixed block; never two consecutive items on the same topic or
method on a single-subject one. Do not group, do not warn her what is coming, do not label the
items by topic. She should have to work out what each question *is* before she can answer it. If
the block ends up in subject order because that was easier to write, it has not done its job.

Mark calculator/non-calculator, or the equivalent constraint in that subject, per item where it
applies.

## Running it

- One question per message. Wait for the answer.
- **Cues name the skill or topic, never a code (study principle 12).** "The structure question:
  what's the one effect you name?" — not "Q3", not "R3", not "A4". Not labelling items *by
  topic* (above) means not announcing the topic before she works it out; it does not mean using
  a code she cannot decode. A cue she cannot decode is a blank, not a retrieval.
- Low stakes, out loud: "five questions, no drama, we mark them together at the end."
- **Blanks (study principle 5).** "I can't" / "skip it" → *"What's one thing you could write
  down?"* and wait. Then the lowest-cost first step — the first line, the diagram, the nearest
  word she does know. A wrong attempt is a good outcome and is logged as an attempt; a blank is
  logged as a blank. Count both; the blank count is a named metric and should trend to zero.
- Praise the attempt specifically and separately from the mark, every time.
- Mark together at the end, briefly. A miss is "that one goes back in tomorrow's pile", nothing
  more.
- **This skill does not teach.** If an item exposes something that needs teaching, say one
  sentence — "that one's not stuck, it goes on the list for your maths block" — record it, and
  move on. A 15-minute warm-up that turns into a lesson has eaten the block that was supposed to
  follow it. The exception is a one-line correction that costs under a minute.
- If the block is a warm-up before a teaching block, close it by naming what the teaching block
  should open with, and hand over to that subject's tutor skill.

## Logging — one call per subject represented, all carrying the block

The block is `tracking: evidence`; it ticks itself when the evidence lands. For **each** subject
that had items in the block:

```
tracker_log_session(
  subject: "<slug>",
  date: "<the day it was actually done>",
  block_key: <from tracker_today>,
  duration_minutes: <this subject's share of the block, so the hours do not triple-count>,
  summary: "Mixed retrieval block, N items across <subjects>. This subject: n/m. <what happened>",
  next_steps: "<what this subject's next retrieval should re-ask>",
  updates: [ { ref: "…", evidence: "…", retrieval_outcome: "correct|retry|incorrect", status?: …, watch?: … } ]
)
```

Rules that bite:

- **`duration_minutes` is that subject's share, not the whole block.** Four subjects in a
  35-minute quiz is roughly 9 minutes each. Logging 35 four times inflates every hours figure in
  the Friday review.
- **Evidence is the question and the answer**, ≥ 10 characters and reconstructable: "5(x−3)=2x+9
  → x=8, three lines of working, unaided" — never "did well on algebra".
- **Omit `status` unless the item earned a move.** Recording evidence without a status change is
  the normal case and is not a failure.
- **Never promote two levels, never promote on one question.** A single unaided correct answer on
  an ageing 🟢 three weeks on is the one exception the record already recognises — and even then,
  propose 🔵 in the summary rather than applying it if there is any doubt.
- **A starter failure demotes**, where the subject's rules say so, with the evidence naming what
  she did and whether she could do it once walked through. "Blank, then correct in three steps
  when scaffolded" is a starting failure, not a method failure, and the evidence must say which.
- Blanks and attempts go in the summary as a count: "9 items, 8 attempted, 1 blank (Lit quotation
  for the Inspector)".
- Log with the date the work was done. Never move a date onto a block. Never log a ceremonial
  session to tick a block. Never excuse a block — only the parent does that.
- **A stop request is a log request — no exceptions.** If she asks to stop part-way — "wrap up",
  "log it", "I need to go", "that's enough" — make the per-subject `tracker_log_session` calls
  *in that turn* for whatever ran, with `next_steps` naming the items not yet asked so tomorrow's
  block re-asks them. Never finish the set first, never ask whether she's sure. When she is done
  is her call, not yours.

Retrieval blocks are not reviewed — the block kind does not require a lesson review — but the
next taught session's review reads these runs (through `tracker_list_practice` for practice
runs, and through the queue and `tracker_history` for the per-subject sessions above), so the
per-item outcomes are what section 6 of that review is built from. Record them per item, never
as a total alone, and put `retrieval_outcome: correct | retry | incorrect` on each `updates[]`
entry so the spacing scheduler sees them.

The weekly synthesis's planner carries a `retrieve` line naming refs to weight into the week's
retrieval blocks (it arrives in each subject's `tracker_review_queue` as `this_week`, and in the
synthesis's Part 19). `tracker_retrieval_due` already orders and spaces items, so the only check
is that those refs are not excluded from the set — do not hand-build items around them.

Then — only once every call has returned without error — tell her it is logged and where to see
it. Never say "logged" before the responses are in hand; a tally typed into chat is not a log and
is never presented as one.

## Fallback Update Block

Only when a `tracker_log_session` attempt failed in this turn, or the `tracker_*` tools are
absent. If the connector answered, the call is the log and there is no fallback:

```
=== RETRIEVAL BLOCK — [date] ===
Block: [key + label] | Subjects: [slugs] | Duration: ~[x] min
Items: [n attempted / correct / retry / blank]
Per subject: [slug — ref: question → answer → verdict]
Proposed status changes: [ref: from → to, or none]
Next retrieval should re-ask: [refs]
=== END ===
```
