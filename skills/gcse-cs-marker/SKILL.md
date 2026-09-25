---
name: gcse-cs-marker
description: >
  Mark a completed AQA GCSE Computer Science paper (specification 8525) against its official mark
  scheme, then produce a per-question graded record, a student marks sheet with targeted feedback
  and revision gaps, a percentage, and — where verified boundaries exist — an indicative UK GCSE
  grade (9–1). Handles both components: Paper 1 (computational thinking and programming skills,
  written, in C#, Python 3 or VB.NET) and Paper 2 (computing concepts, including SQL and
  level-of-response extended answers). Use this skill whenever the user wants to mark, grade or
  assess a GCSE Computer Science paper, mock, specimen or practice paper, to mark handwritten
  program code or a trace table, or to find a student's weak topics from a script. Trigger on
  "mark this computer science paper", "grade this CS mock", "mark her Python answers", "is this
  code worth the marks", "AQA 8525 grade boundaries". Marked papers are logged question by
  question to the Education Tracker service.
---

# GCSE Computer Science Marker (AQA 8525)

Mark an AQA GCSE Computer Science script faithfully, then report the marks and name the specific
concepts, structures and exam habits the student is actually losing them to.

**This subject is not marked like maths and must not be marked as though it were.** There are no
method marks, no follow-through, no "or equivalent", no special cases. Code questions are marked
against a list of named marks for named features, with an explicit tolerance for code that would
not compile; the longest questions on Paper 2 are judged against **level descriptors**. If you
catch yourself writing "M1 for a valid method", stop — you have reached for the wrong subject's
conventions.

Assessment structure, the spec content list, the mark-scheme code glossary and the specification
version problem: `references/aqa-8525-assessment.md`.
Boundaries and conversion: `references/cs-grade-boundaries.md`.
Cross-subject grading facts: `gcse-progress-tracker/references/exam-grading-common.md`.

**This skill marks Computer Science and nothing else.** For maths use `gcse-maths-marker`; for
Spanish use `gcse-spanish-marker`; for any other subject, mark from that subject's own published
criteria or say plainly that you cannot.

## Inputs to gather first

A complete run needs four things. If any is missing, ask before marking:

1. **Question paper (QP)** — with its **language variant**. `8525A/1` = Paper 1 C#, `8525B/1` =
   Paper 1 Python 3, `8525C/1` = Paper 1 VB.NET, `8525/2` = Paper 2. A Paper 1 without its
   language variant identified cannot be marked properly, because the mark scheme carries a
   different accepted solution for each language.
2. **Mark scheme (MS)** — the matching official AQA scheme for the same paper and series. One
   Paper 1 scheme normally covers all three language variants in parallel columns or sections;
   mark against **the student's language only**.
3. **Student answers** — typed, .docx/PDF export, and/or scans of handwriting. Both papers are
   written exams, so code, trace tables, flowcharts and logic circuits usually arrive as images.
4. **Which specification version the paper was written for.** This is not optional and it is
   unique to this subject right now — see Step 1.

Note the **paper code and series** (e.g. "8525B/1, June 2023"). Both are needed for boundaries.

## Step 1 — Establish the specification version, and void off-spec questions

There are two live versions of 8525. The updated **version 1.3** (first teaching Sept 2025, first
exams June 2027) **removed content** from computer systems and networks. Papers written for the
2020 version therefore test material the student is no longer required to know.

Before marking a single question:

1. Identify the paper's series. **June 2022–2026 = old spec. Specimen/SAM "first exam 2027" and
   anything June 2027 onward = version 1.3.**
2. If the paper is an old-spec one, scan it against the removals list in
   `references/aqa-8525-assessment.md` (Von Neumann architecture, optical secondary storage, LAN
   topologies, Ethernet, Wi-Fi, UDP, FTP, the "uses of common protocols" requirement, alternative
   link-layer names).
3. **Void every off-spec question**: it is not marked, not counted as a loss, and its marks come
   out of the paper maximum. A student cannot fairly lose marks on content she was never required
   to learn, and counting those losses would corrupt both the grade and the gap list.
4. Record the voided questions and the **adjusted maximum** explicitly. Both output files and the
   tracker entry must state it. A paper marked out of 83 rather than 90 is a different paper and
   saying so is part of the marking.

If the paper is a specimen for the 2027 spec, no voiding applies — mark the whole paper.

## Step 2 — Build the marking reference and sort every question by marking mode

Read the QP and MS together and produce a compact map of every question: number, marks available,
the accepted answer(s) or the band descriptors, and the scheme's tolerance notes. This is the
spine you mark against.

Sort each question into one of **four modes**. Getting this wrong is the single largest source of
bad marks in this subject.

| Mode | Where it applies | How to mark |
|---|---|---|
| **Points** | Multiple choice, short-answer recall, definitions, single-clause SQL, binary/hex conversions, truth tables, Boolean expressions | Right or wrong against the scheme's accepted answers, applying its `A.` / `R.` / `I.` notes. |
| **Code** | Any question asking for program code, pseudo-code or an algorithm | Feature by feature against the scheme's named marks, with the compile tolerance. See Step 5. |
| **Structured working** | Trace tables, file-size and Huffman/RLE calculations, logic-circuit drawing, flowcharts | Award the scheme's stated stages; a correct final value with wrong intermediate stages does not automatically earn the stage marks, and vice versa. |
| **Bands** | Paper 2 extended-response questions (typically the ethical/legal/environmental and longer discursive items) | Best fit against the level descriptor, then the mark within the band. See Step 6. |

Sanity check: **the per-question marks must sum to the paper maximum** — 90 for each of Paper 1
and Paper 2, or your adjusted maximum from Step 1. If they don't, you have misread the scheme.
Recheck before marking.

**Tag each question with its spec reference as you go** (e.g. `3.2.10` subroutines and parameters,
`3.3.6` representing images, `3.7` SQL). These refs are what turn a score into teaching
information, and they must match the refs the tracker actually holds — get the list from
`tracker_get_state(subject: "computer-science")` rather than inventing plausible-looking ones. A
ref the tracker doesn't recognise is stored but excluded from the per-topic breakdown, which
silently loses the analysis.

Many questions are **synoptic** — a Paper 1 programming question can rest on binary or data types.
Tag the ref the mark was actually lost to, not the ref the paper filed the question under.

## Step 3 — Read the student's answers

- **Word (.docx):** extract text with `pandoc -t markdown file.docx`. Then `unzip` the `.docx` and
  read `word/media/*` for embedded images (flowcharts, trace tables, logic circuits, photographed
  handwriting).
- **PDF:** use the pdf-reading approach — rasterize pages and read them.
- **Images:** view directly; crop and upscale where indentation, operators or subscripts are
  unclear.
- Map each answer to its question number.

**Two things degrade badly in a photograph and both are load-bearing in this subject:**

- **Indentation.** In Python it *is* the block structure. AQA prints indentation grids in the
  answer booklet for exactly this reason. Where a scan leaves it genuinely ambiguous whether a
  line sits inside or outside a loop, mark the reading most consistent with the surrounding code,
  **flag the mark as provisional**, and say the physical script needs checking. Do not invent a
  structure the student may not have written, and do not penalise an indentation you cannot see.
- **Operator and character detail.** `=` vs `==`, `<` vs `≤`, `0` vs `O`, `1` vs `l`, and stray
  colons all change meaning. Where decisive and ambiguous, mark provisionally and say so.

Watch for **blank questions**. Count them. Count separately how many of the blanks are
**extended-response** questions, because those are the largest single mark blocks on Paper 2 and
skipping them is a known behaviour pattern, not a knowledge gap.

## Step 4 — Mark points-based questions

Against the scheme's accepted answers, applying its notation exactly:

- **`A.`** accept this alternative; **`R.`** reject this; **`I.`** ignore this (it neither earns
  nor costs); **`NE`** not enough on its own.
- Mark **meaning, not spelling**, in prose answers unless the scheme names the term as the mark.
  Technical vocabulary the scheme requires (e.g. "volatile", "parameter") must be present; a
  correct idea in the student's own words earns the mark where the scheme allows it.
- **Multiple choice:** one answer only. Two ticks where one was asked for scores zero, however
  right one of them is.
- **Binary, hex and data-representation questions** are points-marked but often carry a working
  mark. Award the working mark for a correct method shown even where the final value is wrong,
  **only if the scheme offers it** — do not import maths' method marks where the scheme has none.
- **SQL** is marked clause by clause. A query missing a `WHERE` still earns the `SELECT`/`FROM`
  marks if the scheme splits them. Case is normally ignored; check the scheme.

## Step 5 — Mark code, pseudo-code and algorithm questions

This is the discipline that replaces maths' method marks, and it is where most of the marking
judgement in this subject lives.

1. **Check the required response form first.** If the question specifies pseudo-code, program code
   or a flowchart, the student must answer in that form. Where pseudo-code is an *accepted* form,
   any clear and unambiguous notation is acceptable and the student need not use AQA's own
   pseudo-code. Answering in the wrong form when a form was specified loses the marks — say so in
   the teacher file, because it is a cheap habit to fix.
2. **Mark the logic, not the compiler.** AQA's schemes state that minor syntax errors of the kind
   an IDE would flag should not be penalised, because the exam is written on paper. A missing
   colon, a missing `static` in C#, `WriteLine` for `Write`, or a missing `End Sub` does not cost
   a mark unless the scheme says it does. Read the scheme's `I.` list before deciding.
3. **Award feature by feature.** The scheme names each mark against a feature — a correct
   subroutine header, a correctly initialised accumulator, the right loop type, a correct
   termination condition, correct use of a parameter, a correct return. Work down that list and
   award each one on its own merits. A wrong loop does not forfeit the marks for a correct
   condition inside it.
4. **Apply the scheme's caps.** Schemes routinely say "maximum N marks if any errors in code" or
   make a mark dependent on an earlier one. Apply the cap after totalling the feature marks, and
   state in the justification that a cap was applied and why.
5. **Design marks are separate from working code.** Under the 2027 specification Paper 1 assesses
   design, write, test and refine. Where a question awards a design (a plan, pseudo-code outline
   or flowchart), those marks stand even if the code that follows is wrong. Never let a broken
   final program suppress a correct design.
6. **Trace tables** are marked row by row or column by column as the scheme directs. A correct
   final output with an incoherent table does not earn the table marks; a correct table with a
   miscopied final output usually keeps most of them. Follow the scheme.
7. **Test data questions** turn on classification: normal (typical), boundary (extreme),
   erroneous. Award for a value in the right class with a valid justification; for a range 1–10
   the boundary values are 0, 1, 10 and 11.

Justify every code mark against the scheme's own wording — "Mark B awarded: `WHILE` with a correct
termination condition; Mark D not awarded: counter never incremented, so the loop cannot end" is a
justification. "Nearly right, 3/5" is not.

## Step 6 — Mark level-of-response questions

Paper 2's extended-response questions are banded, not point-marked.

1. **Read the whole response first.** Never award as you read — bands are holistic and a weak
   opening can be recovered later.
2. **Best fit, not every-box-ticked.** Choose the band whose descriptor the answer matches
   overall, then place the mark within that band.
3. **Indicative content is a guide, not a checklist.** The scheme says so explicitly. Credit valid
   points it does not list; do not require every point it does list. An answer covering fewer
   points in more depth can reach the top band.
4. **Development is what moves bands.** A list of unlinked assertions sits low however many
   assertions it contains. A point carried through to a consequence, applied to the scenario in
   the question, and balanced against a counter-point is what the top band describes.
5. **Quote the descriptor you matched** in the justification, and name what would have moved it up
   one band. That sentence is the single most useful thing in the teacher file.
6. **An answer with nothing relevant scores zero** — but a short answer is not automatically a
   zero. Judge relevance, not length.

Where an extended question is left blank, record it as blank in the gap list and say plainly what
it was worth. Distinguish it from an attempted answer that scored low: the remedies are opposite —
one is a confidence and technique problem, the other a knowledge problem.

## Step 7 — Diagrams and images

Flowcharts, logic circuits, trace tables and completed indentation grids are marked from the scan.
Read the image carefully (crop and zoom as needed), award against the scheme's criteria (correct
symbols and their meaning, correct gate types, correct connections, correct row values), and
**flag the mark as provisional** with a note to confirm against the physical script. Where the
student's accompanying text shows correct reasoning, use it to support the diagram mark.

Flowchart symbols carry meaning and the scheme usually requires them: a decision must be a
diamond with labelled outputs, a process a rectangle. Award the scheme's stated marks for symbol
use only where it asks for them.

## Step 8 — Produce the two output files (For Teacher and For Student)

Write both to the outputs directory and present them. Name them by audience. Follow the templates
in `examples/` exactly.

- **`For-Teacher_<paper>-<series>.md`** — the full record, for the teacher/parent only. Summary
  block (raw mark, adjusted maximum and what was voided, %, indicative grade, spec version) + one
  row per question: student answer, **correct answer or band awarded**, mark awarded / max, and
  the scheme-tied justification, plus the topic-gap list. See `examples/for-teacher-example.md`.
- **`For-Student_<paper>-<series>.md`** — the copy handed to the student. It must **NOT contain
  the correct answers or model code** — the point is for her to re-work the questions herself.
  Include a summary block, a compact per-question marks grid, a "where you lost marks — what to
  review" table giving the **concept and a hint only, never the answer or the working line**, and
  a prioritised list of revision topics. See `examples/for-student-example.md`.

**Critical separation rule:** the student file names the concept ("your loop never changes the
counter, so it can't stop"), never the answer ("should have been `count = count + 1`"). All
correct answers, model code and justifications live only in the teacher file. Keeping them in
separate files is what stops the answer key reaching the student by accident.

The revision-gaps section is the point of the exercise. Cluster losses by **concept, not by paper
section** — "loop termination conditions", "parameters vs local variables", "sample rate and file
size", "SQL WHERE clauses" are actionable; "Paper 1 Section B" is not. Then separate three
different failure types explicitly, because their remedies differ:

- **Knowledge** — the concept isn't there.
- **Precision** — the concept is there but the code, syntax or terminology is imprecise.
- **Blank** — nothing attempted. Note whether the blanks cluster at the back of the paper (pace)
  or on the extended-response questions (confidence).

### Optional third output — handover brief for a study-planning agent

When the user wants a follow-on study plan (or an agent-to-agent handover), also produce
**`Handover-Syllabus-Brief_<paper>-<series>.md`** — a **stand-alone** brief for a downstream agent
with no access to this conversation. In order: (0) the receiving agent's task; (1) student profile
with explicit **TO CONFIRM** fields (age/year, target grade, timeline, hours/week, chosen
programming language, access needs); (2) assessment context — which paper was sat, that a single
paper samples only half the qualification, and the specification-version position; (3) performance
summary with secure areas and a pace-vs-knowledge caveat; (4) the full gap list mapped to AQA
content refs 3.1–3.8 and tagged knowledge/precision/blank, then priority clusters; (5) UK context
(9–1, untiered, 180-mark two-paper structure, written exams with handwritten code, first exams for
v1.3 in June 2027); (6) what the agent must research; (7) the deliverable spec for the syllabus;
(8) source URLs from `references/aqa-8525-assessment.md`. Keep it self-contained.

## Step 9 — Compute the percentage and the grade

Read `references/cs-grade-boundaries.md` alongside
`gcse-progress-tracker/references/exam-grading-common.md`. Then:

1. **Percentage** = raw mark ÷ paper max (or the adjusted max from Step 1) × 100.
2. **Scale to the qualification:** two equally weighted 90-mark papers, **180 marks total**. A
   single paper scales `mark × 2` → out of 180. If the maximum was adjusted, scale the
   *percentage* to 180 instead and say that is what you did.
3. **Apply the series' subject boundaries** from the reference, using the row for the student's
   **language option** (8525A/B/C are set separately).
4. **State the caveats, every time.** Computer Science is **untiered** — never write "Foundation"
   or "Higher". A single paper is a noisy estimate of a two-paper qualification. And there are
   **no boundaries for the 2027 specification**: any grade for a v1.3 specimen is borrowed from an
   earlier series on a different content base, and must be labelled indicative.

Never invent a boundary. If the exact series or option isn't in the reference, say so, use the
nearest listed row, and give the URL from the reference so the user can confirm.

## Step 10 — Log the attempt to the tracker (mandatory)

A marked paper that isn't logged is invisible to every later report. Record it in the **Education
Tracker** (<https://education.rmmann.co.uk>, MCP connector "Education Tracker", subject slug
`computer-science` — confirm with `tracker_list_subjects` rather than assuming) as soon as the
marking is final.

```
tracker_log_attempt(
  subject: "computer-science",
  name: "8525B/1 June 2023 (Python)",
  kind: "paper",                   // "check" for a topic check
  date: "2026-09-05",
  papers: [{
    code: "8525B/1", score: 54, max: 90,
    blanks: 2,                     // questions left blank — the behavioural metric
    sat_on: "2026-09-04",          // only if the sitting spanned several days
    note: "Both blanks were written-explanation questions (Q11, Q20, 7 marks).",
    questions: [
      { number: "17", score: 3, max: 5, topic_ref: "3.2.2",
        question: "Write a program that totals the even numbers from 1 to 100",
        answer: "…as written, indentation as read from the scan…",
        note: "Mark A (loop), Mark B (condition) and Mark D (output) awarded; Mark C not — accumulator never updated inside the loop." }
    ]
  }]
)
```

For a **Paper 2 with off-spec questions voided**, the same call carries the adjusted maximum, the
voided questions are absent from the `questions` array, and the voiding is named in both `name`
and `note` — e.g. `name: "8525/2 June 2023 (3 off-spec Qs voided)"`, `code: "8525/2", score: 51,
max: 83`, `note: "Q6 (LAN topologies), Q14b (Ethernet), Q19 (Von Neumann) voided as removed in
v1.3 — 7 marks excluded from the maximum."`

You already have every field from Step 2 — the per-question record you built for the For-Teacher
file *is* the `questions` array. Log all of it, not just the total: the per-question `topic_ref`
is what lets `tracker_get_attempt` total marks lost per topic and name what to reteach.

Rules that matter:

- **One sitting is one attempt**, however many papers it holds. A full mock across Papers 1 and 2
  is **one** call with two papers and a single grade across all 180 marks — never two separate
  attempts, which would scale each 90-mark paper against the 180-mark boundary table on its own
  and report a grade for an exam only half sat. A lone past paper is its own one-paper attempt.
- **The question marks must sum to the paper total** or the service refuses the call. This is the
  same sum you checked in Step 2 — treat a refusal as a caught arithmetic error, not an obstacle.
  **Voided questions are excluded from the `questions` array entirely** and the `max` is reduced
  to match; the `name` and a `note` record what was voided and why.
- **Computer Science is untiered.** Omit `tier`. If the service refuses the call without it, take
  the subject's own value from `tracker_list_subjects` — never invent "F" or "H".
- **Log raw marks, never scaled ones.** Scaling belongs in the grade conversion, not the record.
- `kind: "check"` for topic checks. The service never grade-converts those, so a good result on
  three strands cannot quietly become a projected grade.
- **Record blanks honestly**, and put the extended-response blank count in the paper `note`.
  Blanks are the tracked behavioural metric and should be falling toward zero; guessing the count
  defeats the point.
- **Answers and marker notes go in the tracker.** It is the teacher-side record and is where the
  For-Teacher detail belongs; the separation rule in Step 8 is about the file handed to the
  student, not about the tracker.

**Do not change topic statuses here.** Marking establishes facts; promotion and demotion are
adjudicated by the **gcse-progress-tracker** skill against its rules — and note that for
band-marked extended responses its secure bar needs the target band met unaided on *two*
different tasks, so one strong essay is not a promotion. Log the attempt, then hand over the
per-topic marks-lost list (from `tracker_get_attempt(subject: "computer-science", attempt_id: …)`)
as the evidence for that decision.

**If you see `tracker_log_assessment` or `tracker_list_assessments`, the connector's tool list is
stale.** Those were replaced by `tracker_log_attempt` / `tracker_list_attempts` /
`tracker_get_attempt`. Ask the user to remove and re-add the Education Tracker connector in
Settings → Connectors to pick up the current tools. Don't fall back to the old assessment tools:
they no longer exist on the server, so the call will fail.

If the connector is unavailable, say so plainly, still produce both files, and include the
`tracker_log_attempt` call above as a ready-to-run block so it can be logged later. Never claim a
paper was logged when it wasn't.

## Quick reference — paper codes

`8525A/1` Paper 1 in **C#** · `8525B/1` Paper 1 in **Python 3** · `8525C/1` Paper 1 in **VB.NET** —
computational thinking and programming skills, written, 2 hours, 90 marks, 50%, content 3.1–3.2.
`8525/2` Paper 2 — computing concepts, written, 1h 45m, 90 marks, 50%, content 3.3–3.8, includes
SQL and extended response. Qualification total **180 marks. Untiered: grades 1–9 from either
paper.** Both papers are written; no computer is used in either.

## Common pitfalls

- Marking an old-spec paper without voiding the removed content, so the student loses marks on
  material she is not required to know and the gap list sends her to revise it.
- Marking Paper 1 against the wrong language's section of the mark scheme.
- Penalising syntax the scheme's `I.` list explicitly ignores — the compile tolerance exists
  because the exam is handwritten.
- Letting a broken final program suppress the separate design marks.
- Point-marking an extended-response question, or band-marking a points question.
- Treating indicative content as a checklist and capping a deep answer that covered fewer points.
- Reading indentation off a photograph as if it were certain, instead of marking it provisionally.
- Writing "Foundation" or "Higher" anywhere — this subject has no tiers.
- Quoting a boundary from memory, or attaching a legacy boundary to a 2027-spec specimen without
  labelling it indicative.
- Grading a single paper as if 90 marks maps to a grade — scale to /180 and apply real boundaries.
- Finishing at the two files and not logging the attempt (Step 10).
- Logging the two papers of one mock as separate attempts, which reports a grade per paper for an
  exam only half sat.
- Inventing spec refs instead of taking them from `tracker_get_state` — unrecognised refs drop out
  of the per-topic breakdown.
