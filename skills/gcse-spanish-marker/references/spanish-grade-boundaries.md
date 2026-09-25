# AQA GCSE Spanish 8692 — grade conversion

Cross-subject grading facts (the 9–1 scale, tiering, comparable outcomes, the 2026 standard) live
in `gcse-progress-tracker/references/exam-grading-common.md`. This file holds only what is specific
to 8692.

## 1. What boundaries apply to

**The total scaled mark of 200**, at both tiers. Scale first:

| Component | F raw | F factor | H raw | H factor | Scaled |
|---|---|---|---|---|---|
| Listening | 40 | ×1.25 | 50 | ×1 | 50 |
| Speaking | 50 | ×1 | 50 | ×1 | 50 |
| Reading | 50 | ×1 | 50 | ×1 | 50 |
| Writing | 50 | ×1 | 50 | ×1 | 50 |

Converting a raw Foundation total of 190 against a 200-mark boundary table is wrong by roughly
five percent of the qualification — enough to move a grade.

## 2. Method

1. Raw mark per component, out of that component's max.
2. Scale each component; sum to a mark out of 200.
3. Apply the series' Foundation or Higher subject boundaries.
4. Foundation caps at grade 5 whatever the mark. Higher runs 9–4 with an allowed 3 just below the
   4 boundary.

**Where components are missing** — which is the normal case in home study, since Speaking is NEA —
do not scale up from what was sat and present it as a grade. Report each component's percentage,
say which components are absent and what share of the qualification they carry, and give at most
an indicative position clearly labelled as incomplete.

## 3. Boundaries

This specification's **first exams were June 2026**, so exactly one real series exists. Legacy 8698
boundaries are from a different specification with a different structure and must not be used.

June 2026, out of 200 — **to be verified against AQA's own published statement before being stored
on the subject row.** These figures come from a secondary aggregation of the AQA data, not from
the PDF itself:

| Tier | 9 | 8 | 7 | 6 | 5 | 4 | 3 | 2 | 1 |
|---|---|---|---|---|---|---|---|---|---|
| Higher | 175 | 155 | 135 | 120 | 105 | 90 | — | — | — |
| Foundation | — | — | — | — | 144 | 130 | 94 | 59 | 24 |

**Verify here before use:**
`https://www.aqa.org.uk/exams-administration/results-days/grade-boundaries` → GCSE grade
boundaries June 2026. The same document carries notional component boundaries, labelled by AQA as
illustrative only — useful as a cross-check on a single-component mock, never as the conversion.

Once verified, store them on the subject row via `tracker_create_subject(boundaries: …,
boundary_max: 200)` so the service does the conversion and the dashboard shows a real grade. Until
verified, **leave `boundaries` unset**: the dashboard then shows the raw score rather than
inventing a grade, which is the correct behaviour.

## 4. Reading a single-component mock

One component is a quarter of the qualification and a noisy estimate of it. Report the component
percentage and, if you must indicate a level, do it against the notional component boundaries for
the matching component and tier, saying explicitly that AQA labels those illustrative. Never
multiply one component by four.

## 5. Sources

- Specification and assessment criteria:
  `https://www.aqa.org.uk/subjects/spanish/gcse/spanish-8692/specification/scheme-of-assessment`
- Specification at a glance:
  `https://www.aqa.org.uk/subjects/spanish/gcse/spanish-8692/specification/specification-at-a-glance`
- Past papers and mark schemes: `https://www.aqa.org.uk/pastpapers`
- Grade boundaries, all series: `https://www.aqa.org.uk/exams-administration/results-days/grade-boundaries`
