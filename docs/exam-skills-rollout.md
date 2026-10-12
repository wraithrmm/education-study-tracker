# Rolling out exam skills

What to do after this is deployed. Steps 2 and 3 go through tools that exist
on the live service only once it is up; steps 4 to 6 are outside this
repository.

## 1. Deploy

Schema step 14 runs on the first request: `exam_practice` joins the block
kinds, the four exam tables are created, the kind's shape rule is seeded.
`/healthz` now counts `exam_questions` and `exam_tests`.

## 2. Create the subject

`docs/exam-skills-seed.json` is the `tracker_create_subject` payload: slug
`exam-skills`, four strands (timing, reading the question, answering, stamina),
seventeen topics, no tier, no exam date, no boundaries. Ask Claude, from the
parent's tracker chat:

> Read `docs/exam-skills-seed.json` and create it with `tracker_create_subject`.

Every topic starts `notstarted`. They move only through the sessions her
exam-practice project logs after each sitting.

## 3. Re-cut the timetable

The live board is what to re-cut from, not `docs/timetable-seed.json` — that
file is the September fixture the smoke test runs against, and the board has
moved on. As of version 11 the free keys start at **42**, Wednesday afternoon
holds block **41** (English Language — reading, real sources, 14:30–15:30),
and block 20 (Spanish) is at 09:45 in the morning, so it no longer needs
moving. From the parent's chat, with `term-planner`:

> Read the timetable with `tracker_get_timetable`. Add block **42**, Wednesday
> 14:30–15:30, kind `exam_practice`, label "Exam practice — timed paper",
> subjects `["exam-skills"]`, tracking `evidence`, note "Mixed-subject timed
> paper on the portal at /exam; marked afterwards in her exam-practice
> project". Move block **41** to 15:30–16:30. Keep every other block's key,
> times, kind **and note** exactly as they are. Show me the diff, then write
> it with `tracker_set_timetable`, `valid_from` next Monday.

It should report one block added and one moved. Check `/` afterwards: the
Wednesday column gains the chip, and once a test is scheduled the chip links
to it.

Read the keys and the notes from the board on the day, not from here. A re-cut
writes a whole new version and keeps only what it is given, so a block whose
note is not sent back loses it — which is why `tracker_get_timetable` prints
every note.

## 4. The two skills and the two projects

`skills/exam-question-generator/` and `skills/exam-practice-session/` are the
skill packs, in the format of the installed pack. Upload each to the skills
plugin. Then:

- **Exam Paper Builder** — a new project for the parent. Its instructions
  are `skills/exam-question-generator/PROJECT.md`. Its knowledge base holds the
  past papers and mark schemes, one pair per paper, named as that file says,
  plus the specification for each subject. It has the Education Tracker
  connector, and it loads `exam-question-generator` and
  `gcse-progress-tracker`. It runs **two scheduled tasks**, both in that
  file: Sunday 18:00 "Build Wednesday's paper", which runs the weekly build
  unattended and reports, and Tuesday 18:00 "Paper check", which builds a
  paper if none is ready and otherwise confirms the one that is. Retire any
  earlier report-only "Bank check" task; the build includes it.
- **Exam practice** — a new project for Paige. Its instructions are
  `skills/exam-practice-session/PROJECT.md`. Nothing in its knowledge base;
  the connector only.

## 5. The first bank and the first paper

In the generator project:

> Generate the first three weeks of questions for maths, English Language,
> English Literature and computer science, vet them, and schedule next
> Wednesday's paper against the exam-practice block.

Check the bank at `/exam/bank` (sign in first) and the paper at `/exam/<id>`
— it should show the sections and the Start button and no question.

## 6. Wednesday

At 14:30 the chip on the board links to the paper. She presses Start; the
timer runs; at zero the paper is handed in. Any time after, in her exam
project:

> It's ready for marking.

The project marks it, logs the sessions, and tells her the marks per section
and one thing to work on. The parent reads the whole paper — schemes, model
answers, marker's notes — at `/exam/<id>` signed in, and the per-subject
attempts at `/s/<subject>/a/<id>`.

## 7. Homework — after schema step 16 is deployed

Step 16 runs on the first request after the deploy: `mode` and `due_on` join
`exam_tests`, `not_attempted` joins `exam_answers`. Every existing test reads
as a sitting. Nothing to create and no timetable change: a homework takes no
block.

**This weekend's sheet.** `docs/homework-seed.json` is the straight-lines
homework set on 9 Oct, as the three tool calls that put it on the tracker.
From the parent's tracker chat:

> Read `docs/homework-seed.json` and run its three steps with the
> `tracker_exam_*` tools: add the six questions, vet them, set the homework.
> Show me `/exam/<id>`.

If she has already done it on paper by then, mark it from the photographs in
the same chat: `tracker_exam_get_test(id, include_bank: true)`, then
`tracker_exam_mark_test(id, handed_in_on: <the day she did it>, marks: […])`.
Marking a set homework hands it in. The A9-A10 loose end on the tracker
already says the sheet is owed; the marked attempt is what closes it, and the
Friday review adjudicates the refs as for a paper.

Do **not** add the questions to the bank before the deploy: the Sunday build
picks vetted questions by flag, and `A9-A10` is flagged.

**Skills.** Re-upload `gcse-progress-tracker` (it gains
`references/homework.md`), `exam-question-generator`, `exam-practice-session`
and `parent-weekly-review`, or update the plugin.

**The Homework project (12 Oct).** The first sheet was not picked up: a
lesson opened that weekend and no lesson skill said a sheet was out. Two
changes close that, and neither needs a deploy:

- **Homework** — a new project for Paige. Its instructions are
  `skills/homework-session/PROJECT.md`; nothing in its knowledge base; the
  connector only. Upload `homework-session`, or update the plugin. It opens
  every conversation on the sheet she owes.
- Re-upload `maths-tutor-session`, `english-tutor-session`,
  `cs-tutor-session`, `spanish-maintenance-session`, `exam-practice-session`
  and `gcse-progress-tracker` (its `references/timetable.md`, `homework.md`
  and `block-kinds.md` changed), or update the plugin. Every lesson now names
  a `set` sheet in its first message.

## 8. If the connector's tool list is stale

If a chat cannot see `tracker_exam_*`, remove and re-add the Education
Tracker connector in Settings → Connectors: the tool list is read once at
connection time.
