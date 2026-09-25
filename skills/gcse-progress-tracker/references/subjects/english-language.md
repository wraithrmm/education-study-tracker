# Subject appendix — `english-language`

Read at Step 0 whenever the resolved subject is `english-language`. **Fill the TO CONFIRM rows
before the first checkpoint.**

| | |
|---|---|
| **Slug** | `english-language` |
| **Spec** | AQA GCSE English Language 8700 (assessment updates from summer 2026 apply) |
| **Tier** | Untiered |
| **Exam series** | June 2027 — confirm dates with AQA and the centre |
| **Target grade** | TO CONFIRM |
| **Qualification total** | **160 raw marks** — Paper 1 = 80, Paper 2 = 80; no scaling |
| **Spoken Language Endorsement** | TO CONFIRM — separately reported (Pass/Merit/Distinction/Not Classified), does not affect the 9–1 grade; the *centre* must administer it. Record here whether the centre will, the date, or that it will show "Not Classified". |
| **Marker skill** | `gcse-english-marker` |
| **Session skill** | `english-tutor-session` |
| **Dashboard** | <https://education.rmmann.co.uk/s/english-language> |

## Promotion thresholds — mixed

- **Points-marked:** P1 Q1, P2 Q1 only. Default bars (70% supported / 80% unaided).
- **Band-marked:** everything else. `developing` = band below target, supported; `secure` =
  target band **unaided, twice, on different tasks**; quote the descriptor matched.
- **Writing (W refs)** is two ladders: record AO5 and AO6 levels separately in the evidence
  ("AO5 L4 lower, AO6 L3"). A topic is not secure on AO5 alone.

## Proposed strand and ref scheme (seed via `gcse-tracker-dashboard`)

| Strand | Refs |
|---|---|
| **R** Paper 1 reading | R1 Q1 retrieval (MCQ) · R2 Q2 language analysis · R3 Q3 structure (single effect) · R4 Q4 evaluation · R5 reading & annotating under time |
| **V** Paper 2 reading | V1 Q1 true statements · V2 Q2 summary/synthesis · V3 Q3 language · V4 Q4 compare viewpoints · V5 19th-century non-fiction reading |
| **W** Writing | W1 descriptive writing · W2 narrative opening · W3 planning in 5 minutes · W4 structure choices (zoom/shift/cyclical) · W5 transactional: form & audience · W6 transactional: argument line · W7 sustaining tone to the last paragraph |
| **T** Technical accuracy (AO6) | T1 sentence demarcation & comma splices · T2 punctuation range used accurately · T3 sentence variety for effect · T4 spelling incl. ambitious vocabulary · T5 paragraphing |
| **X** Exam craft | X1 timing across a paper · X2 handwritten stamina (45 min continuous) · X3 self-check in the last 5 min |
| **E** Spoken Language | E1 presentation prepared · E2 delivered (centre-administered; evidence is the parent's/centre's record) |

## Plain names — what to call each question to her (study principle 12)

The codes in the strand table are for the record. When speaking to her, use the plain name;
the mark and time may follow; the paper number is optional and last.

| Code | Plain name she hears | What the paper actually asks |
|---|---|---|
| P1 Q1 | the find-four question | list four things from these lines (4) |
| P1 Q2 | the language question | how does the writer use language to describe … (8, ~10 min) |
| P1 Q3 | the structure question | how has the writer structured the text to interest you (8, ~10 min) |
| P1 Q4 | the "do you agree" question | to what extent do you agree with this student's view (20, ~20 min) |
| P1 Q5 | the description / story-opening piece | describe … as suggested by the picture, or write the opening of a story (40, 45 min) |
| P2 Q1 | the true-statements question | choose four true statements (4) |
| P2 Q2 | the summary question | summarise the differences/similarities between the two texts (8) |
| P2 Q3 | the language question (Paper 2) | how does the writer use language to … (12) |
| P2 Q4 | the comparing-viewpoints question | compare how the writers convey their views (16) |
| P2 Q5 | the letter / article / speech piece | write a letter/article/speech/leaflet arguing … (40, 45 min) |
| AO5 | "your ideas and how you shape them" | content and organisation of writing |
| AO6 | "your accuracy" | sentences, punctuation, spelling |

## Behavioural metrics

**Blanks are the right metric here**, and two specific ones:
- **Writing-task blanks** (either Q5) — 40 marks each; any blank is a red flag reported in plain
  words.
- **Big-reading blanks** (P1 Q4, P2 Q4) — the questions she is most likely to skip.
Also track **timed + handwritten pieces per month** and the **AO6 focus** currently being drilled.

## Descriptive writing is the banker

Her strength is descriptive writing (W1). Reports should say where W1 sits against the target
AO5/AO6 bands explicitly, because it is the 25% of the qualification most likely to carry the
grade — and because a fall in it is the clearest early signal that stamina or accuracy has slipped.

## Checkpoint duties — mandatory

- **Nov 2026 — timed, handwritten P1 Q5 check:** expect AO5 Level 3 upper or better. Report the
  AO6 level alongside; if AO6 is a level below AO5, the fortnight's focus is T1–T2.
- **Dec 2026 — Paper 1 mock:** every question attempted; Q4 and Q5 at target band minus one.
- **Mar 2027 — full mock (both papers, one attempt):** target band unaided on both Q5s and both
  Q4s. Flag any blank to the parent.
- **Entry deadline ~21 Feb 2027** — remind the parent within 6 weeks; the Spoken Language
  Endorsement arrangement must be settled in writing with the centre by then.

## Grade conversion

June 2025 boundaries (of 160): 9=119, 8=109, 7=100, 6=91, 5=82, 4=73 — full table and method in
`gcse-english-marker/references/english-grade-boundaries.md`. A lone paper scales ×2; single Q5s
or essays are `kind: "check"` and never graded.
