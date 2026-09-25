---
name: gcse-english-marker
description: >
  Mark a completed AQA GCSE English Literature (8702) or English Language (8700) paper, section
  or single response against the official mark scheme's level descriptors, producing a
  per-question record with the band and descriptor matched, a student marks sheet with targeted
  feedback and revision gaps, a percentage, and — using verified boundaries — an indicative GCSE
  grade (9–1). Covers Shakespeare, 19th-century novel, modern text, anthology and unseen poetry
  (incl. AO4 SPaG), and Language reading questions, creative and transactional writing (AO5/AO6).
  Use whenever the user wants to mark, grade or assess a GCSE English paper, mock, essay, creative
  writing piece or timed answer, or find weak skills from a script. Trigger on "mark this English
  paper", "grade this essay", "what level is this description", "mark her unseen poetry", "AQA
  8700 grade boundaries". Marked work is logged to the Education Tracker service.
---

# GCSE English Marker (AQA 8702 Literature · AQA 8700 Language)

Mark an English script the way an AQA examiner does — **holistically, against level descriptors,
best fit** — then report the marks and name the specific skills the student is losing them to.

**This subject is not marked like maths, and only two questions in the whole qualification are
points-marked.** There are no method marks, no follow-through, no "one mark per device". A
response is read whole, matched to the level whose descriptor fits it best, then placed within
that level. If you find yourself counting similes or ticking listed points, stop — you have
reached for the wrong subject's conventions.

Assessment structure, AO allocations and the level ladders: `references/aqa-8702-assessment.md`
and `references/aqa-8700-assessment.md`. Boundaries: `references/english-grade-boundaries.md`.
Cross-subject facts: `gcse-progress-tracker/references/exam-grading-common.md`.

**This skill marks AQA English and nothing else.** For maths, CS or Spanish use their own markers.

## Inputs to gather first

1. **Which qualification and which paper/section** — `8702/1` or `8702/2`, `8700/1` or `8700/2`,
   and for Literature *which set text* the response is on. A modern-text essay cannot be marked
   without knowing the text and the question chosen.
2. **The question paper and the official mark scheme** for that series. The scheme carries the
   level descriptors *and the indicative content for that specific question*; both are needed.
   For Language 2026-onward papers, the scheme carries a "typical features" column — use it.
3. **The student's response** — typed, .docx/PDF, or photographs of handwriting. Timed pieces
   should be handwritten; note whether this one was.
4. **Timing and conditions** — timed or untimed, handwritten or typed, planned or not. These go in
   the record because they change what the mark means.

Note the **paper code and series**. Single responses (one essay, one Q5) are marked and logged as
`kind: "check"`; only a whole paper or a whole mock is an attempt.

## Step 1 — Build the marking reference

From QP + MS, list every question: number, marks, AOs, the level ladder that applies, and the
indicative content. Sanity check: **marks sum to the paper maximum** — 64 (8702/1), 96 (8702/2),
80 (8700/1), 80 (8700/2). If they don't, you've misread the scheme.

Tag each question with its **tracker topic ref** from `tracker_get_state(subject: …)`, never a
plausible-looking guess. English refs are *skills and texts* (e.g. `S1` Macbeth, `P3` anthology
comparison, `R4` P1 evaluation, `W1` descriptive writing, `T2` sentence demarcation) — see the
subject appendices. Tag the skill the marks were actually lost to.

## Step 2 — Read the student's response

- `.docx` → `pandoc -t markdown`; unzip for embedded images. PDFs and photos → rasterise/view.
- **Handwriting:** read it charitably for spelling but not for punctuation — AO6/AO4 judge what is
  on the page. Where a full stop versus comma is genuinely unreadable, mark provisionally and
  say so.
- **Count blanks.** Separately count blanked *writing tasks* (Q5s, Literature essays) and blanked
  *big reading questions* (P1 Q4, P2 Q4). Skipping these is the known behavioural pattern; it is
  tracked, so guess-free counting matters.
- **Note the plan.** A written plan for Q5 or an essay is evidence of process even when the
  writing falls short. Record whether one exists.

## Step 3 — Mark the points questions (only these)

- **8700/1 Q1** (multiple choice, 4 marks) and **8700/2 Q1** (four true statements, 4 marks): right
  or wrong against the scheme. Q1 with five ticks where four were asked scores as the scheme
  directs (usually the first four).
- Nothing else in either qualification is points-marked.

## Step 4 — Mark level-of-response questions (everything else)

This is the whole discipline, and it is AQA's own method (verified against the 8700/1 2026 sample
scheme, the 8700/2 June 2023 scheme and the 8702/2 June 2025 scheme). Follow it exactly:

1. **Read the entire response and annotate it first.** Note the qualities each section shows.
   Never award as you read — bands are holistic and a weak opening is often recovered.
2. **Climb the ladder from the bottom.** AQA's instruction: start at the lowest level, check
   whether the answer meets it, move up, and stop at the first level the answer no longer meets.
   For better answers you skip the lower rungs quickly, but the direction is always upward — never
   start in the middle and never work down from Level 4/6.
3. **One descriptor once is enough to enter a level.** A student reaches a level by meeting one or
   more of its skills descriptors; not all of them. **Quality, not quantity**: it is the quality of
   the comments, not the number of references, that determines the level.
4. **Best fit, then fine-tune within the level.** Strong performance in one aspect compensates for
   weaker performance in another. Literature's rule of thumb: predominantly Level 3 with a little
   Level 4 material = Level 3, mark near the top. A response that meets *every* descriptor of a
   level sits at the top of it.
5. **Indicative content is a guide, never a checklist.** Credit valid points the scheme doesn't
   list; never require the ones it does. A response that develops two ideas deeply outranks one
   that mentions six. Examiners are told they may see responses that exceed the top descriptor —
   use the full range.
6. **Mark the AOs the question assesses, and only those.** Literature: AO1/AO2/AO3 in set-text
   questions; AO1/AO2 only in unseen. Language reading: one AO each (Q2/Q3 AO2; P1 Q4 AO4; P2 Q2
   AO1; P2 Q4 AO3). Context in an unseen answer earns nothing; language analysis in a P2 Q2
   summary earns nothing. Say so in the note — those are cheap habits to fix.
7. **Apply the scheme's caps — they are real and they are large.**
   - **Literature rubric infringement:** a response that ignores a *defining feature* of the task
     (extract-only with no whole-text; whole-text with no extract; anthology answer on the named
     poem alone with no second poem) is **capped at the top of Level 2 (10/30)**, however good.
   - **Language AO5:** a Q5 that does not directly address the focus of the task is **capped at
     the top of Level 2 (12/24)**.
   - **Language one-text answers:** P2 Q2 and Q4 need *both* sources for Level 3 or above. A
     Level-1-quality answer on one text = max 1 (Q2) / 2 (Q4); Level-2-quality on one text =
     max 3 (Q2) / 6 (Q4).
   - **Language wrong lines/source:** Q2/Q3 written about lines outside the given range (or the
     wrong source) with the right focus sit in the appropriate level but at its *bottom*, and the
     right focus is needed for Level 3+.
8. **Implicit is fine.** Reference to the writer's methods may be implicit without naming the
   writer; the evaluative "I agree/disagree" in P1 Q4 may be implicit. Credit the quality of what
   is written.
9. **Development moves levels; quantity does not.** A list of unlinked assertions sits at Level
   2–3 however long. A point carried to a consequence and tied back to the question is Level 4
   and up. Feature-spotting without effect ("this is a metaphor") is Level 2.
10. **Quote the descriptor you matched** and **name the one thing that would lift it a level.**
    That sentence is the most useful line in the teacher file.
11. **Blank ≠ low.** A blank is recorded as unattempted; an attempted answer that scores 3/30 is
    a knowledge problem. Their remedies are opposite.

### Writing tasks (8700 Q5, both papers) — two separate ladders

- **AO5 Content & Organisation (24):** four levels, Level 4 split upper (22–24) / lower (19–21),
  Level 3 upper (16–18) / lower (13–15). Judge register and form fit, the *sustainedness* of tone,
  crafting of vocabulary and devices, structural features, paragraph cohesion. A Level 4 piece is
  convincing and compelling **throughout** — one paragraph of it isn't Level 4.
- **AO6 Technical Accuracy (16):** four levels (13–16 / 9–12 / 5–8 / 1–4). Sentence demarcation
  first — consistent, secure full stops are the gate to Level 3+. Then punctuation range *used
  accurately*, sentence-form variety for effect, Standard English control, spelling incl.
  ambitious words, vocabulary range.
- Mark AO5 and AO6 **independently** — a beautifully crafted piece with comma splices throughout
  is high AO5, mid AO6, and the note says exactly that.
- Descriptive vs narrative is not a factor in the mark. Since 2026 a narrative opening is judged
  as an opening; do not penalise the absence of an ending.

### Literature AO4 (4 marks, Shakespeare and modern text only)

Marked from the same essay on a three-band ladder: **4** = high performance (consistent accuracy,
effective control of meaning); **2–3** = intermediate (considerable accuracy and range, general
control); **1** = threshold (reasonable accuracy, errors don't hinder meaning); **0** = nothing or
below threshold. Award independently of the essay level; note it separately.

## Step 5 — Produce the two output files

Write both to the outputs directory and present them. Name by audience; follow `examples/`.

- **`For-Teacher_<paper>-<series>.md`** — summary block (raw mark, %, indicative grade with its
  caveat, conditions: timed/handwritten/planned) + one row per question: the response's gist, the
  **level and descriptor matched**, mark/max per AO, what would lift it, and the tracker ref.
  Then the skills-gap list.
- **`For-Student_<paper>-<series>.md`** — must **not** contain model answers or "what you should
  have written" content. Summary block; per-question marks grid with the level reached in plain
  words; a "where marks went — what to practise" table naming the **skill and a hint only**; a
  prioritised, short revision list (three items max — she works best from short lists).

**Separation rule:** the student file names the skill ("your paragraphs identify the method but
don't say what it does"), never supplies the improved version. Model wording lives in the teacher
file and the tracker note only.

Cluster gaps by **skill, not by question**: "effect not explained after quote", "context bolted
on", "structure question answered as language", "comma splices", "mood shifted mid-description",
"plan absent". Then separate the failure types, because remedies differ:
- **Knowledge** — text or method knowledge missing (Literature recall; not knowing what Q3 asks).
- **Craft** — the idea is there, the writing doesn't deliver it (development, accuracy, form).
- **Blank / abandoned** — nothing or a fragment. Note whether it was a writing task or a big
  reading question, and whether the paper ran out of time.

## Step 6 — Compute percentage and grade

Read `references/english-grade-boundaries.md` with `exam-grading-common.md`.

1. **Percentage** = raw ÷ paper max × 100.
2. **Scale to the qualification (160 marks).** Both papers of 8700 are 80 (×2). For 8702, raw
   marks already carry the weighting: Paper 1 is 64 (40%), Paper 2 is 96 (60%) — scale a lone
   paper by 160/64 or 160/96 respectively and say so.
3. **Apply the series' subject boundaries** from the reference. Both qualifications are
   **untiered** — never write "Foundation" or "Higher".
4. **Caveats, every time:** a single paper is a noisy estimate of a two-paper qualification;
   band-marked totals are noisier than points-marked ones; a single essay or Q5 is *never*
   converted to a grade — report its level and mark only.

Never invent a boundary. If the series isn't in the reference, use the nearest listed one, say
which, and give the source URL.

## Step 7 — Log to the tracker (mandatory)

Slug `english-literature` or `english-language` — confirm with `tracker_list_subjects`.

```
tracker_log_attempt(
  subject: "english-language",
  name: "8700/1 June 2024 (timed, handwritten)",
  kind: "paper",                       // "check" for a single essay/Q5/section
  date: "2026-11-14",
  papers: [{
    code: "8700/1", score: 47, max: 80,
    blanks: 0,
    note: "Q5 planned; description sustained one mood; Q4 attempted but descriptive not evaluative.",
    questions: [
      { number: "4", score: 9, max: 20, topic_ref: "R4",
        question: "Evaluate: 'the writer makes the reader feel the boy's fear'",
        answer: "…gist of what was written…",
        note: "Level 2 (some evaluative comment, some references): describes what happens rather than evaluating how it works; two quotes with no method. Lift: attach 'this works because…' to each quote." },
      { number: "5", score: 30, max: 40, topic_ref: "W1",
        question: "Description suggested by the picture",
        answer: "…",
        note: "AO5 L4 lower 19/24 — convincing, consistent mood, structured zoom; AO6 L3 11/16 — mostly secure demarcation, two comma splices, semicolon misused twice. Lift to L4 upper: sustain the ambitious vocabulary through the final paragraph; AO6: fix splices." }
    ]
  }]
)
```

Rules that matter:
- **One sitting is one attempt.** A full mock of both papers is one call, two papers, one grade
  across 160. A lone paper is a one-paper attempt. A lone essay or Q5 is `kind: "check"`.
- **Question marks must sum to the paper total** or the service refuses — a free arithmetic check.
- **Untiered:** omit `tier`. Never invent "F"/"H".
- **Log raw marks.** Scaling belongs to grade conversion only.
- **Record blanks honestly**, with the writing-task and big-reading-question blank counts in the
  paper `note`. They are the behavioural metric.
- **Put the level and descriptor in every question note.** That is what makes the per-topic
  marks-lost list (from `tracker_get_attempt`) readable six months later.
- **Do not change topic statuses here.** `gcse-progress-tracker` adjudicates; for band-marked work
  its secure bar is the target band met unaided on *two* different tasks — one good essay is not a
  promotion. Hand over the marks-lost list as the evidence.

If the connector is unavailable, say so, still produce both files, and include the
`tracker_log_attempt` call as a ready-to-run block. Never claim something was logged when it wasn't.

## Quick reference — paper codes

`8702/1` Shakespeare + 19th-century novel · 1h45 · 64 marks (30+4 / 30) · 40%.
`8702/2` Modern text + anthology poetry + unseen poetry · 2h15 · 96 marks (30+4 / 30 / 24+8) · 60%.
`8700/1` Creative reading & writing · 1h45 · 80 marks (4/8/8/20 + 40) · 50%.
`8700/2` Viewpoints & perspectives · 1h45 · 80 marks (4/8/12/16 + 40) · 50%.
Both untiered, both 160 total, both closed book, both handwritten. Some series split 8702/1 into
1M/1N/1P booklets — same marks, same ladders.

## Common pitfalls

- Awarding marks while reading instead of reading whole then matching.
- Starting mid-ladder or marking down from the top instead of climbing from Level 1.
- Missing a rubric cap: extract-only/whole-text-only Literature answers and one-poem anthology
  answers cap at Level 2; off-focus Q5 caps AO5 at 12; one-source P2 Q2/Q4 answers cap.
- Treating indicative content as a checklist; capping a deep two-point answer.
- Marking context into an unseen-poetry answer or language analysis into a P2 Q2 summary.
- Counting devices in Q5 — AO5 rewards crafted effect and sustained tone, not device density.
- Letting AO5 quality pull AO6 up, or the reverse. They are independent ladders.
- Penalising a narrative opening for having no ending (2026 onward).
- Converting a single essay or Q5 to a grade.
- Writing "Foundation"/"Higher" anywhere — English is untiered.
- Quoting boundaries from memory; not stating the series used.
- Logging each paper of a mock as its own attempt.
- Inventing tracker refs instead of reading them from `tracker_get_state`.
- Finishing at the files and not logging.
