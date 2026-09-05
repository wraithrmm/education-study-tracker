---
name: gcse-maths-marker
description: >
  Mark a completed AQA GCSE Mathematics exam paper (specification 8300) against its official
  mark scheme, then produce a per-question graded record, a concise student marks sheet with
  targeted feedback and revision gaps, a percentage, and a UK GCSE grade (9–1). Use this skill
  whenever the user wants to mark, grade, or assess a GCSE maths paper — a mock, past paper, or
  practice paper — or to convert a raw score into a GCSE grade, or to find a student's weak topics
  from a script. Trigger when the user uploads a question paper, a mark scheme, and student answers
  (typed and/or scanned/photographed), or says things like "mark this maths paper", "grade this
  mock", "what grade would this get", "mark my child's exam", or "AQA GCSE maths grade boundaries".
  Grade boundaries and grading rules are baked in — do NOT web-search them per attempt. Marked
  papers are logged question by question to the Education Tracker service.
---

# GCSE Maths Marker (AQA 8300)

Mark an AQA GCSE Maths script faithfully against the official mark scheme, then report a grade and
the student's revision gaps. Everything needed to grade and to assign a GCSE grade is bundled in
this skill — **no web research is required per marking run**. Boundaries and the maths conversion
method live in `references/gcse-grading-standards.md`; the AQA mark-scheme code system lives in
`references/marking-conventions.md`; the grading facts shared with every other subject live in
`gcse-progress-tracker/references/exam-grading-common.md`.

**This skill marks maths and nothing else.** Its conventions — method marks, follow-through,
special cases, "or equivalent", answer-line rules — are AQA Mathematics conventions and have no
meaning in a subject marked by level descriptors. For Spanish use `gcse-spanish-marker`; for any
other subject, mark from that subject's own published criteria or say plainly that you cannot.

## Inputs to gather first

A complete run needs four things. If any is missing, ask for it before marking:

1. **Question paper (QP)** — e.g. `8300/2F` (2 = Paper 2 calculator, F = Foundation; H = Higher; 1 = non-calc; 3 = calc).
2. **Mark scheme (MS)** — the matching official AQA scheme for the same paper and series.
3. **Student answers** — typed text, a Word/PDF export, and/or scanned/photographed handwriting. Drawing questions (constructions, pie charts, graphs, matching, tree diagrams) usually arrive as images.
4. **Sitting conditions** — was it timed, did she finish, and if not, the last question reached and
   minutes left. Ask the parent; if unknown, say so rather than inferring it from the blanks. Record
   it in the paper `note`: "timed 90 min, finished with 5 min left" / "untimed, stopped at 26".
   Before raising a timing flag, read the interleaving: blanks followed by full-mark answers later in
   the paper are skips, not time. From December 2026 also record the access arrangement the sitting
   ran under and the time allowance given.

Note the **paper code and series** (e.g. "8300/2F, June 2022"). Both are needed to pick the correct grade boundaries later.

## Step 1 — Build the marking reference

Read the QP and MS together and produce a compact map of every question: number, marks available, the accepted answer(s), and the key scheme notes (method vs accuracy marks, follow-through, special cases, "or equivalent", and common B0/zero traps). This is the spine you mark against.

Sanity check: **the per-question marks must sum to the paper maximum** (80 for a single 8300 paper). If they don't, you have misread the scheme — recheck before marking.

Use `references/marking-conventions.md` for what every code (M, A, B, ft, dep, SC, oe, `[a, b]`, misread, etc.) means and for the AQA marking principles you must apply.

**Tag each question with its spec reference as you go** (e.g. `A17` solving linear equations, `R7-R8` ratio). These refs are what later turn a score into teaching information, and they must match the refs the tracker actually holds — get the list from `tracker_get_state(subject: "maths")` rather than inventing plausible-looking ones. A ref the tracker doesn't recognise is stored but excluded from the per-topic breakdown, which silently loses the analysis.

## Step 2 — Read the student's answers

- **Word (.docx):** extract text with `pandoc -t markdown file.docx`. Then `unzip` the `.docx` and read `word/media/*` for embedded images (constructions, charts, diagrams). View each image; upscale/crop with PIL if a hand-drawn detail is unclear.
- **PDF:** use the pdf-reading approach (rasterize pages, read images).
- **Images:** view directly.
- Map each answer to its question number. Watch for **blank/skipped questions** — these are common near the back of the paper and are frequently the biggest source of lost marks.

## Step 3 — Mark question by question

Work through **one question at a time**, marrying QP + MS + student answer. For each, decide the marks and write a one-line justification tied to the scheme. Apply the AQA principles from `references/marking-conventions.md` — the ones that most change scores:

- **Positive marking / benefit of the doubt:** award for valid methods even if not the scheme's route; on genuine ambiguity, favour the student.
- **Method marks stand through slips:** an arithmetic slip after a correct method still earns the M mark, and often A1ft.
- **Special cases (SC):** award the exact SC marks the scheme lists for a named misinterpretation (e.g. reversed coordinates, wrong unit form).
- **"Or equivalent" (oe):** accept mathematically equivalent forms.
- **Answer-line rules:** many B marks are lost by a correct value in working but a wrong/rounded value on the answer line — check what the scheme penalises.
- **Ignore further work** once a correct answer is seen, unless it is then contradicted.
- **Misread:** a genuine copy error costs only accuracy marks (max 2); method marks remain.

If the scheme gives worked "additional guidance" rows that match the student's exact pattern (e.g. a reversed-coordinates example), apply that row's marks directly.

## Step 4 — Drawing / diagram questions (from images)

Constructions, pie charts, graphs, matching lines and tree diagrams are marked from the scan. Read the image carefully (crop/zoom as needed), award against the scheme's method/accuracy criteria (angles ±tolerance, lengths, parallel/right-angle intentions, correct matches), and **flag the mark as provisional** with a note to confirm against the physical script — hand-drawn detail from a photo carries marking uncertainty. Where the student's accompanying text shows correct reasoning, use it to support the drawing mark.

## Step 5 — Produce the two output files (For Teacher and For Student)

Write both to the outputs directory and present them. Name them by audience. Follow the templates in `examples/` exactly.

- **`For-Teacher_<paper>-<series>.md`** — the full record, for the teacher/parent only. Summary block (raw mark, %, GCSE grade, tier ceiling) + one row per question: student answer, **correct answer**, mark awarded / max, and the scheme-tied justification, plus the topic-gap list. See `examples/for-teacher-example.md`.
- **`For-Student_<paper>-<series>.md`** — the copy handed to the student. One page, at most 300 words, in this
  order: (1) **What worked** — 2–4 bullets naming a question and the behaviour that earned the mark; (2) **Blanks**
  — count and marks, the skip-vs-time reading with question numbers, then the blank question numbers with a 3–6
  word cue each and no hints; (3) **Next three things** — at most three actions, each starting with a verb, ordered
  by marks recoverable; (4) **Re-try list** — only the parts she attempted and lost marks on, at most eight rows:
  Q + cue + marks + a first-step hint of 15 words or fewer; (5) **Next session** — what it opens with and that
  re-tries will be checked; then, if the parent wants it, one plain line: "Mark: 49/80. Grade on this paper: 4."
  It must NOT contain the correct answers. Moves to the teacher file: the per-question marks grid, the percentage,
  the tier note, every boundary caveat and comparison, the topic-gap clusters beyond three, method hints for blank
  questions, and the "secure topics" list. See `examples/for-student-example.md`.

**Critical separation rule:** the For-Student file gives topics and method hints only — never the specific numeric/algebraic answer to any question (a hint like "divide top by bottom" is fine; writing "= 0.4375" is not). All correct answers and justifications live only in the For-Teacher file. Keeping them in separate files ensures the answer key is never accidentally handed to the student.

The revision-gaps section is the point of the exercise — cluster losses into topics (e.g. "straight-line graphs", "solving equations", "probability") so the student can be given focused practice. In the **teacher** file, cluster as many as the evidence supports; the student file carries at most three. Also note whether blank back-of-paper questions look like a **timing/pace** problem rather than a knowledge gap — reading the interleaving first, and saying which, with the question numbers.

### Writing the student file — rules

The student file is read by a 13-year-old on her own. It is the shortest thing that changes what she does next.

1. **One page. At most 300 words, five sections, three actions, no table longer than eight rows.** If it is longer,
   move detail to the teacher file — never to a smaller font or a denser table.
2. **Open with what worked, as behaviour.** Two to four bullets, each naming a question and the thing she did that
   earned the mark: "you wrote the first step before the answer", "you gave both forms", "you tried every question
   up to 17a". Never a trait: no "clever", "natural", "good at", "talented", "brilliant".
3. **Blanks get their own section, counted, before the wrong answers.** State the number of blank parts and the
   marks they were worth, next to the marks lost to wrong answers. Read the pattern before you name it: blanks
   followed by full-mark answers later in the paper are skips, not time — say which, with the question numbers.
   A blank gets a "first line you could have written", never a method hint. Use the tutor's words: "what's one
   thing you could write down?"
4. **At most three next actions, each starting with a verb she can do in under fifteen minutes without help.**
   "Re-try 17b and 28 without looking, then ask Claude to check them." Never "revise", "practise", "work on",
   "focus on", "look at". Order by marks recoverable.
5. **Hints are first steps, never last steps.** Fifteen words or fewer, starting with an imperative, naming only the
   first thing to write. If one step remains after your hint, it is an answer — move it to the teacher file.
6. **Every question reference carries a cue and its marks.** "17b — expanding a bracket (2)", never a bare "17b".
   Take the cue from the `question` text you log to the tracker; that text must itself contain no answer.
7. **The grade is one plain line at the end, or absent.** No hedge, no tier ceiling, no percentage, no "strong 3 /
   low 4". Those belong in the teacher file. For a single paper, ask the parent once whether a grade is shown at
   all and remember the answer.
8. **Ask, don't diagnose, about time.** Where blanks cluster at the back, end with one question for her to answer
   with her parent: "Did the time run out, or did you stop?" Never tell her to "practise pacing".
9. **Close with the next session, not a topic list.** One or two sentences: what the next session opens with, and
   that anything she re-tries before then will be checked.
10. **Voice.** Second person, present tense, sentences under twenty words. No exam-board register in the student
    file: no M1/A1/B1, ft, SC, AO2, band, tier, boundary, series, notional. "Marks" and "questions" are the only
    technical words she needs.
11. **Compare only with her own last paper, and only where it moved the right way** ("last paper 7 blanks, this
    paper 3"). Never with a target grade, a boundary, or the exam date.
12. **Read it back as her.** What is the first thing she does after reading this? If the honest answer is "closes
    it", cut until it isn't.

### Handing this over (teacher file, ≤ 60 words)

Read "What worked" aloud first. Then ask the one question in the sheet and write her answer in the tracker note.
Agree which of the three next things she will do and when — one, not three. Stop there. If she is upset by the blank
count, leave the sheet and come back tomorrow.

### Optional third output — handover brief for a study-planning agent

When the user wants a follow-on study plan / syllabus (or an agent-to-agent handover), also produce **`Handover-Syllabus-Brief_<paper>-<series>.md`** — a **stand-alone** brief written for a downstream agent that has no access to this conversation. See `examples/handover-brief-example.md`. It must contain, in order: (0) the receiving agent's task — research the current AQA spec and resources and build a remediation syllabus; (1) student profile — what's known plus explicit **TO CONFIRM** fields (age/year, target grade, timeline, hours/week, tier intention, access needs); (2) assessment context, including the limitation that one calculator paper under-samples the spec — recommend also marking Papers 1 and 3; (3) performance summary with secure areas and a timing-vs-knowledge caveat; (4) the full gap list mapped to AQA content areas and tagged blank vs misconception, then priority clusters; (5) UK curriculum/exam context (9–1, tiers, 240-mark structure, 2026 standards, formula sheet); (6) what the agent must research; (7) the deliverable spec for the syllabus (per-topic objectives, prerequisites, sequence, practice, check, time; plus an ordered schedule and re-assessment plan); (8) source URLs. Pull the grading facts and URLs from `references/gcse-grading-standards.md`. Keep it self-contained — the receiving agent should need nothing else.

## Step 6 — Compute the percentage and GCSE grade

Read `references/gcse-grading-standards.md` (the 240-mark structure, the boundary tables and the
method) alongside `gcse-progress-tracker/references/exam-grading-common.md` (the 9–1 scale,
tiering, and the never-invent-a-boundary rule). Then:

1. **Percentage** = raw mark ÷ paper max × 100.
2. **Grade (single paper):** scale the paper mark across the three-paper qualification (mark × 3 → out of 240) and apply that **series' Foundation (or Higher) subject boundaries** from the reference. Cross-check against the **notional single-paper boundaries** if listed.
3. **State caveats:** a single paper is a noisy estimate of a three-paper qualification; and boundaries differ by series. If the paper is from a lenient series (e.g. 2020–2022 pandemic years), also report what the grade would be on **restored 2026 standards** (comparable to 2025). Foundation tier caps at **grade 5**.

Never invent a boundary. If the exact series isn't in the reference, use the nearest listed series, say so, and give the URL from the reference so the user can confirm.

## Step 7 — Log the attempt to the tracker (mandatory)

A marked paper that isn't logged is invisible to every later report. Record it in the
**Education Tracker** (<https://education.rmmann.co.uk>, MCP connector "Education Tracker",
subject slug `maths`) as soon as the marking is final.

```
tracker_log_attempt(
  subject: "maths",
  name: "8300/2F June 2022",     // what was sat
  kind: "paper",                  // "check" for a topic check
  tier: "F",
  date: "2026-09-03",
  papers: [{
    code: "8300/2F", score: 49, max: 80,
    blanks: 7,                    // questions left blank — the behavioural metric
    note: "timed 90 min, finished with 5 min left; supervised rest breaks, 2 taken",
    sat_on: "2026-09-01",         // only if the sitting spanned several days
    questions: [
      { number: "4a", score: 3, max: 4, topic_ref: "A4",
        question: "Factorise 6x²+8x",
        answer: "2(3x²+4x)",                       // what she actually wrote
        note: "M1 for partial factorisation; x not taken out" }
    ]
  }]
)
```

You already have every field from Step 3 — the per-question record you built for the
For-Teacher file *is* the `questions` array. Log all of it, not just the total: the
per-question `topic_ref` is what lets `tracker_get_attempt` total marks lost per topic
and name what to reteach.

Rules that matter:

- **One sitting is one attempt**, however many papers it holds. A three-paper mock is
  **one** call with three papers and a single grade across all 240 marks — never three
  separate attempts, which would scale each 80-mark paper against the 240-mark boundary
  table on its own and report a grade for an exam only a third sat. A lone past paper is
  its own one-paper attempt. If a sitting's papers are marked on different days, log it
  once they are all marked, giving each paper its own `sat_on`.
- **The question marks must sum to the paper total** or the service refuses the call.
  This is the same sum you checked in Step 1 — treat a refusal as a caught arithmetic
  error in the marking, not an obstacle to work around.
- `kind: "check"` for topic checks. The service never grade-converts those, so a good
  result on seven topics cannot quietly become a projected grade.
- **Record blanks honestly.** Blanks per paper are the tracked behavioural metric and
  should be falling toward zero; guessing the count defeats the point.
- **Record the sitting conditions in the paper `note`** — timed or not, whether she finished,
  and from December 2026 the access arrangement and time allowance. That note is what builds the
  dated record of her normal way of working that an access-arrangement application rests on. An
  unrecorded sitting cannot be used as evidence later, and no one remembers in March.
- **Answers and marker notes go in the tracker.** It is the teacher-side record and is
  where the For-Teacher detail belongs; the separation rule in Step 5 is about the file
  handed to the student, not about the tracker.

**Do not change topic statuses here.** Marking establishes facts; promotion and demotion
are adjudicated by the **gcse-progress-tracker** skill against its rules. Log the
attempt, then hand over the per-topic marks-lost list (from
`tracker_get_attempt(subject: "maths", attempt_id: …)`) as the evidence for that decision.

**If you see `tracker_log_assessment` or `tracker_list_assessments`, the connector's tool list is stale.** Those were replaced by `tracker_log_attempt` / `tracker_list_attempts` / `tracker_get_attempt`. Ask the user to remove and re-add the Education Tracker connector in Settings → Connectors to pick up the current 21 tools. Don't fall back to the old assessment tools: they no longer exist on the server, so the call will fail.

If the connector is unavailable, say so plainly, still produce both files, and include
the `tracker_log_attempt` call above as a ready-to-run block so it can be logged later.
Never claim a paper was logged when it wasn't.

## Quick reference — paper codes

`8300/1F` `8300/2F` `8300/3F` = Foundation Papers 1–3 (grades 1–5). `8300/1H` `8300/2H` `8300/3H` = Higher Papers 1–3 (grades 4–9). Paper 1 is non-calculator; Papers 2 and 3 are calculator. Each is 80 marks; the qualification total is 240.

## Common pitfalls

- Marking only the final answer and missing method (M) marks in the working.
- Missing SC/ft opportunities the scheme explicitly allows.
- Forgetting the answer-line rules (correct working, wrong answer line = B0).
- Grading a single paper as if 80 marks maps directly to a grade — always scale to /240 and apply real boundaries.
- Quoting a boundary from memory — use the reference tables only.
- Finishing at the two files and not logging the attempt (Step 7) — the marking then never reaches the tracker.
- Logging the papers of one mock as separate attempts, which reports a grade per paper for an exam only partly sat.
- Inventing spec refs instead of taking them from `tracker_get_state` — unrecognised refs drop out of the per-topic breakdown.
