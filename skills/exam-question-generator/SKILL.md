---
name: exam-question-generator
description: >
  Build the home-educated GCSE student's weekly timed exam-practice paper from the Education
  Tracker's flags — choose the subjects by rotation, choose each question for a reason the
  tracker can show, write and vet what the bank lacks in the house style of the AQA past papers
  in this project's knowledge base, schedule the paper against the Wednesday block and record
  why each question is there. Use this skill for the weekly build (the Sunday scheduled task
  "build Wednesday's paper"), the Tuesday "paper check", and whenever the parent asks to
  generate or write exam questions, build or schedule the Wednesday paper, vet or edit the
  question bank, or asks what is banked for a subject. Trigger on "weekly build", "build
  Wednesday's paper", "paper check", "is there a paper for Wednesday", "why is this question on
  the paper", "generate some exam questions", "vet the drafts", "what's in the bank", "schedule
  the test", "add a maths question on quadratics". Questions are stored in the tracker's
  question bank, tagged by subject and topic ref, and are never shown to the student before she
  sits them. Do not use this skill to mark a sat paper — that is exam-practice-session in her
  own project — or to mark a full past paper, which is the subject's own marker skill.
---

# Exam Question Generator

Builds the Wednesday timed paper and writes the questions for it. This skill runs in the
**parent's** Exam Paper Builder project, where the past papers live. Nothing it produces is ever
pasted into a chat the student can see: the questions reach her only through the portal, on the
day, with the clock running.

The tracker is the store. `docs/exam-skills.md` in the education-study-tracker repository is the
service contract; this skill is written to §7 of it. The project's own instructions
(`PROJECT.md`) hold the parent's standing decisions; this skill holds the procedure, and the two
agree step for step.

## What the paper is for

She is practising **exam technique**, not content. Each paper has to make one of these
observable:

- what an examiner wants from a **1-mark** answer versus a **4-mark** one;
- keeping to time, and leaving a question that is eating it;
- reading the command word and the mark allocation before writing;
- showing working where the method carries marks;
- never leaving a blank — and typing "skip" is a blank.

And every paper has a second job: each question is **the piece of evidence a topic is waiting
for**, so that the marked paper moves her record. `references/paper-composition.md` turns both
jobs into the rules for a week's paper — the rotation, the selection codes, and the one question
per paper that is *meant* to be hard, so that deciding to leave it is a thing she practises
rather than a thing that goes wrong.

## The weekly build

The paper must always exist before the Wednesday block. On 7 October 2026 it did not, because
no task owned building it; this procedure is the fix. It runs **unattended** — Sunday 18:00 as
a scheduled task, with a Tuesday 18:00 check as the safety net — and it **asks nothing**. The
parent reads the report afterwards and can change or veto the paper until Wednesday 14:30.

Run the steps in order. Every step reads the tracker; nothing is taken from memory or from an
earlier chat.

**0. Target.** The paper is for the coming Wednesday. `tracker_get_timetable(valid_on: <that
date>)`: the block of kind `exam_practice` gives the `block_key` (read it from the timetable
every run; never assume last week's key). No such block → stop and say so. `tracker_days_off`
for that date: an approved day off → schedule nothing and report "day off, no paper"; a request
not yet decided → build anyway and say so in the report. The paper is 45 minutes until the
parent says otherwise.

**1. Close out the last paper.** `tracker_exam_list_tests(limit: 8)`. Weekly papers are the
tests named `Exam practice — week NN …`; tests named `Placement …` or `Resit …` are not, and
are never touched.
- Latest weekly paper `marked` → check it was adjudicated: `tracker_history(subject, ref)` for
  its first-ref topics holds a line beginning `adjudicated paper #<id>`. If not, load
  `gcse-progress-tracker` and run its "Adjudicating the Wednesday paper" now, before choosing
  anything — the flags you read in step 2 depend on it.
- `closed`, not marked → it can only be marked in her project. Say so in the report and treat
  its topics as untested.
- `ready` with its date passed → it was not sat. Carry it forward: re-date it to the target
  Wednesday with `tracker_exam_update_test(id, action: "edit", fields: {scheduled_for,
  block_key, name})`, re-check every question against step 4, swap any that no longer qualifies
  (the whole `sections` list is re-sent; dropped questions go back to vetted), rename it for
  the week. That is this week's paper.
- A `ready` test already on the target date → check it against steps 4 and 8; do not build a
  second. **The build is idempotent**: a second run in the same week changes nothing unless
  something is wrong.

**2. Read the flags**, for the four exam subjects — `maths`, `english-language`,
`english-literature`, `computer-science`. Never `spanish` (foundation work for 2028; it is never
on the paper). `exam-skills` is the technique subject and is never a section.
- `tracker_get_state(subject)` — status, last touched, loose end, per ref. Refs come from here
  and nowhere else.
- `tracker_review_queue(subject)` — top gaps, ageing secures, this week's plan, and the last
  review's planner (what unaided evidence a topic is still owed).
- `tracker_retrieval_due(subjects: [all four], limit: 24)` — what is due or overdue.
- `tracker_signals(subject: "exam-skills", status: "open")` and
  `tracker_review_queue("exam-skills")` — the technique target the last paper set and the test
  the synthesis designed on it.
- `tracker_exam_list_questions(status: "vetted")` — what is banked and unused.
- The last three weekly papers with `tracker_exam_get_test` — which refs she has been set, at
  what tariff, how each went, how long she sat, what was not attempted.

**3. Choose the subjects** by the rotation rule in `references/paper-composition.md`,
"Choosing the subjects": three sections (four once a paper is 60 minutes or longer); one exam
subject sits out each week — the one that sat out longest ago, never-sat-out first; a subject
that sat out last week is always in. One question of 6 marks or more per paper (5 or more in
maths), never in the subject that carried it the week before.

**4. Choose the questions** by the selection codes in `references/paper-composition.md`,
"Choosing the questions", in priority order until the paper is full: `tech` → `bar` → `retest`
→ `due` → `loose` → `stretch` → `cover`, with `open` only on the parent's word. First topic ref
decides. No ref twice in a paper; no ref she was set in the last three weeks at the same
tariff; never a question she has sat; a topic that lost marks for a knowledge reason on a paper
is not set again until a taught session has logged evidence on it since. `cover` is at most a
quarter of the marks. The subject with the most flagged refs gets the most marks; no section
under 8.

**5. Fill what the bank lacks.** If a chosen ref has no vetted question at a suitable tariff,
write one (below): read the real paper and its mark scheme in this project's knowledge first,
store it with `tracker_exam_add_questions`, work `references/vetting-checklist.md` over it, and
vet it. At most **12 new questions in one unattended run**, each tagged `auto` so the parent can
find what he has not read. If a question cannot be written, fall to the next flagged ref that
has a vetted question.

**6. Compose and schedule** by `references/paper-composition.md`: about a mark a minute, at
least one 1-mark question and one of 4 or more, the stretch question mid-section, section
guides summing to the duration less five minutes. `tracker_exam_schedule_test` with the
`block_key` from step 0, named and instructed as "Building the week's paper" says.

**7. Record why.** For each question in the test, one `why:` tag, one `wk:` tag and the flag
behind it as the note — "Building the week's paper", "Record why", has the call and its
constraints.

**8. Verify.** `tracker_exam_get_test(id, include_bank: true)`: the marks add up, the guides
add up, no first ref twice, every scheme present, every question carrying its `why:` and `wk:`
tags. **Never report a paper you have not read back.**

**9. Keep the bank at its floor** ("Keeping the bank healthy", below): write and vet the
shortfall on flagged refs first, within the same 12-a-run cap, and report what is still short.

**10. Report**, in this shape and no longer, glossing every exam code in plain words:

```
Wed 14 Oct — paper #8 READY · 45 min · 38 marks · maths 14, lit 12, cs 12 · language sits out
Why: 3 bar · 2 retest · 3 due · 1 loose · 1 tech · 1 stretch · 2 cover (4 marks)
Technique target printed: three quotations in any language answer before moving on
Written this run: 6 new (cs 4, lit 2), all vetted, tagged auto
Last paper (#7): marked · adjudicated — N1-N3 secure→developing; S4 secure→exam-ready
Bank after build: maths 100 marks · language 76 · literature 24 (SHORT of 36) · cs 30 (SHORT)
Not done / needs you: <anything refused, anything waiting on a decision, or "nothing">
https://education.rmmann.co.uk/exam/8
```

A paper not built is reported as `NOT SCHEDULED` with the reason; a day off as `day off, no
paper`. If the connector is unavailable or a step is refused, say exactly which and stop.

**The Tuesday check** is the same procedure entered at step 0 with tomorrow as the target:
`tracker_today(date: <tomorrow>)`, `tracker_days_off` for tomorrow, and
`tracker_exam_list_tests(status: "ready", from: <tomorrow>, to: <tomorrow>)`. A block, no
approved day off and no `ready` paper → build one now, from the vetted bank first, and report. A
paper ready → `tracker_get_state` for each of its first refs; swap any question whose topic is
now `gap` or `notstarted` (it was set before the status moved), re-tag what was swapped, read
back, and say what changed. Nothing to do → one line: the paper's number, its sections and its
marks.

## Before writing anything

1. **Selection comes from the flags, not from status alone.** A question is written because a
   ref carries a flag the build read in step 2 — a loose end, evidence still owed, a retrieval
   due, a secure topic going untested, the technique target — and the flag is what its `why:`
   tag will record. When the parent asks for questions outside a build ("add a maths question on
   quadratics"), the same reading applies: `tracker_get_state(subject)` first, and say which flag
   the ref carries, or that it carries none and the question is `cover`.
2. **`tracker_list_subjects`** then **`tracker_get_state(subject)`** for each subject you are
   writing for. Take refs from there and nowhere else: a ref the tracker does not hold refuses
   the call, and refs are what turn her marks into teaching information. Write on `developing`,
   `secure` and `examready` topics by default — the paper tests technique on content she has
   met. Only write on a `gap` or `notstarted` topic when the parent asks (code `open`).
3. **`tracker_exam_list_questions(status: "vetted")`** — what is already banked and unused. Do
   not write a second question on a ref that already has a vetted one waiting at the tariff you
   need.
4. **Read the paper you are imitating.** The knowledge base holds
   `papers/<subject>/<code>-<series>-QP.pdf` and `-MS.pdf`. Open the question paper *and* its
   mark scheme for the paper whose style you are writing in. `references/question-styles.md` is
   the summary; the actual paper is the authority.

**Writing unattended.** If no past paper for the subject is in this project's knowledge, write
from `references/question-styles.md`, say so in `source_note` ("written from the style summary;
no 8702 paper in the knowledge base"), and name it in the report. Never claim a model question
that was not read.

## Writing a question

One question per bank row. It must read as though it were lifted from that paper: the same
command word, the same mark allocation, the same phrasing conventions, the same instructions
("You must show your working", "Use the figure below" — only if the figure is described in
words), the same register.

The mark scheme goes in the same row and in that paper's own code system:

| Subject | Scheme style |
|---|---|
| Maths (8300) | M / A / B marks, `ft`, `dep`, `oe`, SC rows, the answer-line rule — `gcse-maths-marker/references/marking-conventions.md` |
| English Language (8700) | Level descriptors with their mark ranges, indicative content, AO named — `gcse-english-marker/references/aqa-8700-assessment.md` |
| English Literature (8702) | Level descriptors, AO1/AO2/AO3 weighting, AO4 where it applies — `…/aqa-8702-assessment.md` |
| Computer Science (8525) | Mark points, accept/reject lists, Python 3 conventions, level of response on the 6–9 markers — `gcse-cs-marker/references/aqa-8525-assessment.md` |

Never restate those references here; cite them and follow them.

Text only. The page renders restricted Markdown — paragraphs, `**bold**`, `*italic*`, `` `code` ``,
fenced code blocks, `-` and `1.` lists, `x^2` superscripts — and nothing else. A question needing
a diagram is either described fully in words or not written.

**Printed text.** Only original writing or out-of-copyright text goes inside `question_md`:
Macbeth, A Christmas Carol, the pre-1929 anthology poems. For An Inspector Calls and the modern
anthology poems, name the text and the moment ("the end of Act One, from Sheila's return to the
curtain") and say she may open her own copy at it. Unseen poems and every Language source are
original, as now.

Then store the batch:

```
tracker_exam_add_questions(questions: [{
  client_key: "maths-A17-20260923-1",      // stable; re-sending is a no-op
  subject: "maths",
  topic_refs: ["A17"],                      // the first is where the mark is attributed
  marks: 3,
  paper_style: "8300/1H",
  calculator: false,
  command_word: "Solve",
  time_guide_seconds: 180,                  // ~1 min per mark in maths; by AO in English
  question_md: "Solve 4(x - 3) = 2x + 10\n\nYou must show your working.",
  mark_scheme_md: "M1 for 4x - 12 = 2x + 10 oe\nM1 for 2x = 22 oe\nA1 for x = 11",
  model_answer_md: "4x - 12 = 2x + 10, 2x = 22, x = 11",
  tags: ["non-calc", "linear", "auto"],     // `auto` on anything written unattended
  source_note: "Modelled on 8300/1H June 2023 Q7"
}, …])                                      // at most 20 at a time is comfortable; 50 is the cap
```

Everything lands as `draft`.

## Vetting

Nothing reaches a paper unvetted. Work `references/vetting-checklist.md` over each draft — it is
short and every line is a way a question has actually gone wrong — then:

```
tracker_exam_update_question(id: 12, action: "vet", note: "checked: answerable at Higher, marks match the scheme")
```

`action: "edit"` with a `fields` object corrects a question. In the bank it sends a vetted
question back to draft (what was checked is no longer what is stored — vet it again). Inside a
test — scheduled, answered or marked — it corrects the question in place and it keeps its place
and status; its marks can never drop below a score already given, and a marked question's
attempt keeps the copy it was marked on. `action: "retire"` drops one; a question inside a test
has to be taken out of it first (below).

## Building the week's paper

```
tracker_exam_schedule_test(
  name: "Exam practice — week 42 · maths, lit, cs",
  scheduled_for: "2026-10-14",
  duration_minutes: 45,
  block_key: 42,                            // from tracker_get_timetable, every run
  instructions: "Maths 14 · English Literature 12 · Computer Science 12. No calculator in the maths section. This week: three quotations in any literature answer before you move on. Flag anything that is eating your time and come back to it. Never leave a question blank — typing 'skip' is a blank. A sensible attempt can earn marks, a blank cannot.",
  sections: [
    { subject: "maths",              question_ids: [12, 13, 14, 18], minutes_guide: 14 },
    { subject: "english-literature", question_ids: [15],             minutes_guide: 13 },
    { subject: "computer-science",   question_ids: [16, 17],         minutes_guide: 13 }
  ]
)
```

Rules the tool enforces, so get them right first: every question vetted, in its section's
subject, and unused; one section per subject; one test per date; `block_key` must be the
exam-practice block that runs that day. Rules it only warns about, which are yours:

- 45 minutes by default. Section guides sum to the duration **less five minutes** of reading
  and checking time.
- Roughly a mark a minute overall; ~40 marks for 45 minutes. No section under 8 marks; marks
  follow need.
- **Name**: `Exam practice — week NN · <subjects>`, the subjects abbreviated in section order
  (`maths`, `lang`, `lit`, `cs`), so that `tracker_exam_list_tests` alone shows the rotation.
- Section order: maths, English Language, English Literature, computer science, unless the
  parent says otherwise.
- **Instructions, in this order**: marks per section; any calculator rule; the one technique
  target from the last paper, in plain words she can act on; "Flag anything that is eating your
  time and come back to it"; "Never leave a question blank — typing 'skip' is a blank. A
  sensible attempt can earn marks, a blank cannot." Nothing else: no topic, no hint, no reason.

**Record why.** After scheduling, for each question in the test:

```
tracker_exam_update_question(id: 12, action: "edit",
  fields: { tags: ["non-calc", "linear", "why:bar", "wk:2026-W42"] },
  note: "developing; loose end 29 Sep: unaided solve with brackets still owed")
```

The constraints, which the service will not forgive:

- `tags` is **replaced whole**: re-send the question's existing tags with the two new ones.
  Read them from `tracker_exam_list_questions` or the read-back first.
- At most **10 tags of 40 characters** each. `why:<code>` and `wk:<ISO week>` are two of them.
- Inside a test the edit is made in place; the question keeps its status and its place. If an
  edit is refused, carry on and list the refused ids and the reason in the report.
- **Never put a reason in the test's `instructions`** — she reads them.
- **Never rely on the test's `note`** — marking overwrites it (one `note` field; the marker's
  note replaces the build's).

The adjudicator reads the reasons back with `tracker_exam_list_questions(tag: "wk:2026-W42")`.
Her project cannot see tags through `tracker_exam_get_test`, which is right: adjudication is
parent-side.

Report back as step 10 says: the sections with their marks and guides, the total, the `why:`
counts, and the link. Say plainly if a subject in the rotation has nothing vetted and nothing
could be written for it.

## Changing or cancelling a booked paper

`tracker_exam_update_test(id, action: "edit", fields: {...}, note?)` changes a test after it was
booked. Before she presses Start (`ready`) anything can change: `name`, `scheduled_for`,
`duration_minutes`, `block_key` (null clears it), `instructions`, `note`, and `sections` — the
whole new list, checked as for scheduling; questions already in the test may stay, and any
dropped go back to vetted. While she is sitting it (`open`) the timer can still be lengthened or
shortened — the deadline becomes start + the new duration and her page picks it up within 20
seconds — along with the name, instructions and note. Once sat, only the name, instructions and
note.

`tracker_exam_update_test(id, action: "cancel", note?)` deletes a test that is not yet marked.
Questions from a test she never started go back to vetted; from one she started they are
retired, unless `requeue: true`. A marked test cannot be cancelled.

The build may carry an unsat paper forward and swap questions in a `ready` test on its own. Any
other change — length, an `open` or marked paper, a cancellation — is shown to the parent before
it is made, and the test is reported as it then stands. Re-tag anything swapped in.

## Keeping the bank healthy

`tracker_exam_list_questions` answers with counts per status; read it per subject. The floor,
**per exam subject**: at least **36 marks of vetted, unused questions, including three 1-mark
questions and two of 6 marks or more (4 or more in maths)**. The 40-mark writing tasks take a
whole slot and do not count toward it. Below the floor, the build tops up on flagged refs first,
within its 12-a-run cap, and reports what is still `SHORT`. When the parent asks, say the
number per subject and what you would write to fill it.

Bank for all topics, weighted to the nearer weeks; plan a few weeks ahead only. After a paper is
marked, the sat questions become `marked` and stay in the bank as the record of what she has
seen — never reuse one, and do not write a near-identical replacement in the following week. The
same ref at a different mark tariff, several weeks apart, is the point.

## Never

- Never paste a question, a mark scheme or a model answer into a chat the student might read.
  This project is the parent's; her project never calls `tracker_exam_add_questions`,
  `tracker_exam_list_questions`, `tracker_exam_update_question` or `tracker_exam_update_test`.
  Nothing about a question — text, topic, shape — reaches a chat she can read.
- Never claim a question is from a real paper. They are written **in the style of** the papers;
  `source_note` says which question was the model, or that none was read.
- Never invent a topic ref, a paper code or a grade boundary.
- Never mark a paper here — that is her project, with `exam-practice-session`.
- Never schedule on an approved day off.
- Never edit or cancel a test named `Placement …` or `Resit …`, or any marked test.
- Never put a reason in a test's `instructions`; never rely on a test's `note`.
- Never change the paper's length, set an `open` question (a `gap` or `notstarted` topic), or
  set a single-subject or whole-slot paper (a 40-mark writing task) without the parent's word.
- Never report a paper you have not read back.
