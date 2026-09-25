# GCSE grading — the facts that are the same in every subject

Cited by `gcse-progress-tracker`, `gcse-maths-marker` and `gcse-spanish-marker`. Anything here
holds regardless of subject or board. Anything subject-specific — mark totals, paper structure,
boundary tables, marking conventions — lives in that subject's own reference, never here.

## 1. The 9–1 scale

- GCSEs in England are graded **9 (highest) to 1 (lowest)**, with **U** (ungraded) below 1. The
  numeric scale replaced A*–G, phased in from 2017 (Maths and English first, all subjects by
  summer 2020).
- Ofqual anchor points between old and new: bottom of grade **7 = bottom of old A**; bottom of
  grade **4 = bottom of old C** ("standard pass"); bottom of grade **1 = bottom of old G**.
  Grade **5** is a "strong pass" (≈ high C / low B).
- Rough map (not exact except at the anchors): 9 ≈ high A*, 8 ≈ A*/A, 7 ≈ A, 6 ≈ high B,
  5 ≈ low B/high C, 4 ≈ C, 3 ≈ D, 2 ≈ E/F, 1 ≈ F/G.

## 2. Tiering, where a subject is tiered

Maths, the sciences and MFL are tiered; English, History, Geography and most others are not.

- **Foundation tier: grades 1–5 only.** Grade 5 is the ceiling — a Foundation candidate cannot
  score above a 5, whatever the mark.
- **Higher tier: grades 4–9**, with an allowed **grade 3** as a narrow safety net just below the
  grade-4 boundary (below that = U).
- A candidate sits **every component of a tiered subject at the same tier** in the same series.
- Tier entry is a real decision with a real deadline. Where a tracked subject is tiered, the
  decision point belongs in its `subjects/<slug>.md` appendix with a date.

## 3. How boundaries work

- Boundaries are set **anew each series** after marking, combining statistical prediction with
  senior-examiner judgement under Ofqual's **"comparable outcomes"** approach. There is **no
  fixed percentage and no formula** — a harder paper gets lower boundaries.
- Boundaries apply to the **qualification total**, not to any single paper. A single paper is a
  noisy estimate of a multi-paper qualification and must be reported as one.
- **Notional single-paper boundaries**, where a board publishes them, are labelled "for
  illustrative purposes only" and do not always sum to the subject boundaries. Use them as a
  cross-check, never as the primary conversion.
- Some subjects **scale component marks** before totalling (MFL does; maths does not). Where they
  do, boundaries are set on the total *scaled* mark, so raw marks must be scaled before conversion.

## 4. Truthfulness rules — these are not negotiable

- **Never invent or estimate a boundary.** If the exact series isn't to hand, use the nearest
  published series, say which you used, and give the source URL so it can be checked.
- **No verified boundaries → no grade.** Report the raw mark and the percentage, say a grade
  cannot be given yet, and leave the subject's `boundaries` unset in the tracker. The dashboard
  then shows the raw score rather than inventing a grade. A missing grade is a small
  inconvenience; a fabricated one gets acted on.
- **Checks are never grade-converted** (`kind: "check"`), so a good result on a handful of topics
  cannot quietly become a projected grade.
- **Percentages of band-marked work are close to meaningless.** 60% of a 25-mark essay grid is not
  "a grade 6" and must not be reported as though it were.

## 5. 2026 standards, and older papers

- 2026 grading maintains the **pre-pandemic standard restored in summer 2023**; Ofqual's *Guide
  for schools and colleges 2026* states the standard to achieve a grade in 2026 is **comparable
  to summer 2025**. No grade quotas; boundaries move only for paper difficulty.
- **Consequence for older lenient papers:** 2020–2022 (pandemic-era) series were graded more
  generously. A total graded 4 on 2022 boundaries can be a **high grade 3 / borderline 4** on
  2024–2026 standards. When marking a pre-2023 paper, report both: *"grade X on <series>
  boundaries; grade Y on restored 2026 standards."*
- Do not staple one series' boundaries onto another series' paper as if exact — use it only as a
  directional sense-check, because each paper's difficulty is baked into its own boundaries.
- Boundaries publish on results day each August and cannot be predicted beforehand.

## 6. Sources

- AQA grade-boundary hub, all series and subjects:
  `https://www.aqa.org.uk/exams-administration/results-days/grade-boundaries`
- Ofqual guide for schools and colleges 2026:
  `https://www.gov.uk/government/publications/ofqual-guide-for-schools-and-colleges-2026/ofqual-guide-for-schools-and-colleges-2026`

Verify a figure against the board's own published statement before storing it on a subject row.
Secondary sites that aggregate boundaries are useful for finding a number and not sufficient for
trusting it.
