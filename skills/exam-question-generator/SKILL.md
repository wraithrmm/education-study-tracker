---
name: exam-question-generator
description: >
  Write, vet and schedule the exam-style questions the home-educated GCSE student sits in her
  weekly timed exam-practice paper, in the house style of the AQA past papers held in this
  project's knowledge base. Use this skill whenever the parent asks to generate or write exam
  questions, build or schedule the Wednesday paper, vet or edit the question bank, or asks what
  is banked for a subject. Trigger on "generate some exam questions", "build next Wednesday's
  paper", "vet the drafts", "what's in the bank", "schedule the test", "add a maths question on
  quadratics". Questions are stored in the Education Tracker's question bank, tagged by subject
  and topic ref, and are never shown to the student before she sits them. Do not use this skill
  to mark a sat paper — that is exam-practice-session in her own project — or to mark a full past
  paper, which is the subject's own marker skill.
---

# Exam Question Generator

Writes the questions for the Wednesday timed paper. This skill runs in the **parent's** project,
where the past papers live. Nothing it produces is ever pasted into a chat the student can see:
the questions reach her only through the portal, on the day, with the clock running.

The tracker is the store. `docs/exam-skills.md` in the education-study-tracker repository is the
service contract; this skill is written to §7 of it.

## What the paper is for

She is practising **exam technique**, not content. Each paper has to make one of these
observable:

- what an examiner wants from a **1-mark** answer versus a **4-mark** one;
- keeping to time, and leaving a question that is eating it;
- reading the command word and the mark allocation before writing;
- showing working where the method carries marks;
- never leaving a blank.

`references/paper-composition.md` turns that into the rules for a week's paper — including the
one question per paper that is *meant* to be hard, so that deciding to skip it is a thing she
practises rather than a thing that goes wrong.

## Before writing anything

1. **`tracker_list_subjects`** then **`tracker_get_state(subject)`** for each subject you are
   writing for. Take refs from there and nowhere else: a ref the tracker does not hold refuses
   the call, and refs are what turn her marks into teaching information.
   Write on `developing`, `secure` and `examready` topics by default — the paper tests
   technique on content she has met. Only write on a `gap` when the parent asks.
2. **`tracker_exam_list_questions(status: "vetted")`** — what is already banked and unused. Do
   not write a second question on a ref that already has a vetted one waiting.
3. **Read the paper you are imitating.** The knowledge base holds
   `papers/<subject>/<code>-<series>-QP.pdf` and `-MS.pdf`. Open the question paper *and* its
   mark scheme for the paper whose style you are writing in. `references/question-styles.md` is
   the summary; the actual paper is the authority.

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
  tags: ["non-calc", "linear"],
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

`action: "edit"` with a `fields` object corrects a draft and sends a vetted question back to
draft (what was checked is no longer what is stored — vet it again). `action: "retire"` drops
one. A question already in a test cannot be edited or retired: what she sat is fixed.

## Building the week's paper

```
tracker_exam_schedule_test(
  name: "Exam practice — week 40",
  scheduled_for: "2026-09-30",
  duration_minutes: 45,
  block_key: 39,                            // from tracker_get_timetable
  instructions: "Maths section: no calculator. Write something for every question.",
  sections: [
    { subject: "maths",              question_ids: [12, 13, 14], minutes_guide: 15 },
    { subject: "english-language",   question_ids: [15],         minutes_guide: 12 },
    { subject: "computer-science",   question_ids: [16, 17],     minutes_guide: 10 }
  ]
)
```

Rules the tool enforces, so get them right first: every question vetted, in its section's
subject, and unused; one section per subject; one test per date; `block_key` must be the
exam-practice block that runs that day. Rules it only warns about, which are yours:

- 45 minutes by default. Section guides should sum to the duration **less about five minutes**
  of reading and checking time.
- Roughly a mark a minute overall; ~40 marks for 45 minutes.
- Section order: maths, English Language, English Literature, computer science, unless the
  parent says otherwise.
- Put the marks per section in `instructions`, with any calculator rule.

Report back: the sections with their marks and guides, the total, and the link she will use.
Say plainly if you had to leave a subject out because the bank had nothing vetted for it.

## Keeping the bank healthy

The parent may ask what state it is in. `tracker_exam_list_questions` answers with counts per
status. Aim to hold **three weeks of vetted, unused questions** across the subjects. When the
bank runs thin, say so with the number and offer to write the gap.

After a paper is marked, the sat questions become `marked` and stay in the bank as the record of
what she has seen — never reuse one, and do not write a near-identical replacement in the
following week. The same ref at a different mark tariff, several weeks apart, is the point.

## Never

- Never paste a question, a mark scheme or a model answer into a chat the student might read.
  This project is the parent's; her project never calls `tracker_exam_add_questions`,
  `tracker_exam_list_questions` or `tracker_exam_update_question`.
- Never claim a question is from a real paper. They are written **in the style of** the papers;
  `source_note` says which question was the model.
- Never invent a topic ref, a paper code or a grade boundary.
- Never mark a paper here — that is her project, with `exam-practice-session`.
