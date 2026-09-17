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

The live board is what to re-cut from, not `docs/timetable-seed.json` — the
board has been edited since the seed (the Wednesday group, lunch). From the
parent's chat, with `term-planner`:

> Read the timetable with `tracker_get_timetable`. Add block **39**, Wednesday
> 14:30–15:30, kind `exam_practice`, label "Exam practice — timed paper",
> subjects `["exam-skills"]`, tracking `evidence`, note "Mixed-subject timed
> paper on the portal at /exam; marked afterwards in her exam-practice
> project". Move block **20** (Spanish) to 15:30–15:45. Keep every other
> block's key, times and kind exactly as they are. Show me the diff, then
> write it with `tracker_set_timetable`, `valid_from` next Monday.

It should report one block added and one moved. Check `/` afterwards: the
Wednesday column gains the chip, and once a test is scheduled the chip links
to it.

## 4. The two skills and the two projects

`skills/exam-question-generator/` and `skills/exam-practice-session/` are the
skill packs, in the format of the installed pack. Upload each to the skills
plugin. Then:

- **Exam question generator** — a new project for the parent. Its instructions
  are `skills/exam-question-generator/PROJECT.md`. Its knowledge base holds the
  past papers and mark schemes, one pair per paper, named as that file says,
  plus the specification for each subject. It has the Education Tracker
  connector.
- **Exam practice** — a new project for Paige. Its instructions are
  `skills/exam-practice-session/PROJECT.md`. Nothing in its knowledge base;
  the connector only.

## 5. The first bank and the first paper

In the generator project:

> Generate the first three weeks of questions for maths, English Language,
> English Literature and computer science, vet them, and schedule next
> Wednesday's paper against block 39.

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

## 7. If the connector's tool list is stale

If a chat cannot see `tracker_exam_*`, remove and re-add the Education
Tracker connector in Settings → Connectors: the tool list is read once at
connection time.
