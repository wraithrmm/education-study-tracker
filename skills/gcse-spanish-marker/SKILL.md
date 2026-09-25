---
name: gcse-spanish-marker
description: >
  Mark a completed AQA GCSE Spanish paper (specification 8692) against its official mark scheme
  and assessment criteria, then produce a per-question graded record, a student marks sheet with
  targeted feedback and revision gaps, a percentage, and — where verified boundaries exist — a UK
  GCSE grade (9–1). Handles all four components: Listening (including dictation), Reading
  (including translation into English), Writing (including translation into Spanish and extended
  writing), and Speaking. Use this skill whenever the user wants to mark, grade or assess a GCSE
  Spanish paper, mock or practice paper, or to find a student's grammar and vocabulary weaknesses
  from a script. Trigger on "mark this Spanish paper", "grade this Spanish mock", "mark her
  translation", "what grade is this Spanish writing". Marked papers are logged question by
  question to the Education Tracker service.
---

# GCSE Spanish Marker (AQA 8692)

Mark an AQA GCSE Spanish script faithfully, then report the marks and the grammar the student is
actually losing them to.

**This subject is not marked like maths and must not be marked as though it were.** There are no
method marks, no follow-through, no "or equivalent", no special cases. Roughly half of every
component is judged against **level descriptors** — you place the response in a band by best fit
and then justify the mark within that band. If you catch yourself writing "M1 for a valid method",
stop: you have reached for the wrong subject's conventions.

Assessment structure and every published criteria grid: `references/aqa-8692-assessment.md`.
Boundaries and conversion: `references/spanish-grade-boundaries.md`.
Cross-subject grading facts: `gcse-progress-tracker/references/exam-grading-common.md`.

## Inputs to gather first

1. **Question paper** — note the component and tier: `8692/1F` Listening Foundation, `/2` Speaking,
   `/3H` Reading Higher, `/4F` Writing Foundation, and so on.
2. **Mark scheme** — the matching official AQA scheme for the same paper and series. For Listening
   Section A and Reading Section A the scheme carries the accepted answers and is essential; for
   the band-marked sections the criteria in `references/aqa-8692-assessment.md` are the spine,
   and the series mark scheme supplies the worked examples and the translation element lists.
3. **Student answers** — typed, .docx/PDF export, or scans of handwriting.
4. **For a translation question, the mark scheme's element list.** The 15 elements are defined per
   series; without them the Grid-one tick count cannot be produced honestly. Say so rather than
   inventing a division.

Note the paper code and series. Both are needed for boundaries later.

**Listening cannot be marked from the paper alone if the audio is in question.** Mark the answers
against the scheme as normal; if a disputed answer turns on what was actually said, flag it rather
than adjudicating it.

## Step 1 — Establish which marking mode each section uses

Read the paper and sort every question into one of three modes before marking anything. Getting
this wrong is the single largest source of bad marks in this subject.

| Mode | Where it applies | How to mark |
|---|---|---|
| **Points** | Listening Section A, Reading Section A, Writing Q3 (grammar, F), each Role-play task | Right or wrong against the scheme's accepted answers. Award if understanding is satisfactorily communicated; accept the scheme's listed alternatives. |
| **Bands** | Dictation, all extended writing, all Speaking, Writing Q1 (per sentence, F) | Best fit against the descriptor. Read the whole response, choose the band, then the mark within it. |
| **Grids** | Translation, both directions | Grid one: count communicated elements out of 15 → mark out of 5. Grid two: a separate mark out of 5 for vocabulary and grammar across the whole response. |

Confirm the section marks sum to the paper total from `references/aqa-8692-assessment.md`. If they
don't, you have misread the paper — recheck before marking.

**Tag each question with its spec ref as you go**, and in this subject tag *two* things where they
differ: the theme the text sat in, and the **grammar point the mark was actually lost to**. Take
refs from `tracker_get_state(subject: "spanish")` rather than inventing plausible-looking ones —
an unrecognised ref is stored but drops silently out of the per-topic breakdown.

## Step 2 — Read the student's answers

- **Word (.docx):** `pandoc -t markdown file.docx`, then `unzip` and read `word/media/*` for any
  embedded images.
- **PDF:** rasterize pages and read them.
- **Images:** view directly; crop and upscale where accents or handwriting are unclear.
- **Accents matter and photographs lose them.** Where an accent is decisive for a mark and the
  scan is ambiguous, mark it provisionally and say the physical script needs checking. Do not
  penalise an accent you cannot actually see.

Map each answer to its question number and watch for blanks.

## Step 3 — Mark points-based sections

Against the scheme's accepted answers. The specification's own standard is that the mark is
awarded **if the student has satisfactorily communicated their understanding** — so mark meaning,
not spelling, in English-language answers. Accept the scheme's listed variants. For Role-play
tasks, the two-mark scale is: message conveyed without ambiguity / partially conveyed or ambiguous
/ not conveyed; where a task requires two details, failing one drops it to 1.

## Step 4 — Mark band-based sections

This is the discipline that replaces maths' method marks. For each band-marked question:

1. **Read the whole response first.** Never award as you read — bands are holistic and an early
   error can be recovered by later work.
2. **Best fit, not every-box-ticked.** Choose the band whose descriptor the response matches
   overall, then justify the mark within the band where the band spans a range.
3. **Quote the descriptor you matched** in your justification. "AO2 band 4: all three bullets
   covered, communication mostly clear with occasional lapses" is a justification.
   "Good effort, 7/10" is not.
4. **Mark AO2/AO1 and AO3 separately** where the question splits them, and apply the linkage rule:
   a zero on the content objective forces a zero on AO3, but otherwise the content mark does not
   cap the language mark.
5. **Major vs minor errors** is the distinction the AO3 grids turn on: a major error adversely
   affects communication, a minor one does not. Classify each error explicitly — this is what
   makes the mark defensible and what feeds the grammar-gap list.
6. **Time frames.** The extended-writing AO3 grids reward successful reference to two or three
   time frames. Count them and record the count; it is often the single cheapest mark to recover.

Speaking is marked from the same grids, but see Step 6 — the input problem is real.

## Step 5 — Mark translations

Both directions use the same two-grid structure.

- **Grid one:** divide the passage into the 15 elements the series mark scheme defines — not your
  own division. Tick each element whose meaning is communicated *despite minor inaccuracies*. The
  tick count converts to a mark out of 5 by the published band table.
- **Grid two:** one judgement out of 5 across the whole response for knowledge of vocabulary and
  grammar. Zero on Grid one forces zero on Grid two; otherwise Grid one does not cap Grid two.
- Record which elements were dropped. A translation is the highest-yield question in the paper for
  the gap list, because each missed element names a specific word or structure.

## Step 6 — Speaking, and the audio problem

Paper 2 is **non-exam assessment**: conducted and audio-recorded by a teacher, marked by an AQA
examiner. Two consequences that must be stated rather than worked around:

- **Audio cannot be marked here.** Mark speaking only from a transcript, and say in both output
  files that the marks derive from a transcript. **Pronunciation (the 5-mark AO3 Reading-aloud
  grid) cannot be marked from a transcript at all** — leave it unmarked and say so rather than
  guessing. Fluency, hesitation and development are also degraded on the page.
- **A private candidate needs a centre that will host the speaking test.** If the tracker's
  Spanish appendix has no centre confirmed, say so once in the teacher file: 25% of the
  qualification is unmodelled until it is.

Where a live judgement was made by the parent, record it as such — whose judgement, and that it
was live — rather than presenting it as your marking.

## Step 7 — Produce the two output files

Write both to the outputs directory and present them. Name them by audience.

- **`For-Teacher_<paper>-<series>.md`** — the full record. Summary block (raw mark per component,
  scaled mark, %, grade or "no verified boundaries", tier ceiling), then one row per question:
  student's answer, the correct answer or the band awarded, mark / max, and the criteria-tied
  justification. Then the grammar-gap list.
- **`For-Student_<paper>-<series>.md`** — handed to the student. It must **NOT contain the correct
  answers or model translations** — the point is for her to re-work them. Include the summary, a
  per-question marks grid, a "where you lost marks" table giving **the grammar point and a hint
  only**, and a prioritised revision list.

**Critical separation rule:** the student file names the structure ("preterite endings on -er
verbs"), never the answer ("should have been *comí*"). All correct answers, model versions and
justifications live only in the teacher file. Keeping them in separate files is what stops the
answer key reaching the student by accident.

The gap list is the point of the exercise. Cluster losses by **grammar point first, theme second** —
"adjective agreement", "preterite vs imperfect", "ser vs estar" are actionable; "Theme 2" is not.
Note separately whether extended writing is capped by *range* (narrow vocabulary, one time frame)
or by *accuracy* (errors in what was attempted), because the remedies are opposite: range is fixed
by learning structures, accuracy by slowing down and checking verbs.

## Step 8 — Percentage and grade

Read `references/spanish-grade-boundaries.md`. Then:

1. **Raw percentage per component** = raw ÷ component max × 100.
2. **Scale before converting.** Foundation Listening is ×1.25; everything else is ×1. The
   qualification total is **200 scaled marks at both tiers**. Never convert a raw total.
3. **A single component cannot carry a grade.** Where only one or two components were sat, report
   the component percentages and, at most, an indicative position — clearly labelled as such.
   With Speaking absent, say the projection excludes 25% of the qualification.
4. **Apply the series' subject boundaries** from the reference, or report no grade if none are
   verified. Foundation caps at grade 5.

Never invent a boundary. This specification's first exams were June 2026, so there is exactly one
real series to work from — treat it accordingly and say so.

## Step 9 — Log the attempt to the tracker (mandatory)

A marked paper that isn't logged is invisible to every later report. Record it in the **Education
Tracker** (<https://education.rmmann.co.uk>, connector "Education Tracker", subject slug `spanish`).

```
tracker_log_attempt(
  subject: "spanish",
  name: "8692/4F June 2026",      // what was sat
  kind: "paper",                   // "check" for a topic check
  tier: "F",
  date: "2026-09-03",
  papers: [{
    code: "8692/4F", score: 31, max: 50,
    blanks: 0,
    questions: [
      { number: "4", score: 7, max: 10, topic_ref: "G12",
        question: "Translation into Spanish, 5 sentences",
        answer: "…as written…",
        note: "Grid1 11/15 ticks = 4; Grid2 3 — preterite endings wrong in 2 of 5 sentences" }
    ]
  }]
)
```

Rules that matter:

- **Store raw marks, never scaled ones.** The scaling belongs in the grade conversion, not the
  record. A Foundation listening paper is `score: 32, max: 40`.
- **One sitting is one attempt**, however many components it holds. A full mock across all four
  papers is one call with four papers.
- **Question marks must sum to the paper total** or the service refuses the call — the same sum
  you checked in Step 1, so treat a refusal as a caught arithmetic error.
- **Split-objective questions:** log the question once at its total, and put the AO2/AO3 split in
  the `note`. The service totals marks per topic, and a question logged twice double-counts.
- `kind: "check"` for topic checks — never grade-converted.
- **Answers and marker notes go in the tracker.** It is the teacher-side record; the separation
  rule in Step 7 governs the file handed to the student, not the tracker.

**Do not change topic statuses here.** Marking establishes facts; promotion and demotion are
adjudicated by **gcse-progress-tracker** against its rules — and note that for band-marked work
its secure bar needs the target band met unaided on *two* different tasks, so one good essay is
never enough. Log the attempt, then hand over the per-topic marks-lost list from
`tracker_get_attempt(subject: "spanish", attempt_id: …)` as the evidence.

**If you see `tracker_log_assessment` or `tracker_list_assessments`, the connector's tool list is stale.** Those were replaced by `tracker_log_attempt` / `tracker_list_attempts` / `tracker_get_attempt`. Ask the user to remove and re-add the Education Tracker connector in Settings → Connectors to pick up the current 15 tools. Don't fall back to the old assessment tools: they no longer exist on the server, so the call will fail.

If the connector is unavailable, say so plainly, still produce both files, and include the
`tracker_log_attempt` call as a ready-to-run block. Never claim a paper was logged when it wasn't.

## Common pitfalls

- Marking band-marked work as if it were points-marked, or importing maths conventions (method
  marks, follow-through, "or equivalent") into a subject that has none of them.
- Awarding a band while reading rather than after reading the whole response.
- Converting a raw total to a grade without scaling Foundation Listening ×1.25.
- Reporting a percentage of a band-marked question as a grade.
- Marking pronunciation from a transcript.
- Inventing the 15 translation elements instead of using the series mark scheme's division.
- Penalising an accent that the scan cannot actually resolve.
- Clustering the gap list by theme when the losses were grammatical.
- Logging scaled marks, or logging a split-objective question twice.
- Projecting an overall grade while Speaking is unsat and unmodelled.
