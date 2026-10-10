# Homework — set it, hand it in, mark it, log it

Companion to `block-kinds.md` and `timetable.md`. Homework is the Wednesday paper's bank and
marking without its sitting: the questions come from the bank and are met once; the marks land
as a `check` attempt per subject; the record is the tracker. What differs is the shape of the
work — on paper, by hand, over a weekend, handed in as photographs — and that is exactly the
part a timed test cannot hold. So a homework is a test row in `mode: "homework"`, and it never
passes through `open`.

Runs **in the parent's project only**, from the tracker chat. Her project points her at the
sheet (`exam-practice-session` Mode C) and never marks it.

## When to set one

- A loose end the week left on a topic she has been taught: the same error twice, a method
  right only with a prompt, a working line she still does not write. Read it off
  `tracker_get_state(subject)` and `tracker_review_queue(subject)` first; the `watch` text is
  the brief.
- Never on a topic untaught, and never to cover a missed block — a miss stays a miss.
- At most one sheet per subject per due date (the service refuses a second). Keep it short:
  about 25 minutes, 10–15 marks, four to six questions, scaffold withdrawn across them.

## Setting it — five calls

1. **Bank the questions.** `tracker_exam_add_questions(questions: [...])`, one per item, in the
   subject's paper style, each with its mark scheme and model answer, `topic_refs` taken from
   `tracker_get_state` (the first ref is the one the mark lands on), a stable `client_key`
   (`maths-A9-hw-20261011-1`) and the tag `hw:<due_on>`. Write to the
   `exam-question-generator` checklist (`references/question-checklist.md` there): no diagrams,
   restricted Markdown, marks that match the scheme. Do not reuse anything vetted and waiting
   in the bank for the Wednesday paper unless the parent says so — a question is met once, and
   the build counts on what is vetted.
2. **Vet them.** `tracker_exam_update_question(id, action: "vet", note)` for each.
3. **Set the sheet.** `tracker_exam_schedule_test(mode: "homework", name, scheduled_for: <today>,
   due_on, duration_minutes: <the guide>, instructions, sections: [{ subject, question_ids,
   minutes_guide }], note)`. No `block_key`. The **instructions are read by her on the sheet**,
   so unlike a paper they may say why: one plain sentence on what the week showed, then the
   rules (on paper, by hand; no calculator or notes; about N minutes with a visible timer; every
   line of working; the safe first line if one looks hard), then "photograph every page and give
   the photos to Dad". Name it for the topic: `Straight lines: weekend homework`.
4. **Read it back.** `tracker_exam_get_test(id, include_bank: true)` — the sheet as she will see
   it, with the schemes.
5. **Tell the parent** the link (`/exam/<id>`), the due date, the marks, and one line per
   question on what it is for. Then write the loose end: `tracker_update_topic(subject, ref,
   evidence: "homework #<id> set <date>, due <date>: <what it is for>", watch: "<what the sheet
   must show>; do not re-set its items")` — so a later session knows the items are out.

Nothing is pasted into a chat she can read; the sheet is on the page.

## Handing in

She photographs every page and gives them to the parent, and presses **Handed in** on the sheet
(or does not — the record does not depend on it). The parent pastes the photographs into the
tracker chat. If the sheet is still `set`, marking hands it in; to record the hand-in first,
`tracker_exam_update_test(id, action: "hand_in", fields: { handed_in_on: <date> })`.

`tracker_today` lists every sheet out (and on a Saturday that is most of what it says);
`tracker_exam_list_tests(mode: "homework")` shows set, handed in and marked, with `OVERDUE` and
`READY FOR MARKING`.

## Marking from the photographs

1. `tracker_exam_get_test(id, include_bank: true)` — every question with its scheme beside it.
2. Read her pages. Mark each question against its scheme in the subject marker's convention
   (`gcse-maths-marker` for M/A/B marks and follow-through; the English and CS markers for
   theirs). The marks are for the lines, as the sheet told her: credit method marks that are
   written, and say in the note which line was missing when one was.
3. `tracker_exam_mark_test(id, handed_in_on: <the day she did it, if the sheet is still set>,
   marks: [{ question_id, score, answer: "<what she wrote, transcribed>", note: "<why the marks
   went that way, loss=none|knowledge|technique|not_attempted>", student_feedback: "<topic and
   method hint; never the answer>", not_attempted: true|false }], note: "<the sheet as a whole:
   minutes by her timer if she said, pages, anything to carry>")`. Every question in one call;
   `not_attempted` wherever nothing was tried; `answer` short and faithful, the way the attempt
   page should show it.
4. The reply names one attempt per subject (`homework #<id>`, dated the hand-in day). Then
   **one `tracker_log_session` per subject** with a `review`: one `updates[]` entry per first
   ref with evidence `homework #<id> Q<label>: <score>/<max>, loss=… — <what happened>` and its
   `retrieval_outcome`; **no `block_key`, no `duration_minutes`** (the attempt is the record;
   the hours are hers at the weekend); no status move except a `notstarted` ref she answered on,
   under the parent rule. **No `exam-skills` session**: a homework is not a timed sitting.
5. Read back `tracker_history(subject, weeks: 1)`; tell the parent the marks and what the sheet
   showed against the brief it was set for. She reads her marks and the feedback lines on the
   sheet.

## Adjudicating

In the Friday `parent-weekly-review`, a homework marked that week is adjudicated under
"Adjudicating the Wednesday paper" with the same table: one question is one occasion of unaided
evidence on its first ref (no notes, no calculator was the rule), so it promotes only where one
occasion may decide or where it completes evidence already on the trail, and a knowledge loss on
a `secure` topic demotes. Evidence lines begin `adjudicated homework #<id> Q<label>:`. The
`why:` is the sheet's own instructions and the loose end it was set on; there are no `why:` tags
to read. Idempotent as for a paper.

The `HOMEWORK` block of `tracker_week_report(week)` lists every sheet due that week with its
status and marks; a sheet `OVERDUE, nothing handed in` is reported as a miss in waiting, not
excused.
