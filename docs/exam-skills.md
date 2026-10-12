# Exam skills — service contract

The specification the service is built against (`php/lib/exam.php`,
`php/lib/mcp_exam.php`, `php/lib/dashboard_exam.php`, the `exam skills`
section of `Store`, the `/exam` routes in `php/index.php`), and the decisions
taken where the specification left room. The skill pack (`exam-question-generator`
for the parent's project, `exam-practice-session` for the student's) is written
to §7 and shipped as files under `skills/` in this repository, to be uploaded to
the skills plugin by hand; the rest of the pack lives with the skills.

Written against schema step 14 and the `mcp.php` tool set as of this change;
§9 adds homework mode, schema step 16.

## 0. Implementation notes — where the service decided

- **Question labels run 1..n across the whole paper**, in section order, the way
  a paper numbers its questions. The label is what the nav strip shows and what
  the attempt records as the question number, so it has to be unique across
  the sitting. Per-section numbering was the alternative and would have made
  "Q2" ambiguous.
- **The timer closes at the deadline exactly.** A test the timer ends has
  `closed_at = deadline_at` whenever the request that notices it arrives, so
  the sat minutes are the duration. A person handing in early closes at now.
  A person handing in after the deadline is the timer closing late.
- **Ten seconds of grace** on saves (`EXAM_GRACE_SECONDS`): an answer in flight
  when the clock hits zero is taken; one fifteen seconds late is refused with a
  409 and the page reloads into the closed view.
- **The sitting token is in the page, not a cookie.** Start mints a random
  token stored on the row and echoed in the page's data block; every answer
  and the hand-in carry it. A stranger with the link cannot write into a
  sitting they did not start. She has no login and needs none.
- **Start is open to anyone with the link on the day**, the same trust the
  board already extends ("readable by anyone with the link"). The parent, signed
  in, can start or hand in any test on any day — for a walkthrough, or a
  Wednesday that moved.
- **An open test past its deadline is evidence.** `evidenceBetween` reads a
  closed or marked test, and an open one whose `deadline_at` has passed, as
  type `exam` — an inference, not a write, so the board is right even when no
  page has been loaded since the clock ran out. The next request of any kind
  (a page, `tracker_exam_list_tests`, `tracker_exam_get_test`) closes it for
  real, `closed_by = timer`.
- **The exam row binds at `00:00`.** Evidence binds greedily by `[at, type,
  id]`, and a session logged on a later day for the sitting date sorts at
  `00:00`. The exam row is placed at `00:00` too — `exam` sorts before
  `session` — so the sitting is always the block's evidence and a session
  written about it is always an extra. That is why the technique session is
  logged with **no `block_key` and no `duration_minutes`**: with a key it would
  claim the block in pass 1 and the block would read `done_shape_unmet`; with a
  duration the hour would be counted twice.
- **`Store::addAttempt` runs inside `Store::transaction()`** rather than
  opening its own PDO transaction, so marking can write the marks, one attempt
  per section and the status flips as one unit. Called alone it behaves as
  before.
- **A mark scheme is withheld while a test is `ready` or `open`.**
  `tracker_exam_get_test` returns the questions of an unsat test only with the
  schemes removed, unless `include_bank: true` is passed — which the parent's
  project uses to check a paper before the day and the student's never sends.
- **An edit sends a vetted question back to draft**: what was checked is no
  longer what is stored. A question in a test (scheduled, answered, marked)
  is edited in place and keeps its status and its place — the generator
  relies on this to write its `why:` and `wk:` tags after scheduling — and
  its marks cannot drop below a score already given; it cannot be retired
  until it is taken out of the test. What she sat is fixed in the attempt,
  which keeps the copy it was marked on.
- **Blanks are derived, never declared** — in a sitting, as first built: an
  answer is blank when nothing but whitespace was written in the answer box;
  working alone is not an answer. The attempt's paper carries the count, and
  the answer column reads `(blank)`. Step 16 (§9) adds the marker's
  `not_attempted` beside it, and on paper that declaration is the only blank
  there is.
- **The attempt is a `check`.** One per subject section, tier taken from the
  subject, `date` and `sat_on` the local date of `started_at`, paper code
  `exam-skills #<test>`, questions numbered by label with the first topic ref
  as the topic, the typed answer (and working) as the answer, the marker's
  note as the note. Never grade-converted, like every check.
- **Text only.** Questions, schemes and feedback are restricted Markdown
  rendered by `exam_md()` — paragraphs, bold, italic, inline and fenced code,
  `-` and `1.` lists, `^` superscripts, hard breaks — escaped first, so no
  markup in the record can reach the page. No images, no MathJax; the
  generator's checklist requires diagram-free questions or a description.

---

## 1. Design principles

1. **The bank is the parent's.** Questions are written, vetted and scheduled
   in the parent's project. The student meets a question once, on the day, on
   the portal, and again only when she reads her marked paper.
2. **The sitting is the server's.** Start sets the deadline; the page counts
   down from it and re-reads the server's clock on every save; the server
   closes the test at the deadline whether or not a page is open. No pause,
   no extension, no browser storage.
3. **Marking writes ordinary attempts.** One `check` attempt per subject, so
   every existing report — the attempt page, the per-topic breakdown, the
   weekly review's attempts line, the synthesis inputs — reads the sitting
   without knowing the exam tables exist.
4. **Technique is a subject.** `exam-skills` holds the topics a sitting shows
   something about — pacing, command words, showing working, never blank — and
   its sessions carry what the sitting showed. The marks belong to the real
   subjects.
5. **Private by default.** Schemes, model answers, marker's notes and the bank
   render only behind the parent gate (`$isParent`); every other render goes
   through `exam_student_view()`.
6. **No duplication across skills.** Marking conventions stay in the subject
   markers' references; teaching rules in `study-principles.md`; the block
   contract in `timetable.md`. The two new skills cite them.

## 2. Vocabulary

| Term | Meaning |
|---|---|
| question | One exam-style question with its mark scheme, model answer, marks, command word, calculator flag, time guide, tags and source note; for one real subject and one or more of its topic refs. |
| bank | Every question, in every status. |
| test | One sitting: vetted questions in subject sections, one duration, one timer, one date, one block. |
| section | The questions of one subject inside a test, in order, with a suggested time. |
| label | A question's number on the paper, 1..n across the sections. |
| sitting | The test between Start and its close. |
| blank | An answer box with nothing but whitespace in it. |
| flag | Her mark on a question to come back to; kept with the answer. |
| attempt | The existing record: one per subject section once the test is marked. |

## 3. Data model — migration step 14

All additive except the `timetable_blocks` rebuild, which carries every row
and id across the wider kind CHECK.

- `TIMETABLE_KINDS` gains `exam_practice`; `block_kind_rules` gains its row:
  `satisfied_by any`, shape `[{evidence_type: exam}]`, `review_required` false.
- `exam_questions` (id, `client_key` UNIQUE, subject_slug, topic_refs_json,
  paper_style, marks CHECK > 0, calculator, command_word, question_md,
  mark_scheme_md, model_answer_md, time_guide_seconds, tags_json, source_note,
  status ∈ draft/vetted/scheduled/answered/marked/retired, created_at,
  vetted_at, note).
- `exam_tests` (id, name, scheduled_for, block_key, duration_minutes CHECK > 0,
  instructions, status ∈ ready/open/closed/marked, started_at, deadline_at,
  closed_at, closed_by ∈ timer/student/parent, marked_at, sit_token, note).
- `exam_test_questions` (test_id, question_id, section, position, label,
  section_minutes_guide; UNIQUE (test_id, question_id); UNIQUE (question_id) —
  a question is sat once).
- `exam_answers` (test_id, question_id, answer, working, flagged,
  time_spent_seconds, saved_at, score, marker_note, student_feedback,
  attempt_question_id; PRIMARY KEY (test_id, question_id)).

Timestamps are UTC like `played_at`; the sitting date is the local date of
`started_at` (`exam_sat_date()`), and every record of the sitting carries it.

## 4. State machines

**Question.** `draft` →(vet)→ `vetted` →(schedule)→ `scheduled` →(the test
closes)→ `answered` →(the test is marked)→ `marked`. `draft`/`vetted` →(retire)→
`retired`. `vetted` →(edit)→ `draft`. Nothing moves a question out of
`scheduled`, `answered` or `marked` except the test.

**Test.** `ready` →(Start: her on the day, or the parent)→ `open` →(the
timer at the deadline; her hand-in; the parent)→ `closed` →(marking)→
`marked`. Nothing reopens a test.

## 5. Tools

- `tracker_exam_add_questions(questions[])` — validates every question, then
  writes them in one transaction. Refuses: subject `exam-skills`; an unknown
  topic ref; a `client_key` repeated in the call; marks outside 1–40; text over
  4000 characters. A `client_key` already stored is a no-op line naming the
  existing id.
- `tracker_exam_list_questions(subject?, status?, tag?, limit?)` — the bank,
  one line each, with the status counts.
- `tracker_exam_update_question(id, action, note?, fields?)` — `vet`, `edit`
  (fields: topic_refs, marks, question_md, mark_scheme_md, model_answer_md,
  paper_style, calculator, command_word, time_guide_seconds, tags,
  source_note), `retire`. Editable at any status: in the bank an edit returns
  it to draft; inside a test it is corrected in place, keeps its status, and
  its marks cannot drop below a score already given. Retire refuses a
  question inside a test until it is taken out.
- `tracker_exam_schedule_test(name, scheduled_for, duration_minutes,
  sections[], block_key?, instructions?, note?)` — refuses a section subject
  of `exam-skills`, a repeated subject, a question that is missing, of another
  subject, not vetted or already used, a block that does not run that day or
  does not belong to `exam-skills` (`mcp_check_block`), a duration outside
  10–120, and a second `ready`/`open` test on the date. Warns when the section
  guides exceed the duration.
- `tracker_exam_update_test(id, action, fields?, requeue?, note?)` — `edit`:
  a `ready` test may change name, scheduled_for, duration_minutes, block_key
  (null clears), instructions, note and sections (the whole list, validated as
  for scheduling; questions already in the test may stay, dropped ones go back
  to vetted); an `open` test name, instructions, note and duration_minutes
  (deadline = started_at + duration; a deadline already past closes it by the
  timer); `closed`/`marked` name, instructions and note. `cancel`: deletes an
  unmarked test with its answers; questions → vetted if it was never started,
  else retired unless `requeue`. Refuses to cancel a marked test.
- `tracker_exam_list_tests(status?, from?, to?, limit?)` — closes overdue open
  tests first; flags `closed` as READY FOR MARKING and `ready` with its URL.
- `tracker_exam_get_test(id, include_bank?)` — closes an overdue test first;
  sections, questions, answers, working, flags, time per question, blanks;
  schemes and model answers when the test is closed or marked, or
  `include_bank`.
- `tracker_exam_mark_test(id, marks[], note?)` — only from `closed`; every
  question exactly once; score ≤ marks. One transaction: marks saved, one
  attempt per section, `attempt_question_id` filled, questions and test →
  `marked`. The reply names the attempts and tells the skill which sessions to
  log and how.
- Extended: `tracker_today` (an `exam` tag on the block — `marked` or `closed,
  awaiting marking`; and `test #n ready at /exam/n` on the day), `mcp_absent`
  (`no exam-practice test sat`), `tracker_week_report` (`EXAM PRACTICE`: one
  line per test with its section marks once marked).

## 6. Pages and who sees what

| Status | Everyone | Parent additionally |
|---|---|---|
| `ready` | name, date, sections with marks and minutes, instructions, duration, **Start** (on the day) | Start on any day |
| `open` | the questions, answer and working boxes, flags, the countdown, the nav strip, hand in | the scheme and model answer under each question |
| `closed` | "handed in, awaiting marking", her answers, working, time per question, blanks counted | schemes, model answers |
| `marked` | score per question, section and paper totals, the feedback line, a link to each subject's attempt | schemes, model answers, marker's notes, the test note |

- `/exam` — every test, newest first, with its status. Public.
- `/exam/bank` — every question with its scheme, filterable by subject, status
  and tag. Parent only (redirects to `/login`).
- The Wednesday chip on the board links to the day's `ready` or `open` test.
- `POST /exam/{id}/start` — `ready` only; her on the day, the parent any day.
- `POST /exam/{id}/answer` (JSON `{token, answers[]}`) — guarded by
  `exam_guard_write()`: open, token, deadline + grace; unknown question ids are
  ignored. Replies with the server's clock so the page re-syncs.
- `POST /exam/{id}/submit` — her with the token (`by: student`, or `timer`
  from the page at zero), or the parent signed in.

## 7. Skill contract

What the skills are written to.

- **`exam-question-generator`** (the parent's Exam Paper Builder project;
  `skills/exam-question-generator/`): runs the **weekly build unattended** —
  a Sunday scheduled task, with a Tuesday check — so a `ready` test always
  exists before the Wednesday block. Reads the block from
  `tracker_get_timetable` and the day-off records; closes out the last paper
  (`tracker_exam_list_tests`, carrying an unsat one forward with
  `tracker_exam_update_test`); reads the flags — `tracker_get_state`,
  `tracker_review_queue`, `tracker_retrieval_due`, `tracker_signals` for
  `exam-skills`, the last three papers — and chooses subjects by a fixed
  rotation and questions by a selection code per flag; writes what the bank
  lacks with `tracker_exam_add_questions` under stable client keys (tagged
  `auto` when unattended, at most 12 a run), vets against its checklist with
  `tracker_exam_update_question`; builds the paper with
  `tracker_exam_schedule_test`; then records why each question is there as
  tags `why:<code>` and `wk:<ISO week>` with the flag as the note, through an
  in-place `tracker_exam_update_question(action: "edit")` on each question
  (re-sending its existing tags), never in the test's `instructions` or
  `note`; reads the test back with `include_bank` before reporting. Never
  pastes a question into a chat the student can see.
- **`gcse-progress-tracker`** (the parent's projects; `skills/gcse-progress-tracker/`):
  **adjudicates** the marked paper — in the Friday review, or as the first
  step of the Sunday build if Friday did not — reading `tracker_exam_get_test`,
  `tracker_exam_list_questions(tag: "wk:…")` for the `why:` codes, and
  `tracker_history` per ref, and writing `tracker_update_topic` with evidence
  beginning `adjudicated paper #<id> Q<label>:`. One paper question is one
  occasion of unaided evidence and moves a status only where the existing
  rules let one occasion decide or where it completes evidence already on the
  trail; a knowledge loss on a secure topic demotes it, a technique loss or a
  non-attempt moves nothing on the subject topic. Idempotent: a ref whose
  history already holds the line is skipped.
- **`parent-weekly-review`** (the parent's project; `skills/parent-weekly-review/`):
  reads the `EXAM PRACTICE` block of `tracker_week_report`, runs the
  adjudication above in the same turn for a paper marked that week, reads
  `tracker_exam_list_tests(status: "ready", from: <next Monday>)`, and
  reports non-attempts, what moved, and whether next week's paper exists
  (`NOT SCHEDULED` is named as a miss-in-waiting).
- **`exam-practice-session`** (the student's project; `skills/exam-practice-session/`):
  *Sit* — `tracker_today` and `tracker_exam_list_tests(status: ready)`, then
  the link and three rules; never a question in the chat. *Mark* — on "ready
  for marking": `tracker_exam_get_test`, mark against each scheme in the
  subject's convention, `tracker_exam_mark_test` with a score, a marker's note
  and student-safe feedback per question. An answer that is empty or whose
  whole text is a refusal to attempt ("skip", "pass", "idk" …) with no working
  is a **non-attempt**: scored by the scheme, counted with the blanks in the
  notes and the technique session whatever the portal's blank count says
  (the service counts only an empty box — Phase 2 item S3 closes that gap).
  Then one `tracker_log_session` per subject (a `review`, one update per ref
  with evidence `paper #<test> Q<label>: <score>/<max>, loss=<none|knowledge|
  technique|not_attempted> — …` and its `retrieval_outcome`, **no promotion
  or demotion** except a `notstarted` ref she answered on, which moves under
  the parent rule; no `block_key`) and one for `exam-skills` (no `block_key`,
  no `duration_minutes`) carrying the technique observations and `updates[]`
  on the T/R/A/S refs. Tells her the marks per section and the feedback lines;
  never the answers. Never sees the bank, `include_bank` or the `why:` tags.
- **The other skills in this repository** that the paper touches:
  `gcse-progress-tracker/references/timetable.md` and `block-kinds.md` carry
  `exam_practice` and the `exam` evidence type; `term-planner` owns the block
  and records a change of paper length in the Builder project's
  instructions; `weekly-synthesis` writes the technique test design onto the
  `exam-…` signal the build reads; `retrieval-block-session` — a timed paper
  is still not its job; the subject tutor skills — a Wednesday afternoon
  question about the paper goes to `exam-practice-session`.

## 8. Known limits

- **One connector identity.** The service cannot tell the parent's project
  from the student's. Bank privacy on the connector is by contract; the
  portal's is real.
- **Start is open on the day to anyone with the link**, like the board. The
  token then keeps strangers out of the sitting.
- **Text only** — no diagrams, no rendered maths beyond superscripts.
- **Time per question is what the page measured while a question had focus.**
  A guide, not a stopwatch: it survives a reload but not a closed laptop.
- **One sitting per date**, and one unmarked homework per subject per due date.
- **Homework ticks are browser-local.** The record knows set, handed in and
  marked; it does not know which questions she has ticked.

## 9. Homework mode — schema step 16

The test row has a second mode. `mode = 'homework'` is a sheet, not a sitting:
the same bank, the same marking into attempts, none of the timer.

**Why not just a test.** A sitting is the server's — Start, the deadline, the
answer boxes, the token, auto-close. Homework is on paper over a weekend, with
a timer she sets herself, handed in as photographs. Pretending a sitting
happened would derive every answer as blank, date the work by the parent's
click, code the attempt `exam-skills` and ask for a technique session. So the
sitting half is skipped and the bank and marking halves are kept.

**Vocabulary.** A homework is *set* (`ready`), *handed in* (`closed`) and
*marked*; it never passes through `open`. `scheduled_for` is the day it is
set and shown; `due_on` is the day it is due; `duration_minutes` is the guide
she reads ("about 25 minutes"), `HOMEWORK_MIN_MINUTES`–`HOMEWORK_MAX_MINUTES`.

**Schema.** `exam_tests.mode` (`sitting` default, CHECK on `EXAM_MODES`),
`exam_tests.due_on`, `exam_answers.not_attempted`; an index on `due_on`. Step
16 adds the columns in place; `createExamTables` carries them for a fresh
database. Nothing is rebuilt.

**Rules the service enforces.**

- `due_on` required and on or after `scheduled_for`; no `block_key` (a
  homework sits outside the timetable); the guide's own range; `due_on` is
  refused on a sitting.
- One unmarked homework per **subject per due date**
  (`Store::examHomeworkClash`). A sitting on the same date does not clash
  with a homework, nor a homework with it: `examTestOnDate` reads sittings
  only.
- `mode` is fixed: cancel and schedule the other kind.
- A homework cannot be started (`startExamTest` refuses; `/exam/{id}/start`
  redirects). `POST /exam/{id}/handin` — anyone with the link, like Start —
  moves it `ready → closed`, `closed_by` student or parent. The tool's
  `hand_in` action does the same from the parent's chat, dated
  `handed_in_on`; `tracker_exam_mark_test` on a set homework hands it in
  itself, so the photographs arriving is the hand-in.
- A homework is **not `exam` evidence**: `evidenceBetween` reads sittings
  only, and the Wednesday chip (`examTestsBetween`) sees sittings only. Its
  marked attempts are the evidence, dated the hand-in day, and appear as
  extra rows on that day — the weekend has no blocks.
- The sitting date (`exam_sat_date`) of a homework is the local date of
  `closed_at`; before hand-in, `due_on`.
- Cancelling a homework retires its questions when `scheduled_for` is on or
  before today — the sheet has been on the page — and returns them to vetted
  when it is still in the future, as for a paper she has or has not started.

**Blanks.** In a sitting a blank stays derived from the empty box; the marker
may now also declare `not_attempted` (closing Phase 2 item S3), and either
counts. On paper there is no box, so a homework's blank is **only** the
marker's `not_attempted`, and an unmarked homework has none.
`exam_answer_blank()` holds both rules; `exam_section_totals()` reads the mode
off the section, which `Store::examTest` stamps on it.

**Marking.** `marks[]` entries take `not_attempted` in either mode and
`answer` — the marker's transcription of what she wrote — in homework mode
only (a sitting's answers are what she typed; `saveExamMarks` keeps a null
`answer` from overwriting them with `COALESCE`). The attempt is one `check`
per subject as for a sitting, coded `homework #<id>`, named after the sheet,
dated the hand-in day, with the transcription as the question's answer
(`(not attempted)` or `(on paper)` when there is none). The reply asks for
one subject session per section with the review, no `block_key`, no
`duration_minutes`, and **no `exam-skills` session**.

**Pages.** `/exam/{id}` for a homework is the sheet: the instructions as
"Before you start", the questions in full from the day it is set, a tick per
question kept in `localStorage` only (hers, like the bell), a **Handed in**
button, and a print stylesheet. Handed in: the sheet with a waiting note.
Marked: score per question, "what you wrote, as read from the photo", the
feedback line, totals, the attempt link. The parent additionally sees scheme,
model answer, marker's note and the bank line, through `$isParent` as for a
paper; everyone else goes through `exam_student_view()`. `/exam` lists
homework with a `homework` chip and its due date; the board carries a
homework line under this week when any is set or handed in and unmarked.

**Tools.** `tracker_exam_schedule_test(mode, due_on)`;
`tracker_exam_update_test(action: hand_in, fields.handed_in_on)` and, for a
set homework, `fields.due_on`; `tracker_exam_list_tests(mode)`;
`tracker_exam_mark_test(handed_in_on, marks[].answer, marks[].not_attempted)`;
`tracker_today` lists homework out (and on a day with no blocks — the
weekend — that is most of what it says); `tracker_week_report` gains a
`HOMEWORK` block, by due date, with the marks once marked and `OVERDUE` or
`READY FOR MARKING` otherwise.

**Skills.** `gcse-progress-tracker` sets homework from the parent's tracker
chat and marks it from the photographs (`references/homework.md`);
`exam-question-generator` writes the questions to its checklist and may build
a sheet on request; `exam-practice-session` points her at the sheet and never
marks it; `parent-weekly-review` reads the `HOMEWORK` block.
`homework-session` runs the sheet in her Homework project: it opens every
conversation on the sheet that is out, helps only with method on a different
example, never checks an answer, and logs the sit-down as an extra session
with no `block_key` — `unfinished` when she stops before hand-in, which the
marking session resolves. The subject tutor skills name a `set` sheet in
their first message (`references/timetable.md`, "Opening") and never work
its questions.
