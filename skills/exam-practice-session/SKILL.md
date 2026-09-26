---
name: exam-practice-session
description: >
  Run the home-educated GCSE student's weekly timed exam-practice paper — point her at the
  waiting paper on the portal without ever showing a question, then, when she says it is done,
  mark every answer against its stored mark scheme in that subject's own convention, record the
  marks and student-safe feedback, and log the sessions that put the sitting on her record. Use
  this skill whenever she asks what her exam practice is, says the test is ready for marking,
  says she has finished or handed in the paper, asks how she did on it, or asks what the rules
  of the timed paper are. Trigger on "what's my exam practice", "is there a test today", "it's
  ready for marking", "I've finished the paper", "how did I do on Wednesday's test", "mark my
  exam practice". Do not use it to write or schedule questions — that is exam-question-generator
  in Dad's project — or to mark a full past paper, which is the subject's own marker skill.
---

# Exam Practice Session

Two jobs, in her own project: **get her into the paper**, and **mark it afterwards**. The
Education Tracker connector (tools `tracker_*`) holds the paper, her answers and the record.

Teaching rules are `gcse-progress-tracker/references/study-principles.md` (§3 timed chunks, §5
never blank, §8 type to draft); the block contract is `references/timetable.md` there; marking
conventions belong to the subject markers (`gcse-maths-marker`, `gcse-english-marker`,
`gcse-cs-marker`). This skill cites them and restates none of them.

## Mode A — sitting the paper

When she asks what her exam practice is, or it is Wednesday afternoon:

1. `tracker_today()` — is the exam-practice block now or next?
2. `tracker_exam_list_tests(status: "ready")` — the paper waiting for her.
3. Give her the link and the rules, in about four lines. Nothing else.

> Wednesday's paper is ready: **https://education.rmmann.co.uk/exam/7** — 45 minutes, 41 marks,
> maths then English Language then computer science.
> The timer starts when you press Start and can't be paused. It hands the paper in at zero.
> If a question is eating your time, flag it and move on — there are easier marks waiting.
> Never leave one blank. A sensible attempt can earn marks; a blank can't.

**Never print a question.** Not a hint, not the topic of a question, not "there's a tree diagram
one". If she asks what is on it: "I'm not telling you — that's the point. Have a look at your
retrieval from this week if you want a warm-up."

If nothing is `ready`, say so and offer the retrieval warm-up instead; do not improvise a paper.
If a test is `open`, she is mid-sitting: tell her to go back to the tab, and answer nothing else
until it is closed.

While a paper is open or ready, do not call `tracker_exam_get_test` — the schemes are withheld
anyway, but the answer is not yours to look at either.

## Mode B — marking

She says it is ready for marking, or done. Work in this order and do not stop half way.

### 1. Read the sitting

```
tracker_exam_get_test(id: 7)
```

Only a `closed` test can be marked. You now have, per question: the text, the mark scheme, the
model answer, what she typed, her working, whether she flagged it, how long it had her focus, and
whether it was blank. Also the sat minutes against the duration and who closed it.

### 2. Mark each answer in its subject's convention

Follow the subject's marker skill, which is where the conventions live:

- **Maths** — positive marking; method marks stand through an arithmetic slip; `ft` and SC where
  the scheme allows; the answer-line rule. `gcse-maths-marker/references/marking-conventions.md`.
- **English** — match the level descriptor, then the mark within its range, against the
  indicative content; AO4 separately where the question carries it.
  `gcse-english-marker/references/aqa-8700-assessment.md` and `…/aqa-8702-assessment.md`.
- **Computer science** — mark points with their accept/reject lists; level of response on the
  6–9 markers; Python 3 conventions on code. `gcse-cs-marker/references/aqa-8525-assessment.md`.

Mark what she wrote, not what she meant. A blank scores what the scheme gives it, which is
almost always zero — and it is recorded as a blank, which matters more than the mark.

### 3. Record it

```
tracker_exam_mark_test(id: 7, marks: [
  { question_id: 12, score: 3,
    note: "M1 4x - 12 = 2x + 10; M1 2x = 22; A0 answer line reads x = 11cm",
    student_feedback: "Method fully right. The answer line wanted a number, not a length — check what the question asked for." },
  …
], note: "Ran out of time in the CS section; two blanks at the end.")
```

`note` is the teacher-side justification, tied to the scheme. `student_feedback` is what she
reads on the portal and it obeys the markers' separation rule: **the topic and a method hint,
never the answer**. "Divide the top by the bottom" is feedback; "= 0.4375" is not. One or two
sentences, and say what earned the marks as well as what lost them.

Every question must be in the one call, and no score may exceed the question's marks. The tool
writes one `check` attempt per subject, so the marks land on her real subject records.

### 4. Log the sessions

The tool's reply says this too. Dated the day of the sitting:

**One per subject that had questions** — `tracker_log_session(subject, date, summary,
updates[], review)`, with:

- `summary` naming the section marks and what the errors were about;
- `updates[]` carrying a `retrieval_outcome` for each ref the section touched
  (`correct` / `retry` / `incorrect`) and **no status change** — marking establishes facts,
  `gcse-progress-tracker` adjudicates promotions;
- a `review` per `lesson-review`'s analysis rules, with each error typed and
  `missing_evidence` naming what a short timed paper could not show;
- **no `block_key`** — the sitting is already the block's evidence.

**One for `exam-skills`** — same call, subject `exam-skills`, **no `block_key` and no
`duration_minutes`** (the sitting carries the hour). This one is about technique, and it is the
reason the paper exists. `references/technique-marking.md` has the taxonomy; put in its
`review`:

- time per section against its guide, and the question that ate the time;
- the order she answered in, and what she flagged and whether she came back;
- blanks, with which questions and whether time or knowledge caused them;
- marks lost to **technique** rather than knowledge: working not shown, wrong answer line,
  command word misread, a paragraph written for a 1-mark question, an extended answer that never
  reached the band it was aiming at;
- `updates[]` on the T/R/A/S refs with the numbers as evidence;
- signals keyed `exam-…`, with a `next_test` naming what next week's paper would show.

### 5. Tell her

Marks per section and the paper total, the feedback lines, and **one technique target** for next
week — one, not a list. Something she can do: "next week, when a question has more than two
marks, write the method line before the answer". Then say her paper is on the portal with the
feedback if she wants to re-work anything.

Never read her the mark scheme, the model answers or your marker's notes. They are on the page
for Dad, behind his login.

## Never

- Never show or describe a question before she has sat it.
- Never call `tracker_exam_add_questions`, `tracker_exam_list_questions`,
  `tracker_exam_update_question` or `tracker_exam_update_test`; the bank and the
  booked papers are Dad's — if she asks for more time, tell her to ask him.
- Never pass `include_bank: true` to `tracker_exam_get_test`.
- Never mark a test that is not `closed`, and never mark one twice.
- Never move a topic status from marking.
- Never put an answer in `student_feedback`.
- Never tell her the timer can be paused or extended. It cannot, and that is the point.
