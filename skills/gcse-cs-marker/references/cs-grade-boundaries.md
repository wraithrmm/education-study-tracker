# AQA Computer Science (8525) grade boundaries and conversion

Everything needed to turn a raw Computer Science mark into an indicative GCSE grade without web
research. Boundaries are copied from AQA's published grade-boundary statements. Verify against the
source URLs in §7 if a figure is disputed or a newer series is needed.

## 1. The 9–1 scale and comparable outcomes

The same in every subject; they live in
`gcse-progress-tracker/references/exam-grading-common.md`. Read that file alongside this one — it
also carries the rules on never inventing a boundary and what to do when none are verified.

## 2. Tiering — there isn't any

**AQA GCSE Computer Science is untiered.** Every candidate sits the same two papers and grades
1–9 are all available. There is no Foundation ceiling, no Higher safety net, no tier-entry
decision and no tier field to fill in. Never write "Foundation" or "Higher" in a Computer Science
output; if a template asks for a tier ceiling, replace that row with "Untiered — grades 1–9".

## 3. How the grade is calculated

- 100% exam, no coursework. **Two equally weighted papers, each 90 marks:**
  - **Paper 1** (`8525A/1` C# · `8525B/1` Python 3 · `8525C/1` VB.NET) — 2 hours
  - **Paper 2** (`8525/2`) — 1 hour 45 minutes
- **Qualification total = 180 marks.** The grade is set by boundaries applied to the 180 total,
  **not** to a single paper.
- **Boundaries are published separately for each language option** (8525A, 8525B, 8525C), because
  component marks are scaled per option. Use the row matching the student's declared language.
- Boundaries are set anew each series after marking, under Ofqual's **comparable outcomes**
  approach. There is no fixed percentage — a harder paper gets lower boundaries.

## 4. Published subject boundaries (out of 180)

**June 2024, option 8525C (VB.NET) — verified from AQA's published statement:**

| Series / option | 9 | 8 | 7 | 6 | 5 | 4 | 3 | 2 | 1 |
|---|---|---|---|---|---|---|---|---|---|
| **June 2024 · 8525C** | **152** | **140** | **128** | **108** | **88** | **68** | **50** | **32** | **14** |

That row is the only one reproduced here verbatim. **The 8525A and 8525B rows for the same series
differ by a few marks and are not reproduced because they were not independently verified** — pull
them from the June 2024 statement (§7) before quoting a figure for a C# or Python candidate, or
use the C row and say explicitly that you have done so.

**Directional context, for sense-checking only — not for quoting as a boundary:** across recent
series a grade 9 has needed roughly **82–86%** of 180 and a grade 4 roughly **38–41%**. Ofqual's
2024 protective adjustment lowered some grade-4 boundaries; grade 4 sat near 68 in 2024 and rose
again in 2025. Treat the 2025 figure as unverified until read from the published statement.

**There are no boundaries for the v1.3 specification.** Its first exams are June 2027 and the
first boundaries will be set at that award, in **August 2027**. Because the assessment structure
is unchanged (two 90-mark papers, 180 total), legacy boundaries are a reasonable planning proxy —
but every grade produced from them for a v1.3 specimen must be labelled **indicative**.

## 5. Method — turning a mark into a grade

**Whole mock (both papers sat):**
1. Total both papers → out of 180.
2. Apply the series' boundaries for the student's language option.

**Single paper:**
1. Percentage = raw ÷ paper max × 100.
2. Scale across two papers: `mark × 2` → out of 180.
3. Apply the boundaries, then state that a single paper samples only half the qualification and
   only half the content — a Paper 1 result says nothing about 3.3–3.8, and vice versa.

**Single paper with an adjusted maximum** (off-spec questions voided under Step 1 of the skill):
do **not** scale the raw mark, because the paper is no longer out of 90. Scale the percentage
instead — `percentage × 1.8` → out of 180 — and say in both output files that the conversion was
done this way and why.

**Worked example.** 51/83 on 8525/2 June 2023, after voiding three questions worth 7 marks that
test content removed in v1.3 (LAN topologies, Ethernet, Von Neumann):
- 51/83 = **61.4%**.
- Adjusted-maximum route: 61.4% × 1.8 = **111/180**.
- June 2024 8525C boundaries: grade 6 = 108, grade 7 = 128 → **indicative grade 6**, 3 marks
  clear of the grade-6 line and 17 short of a 7.
- Caveats to state: boundaries borrowed from a different series and a different language option;
  Paper 2 only, so 3.1–3.2 and all programming is entirely unsampled; percentage-scaled because
  the maximum was adjusted; three marks clear of a boundary is not a secure grade 6.

Voiding does not normally arise on **Paper 1** — the removals sit in 3.4 and 3.5, which Paper 1
does not assess. A Paper 1 is almost always marked out of the full 90 and scaled `mark × 2`.

## 6. Rules

- Never invent a boundary, and never interpolate one between series.
- Never quote a boundary from memory — use §4 or the published statement.
- Never attach a legacy boundary to a v1.3 specimen without the word "indicative".
- Never report a grade from a topic check. `kind: "check"` attempts are not grade-converted.
- Never write a tier.

## 7. Sources

- AQA grade-boundary hub (all series): `https://www.aqa.org.uk/exams-administration/results-days/grade-boundaries`
- June 2024 GCSE grade boundaries (PDF, contains the 8525C row above): `https://filestore.aqa.org.uk/over/stat_pdf/AQA-GCSE-GDE-BDY-JUN-2024.PDF`
- June 2025 subject grade boundaries (PDF): `https://www.aqa.org.uk/files/2753340a-f737-4784-996f-be4e15d824fd/f5a7282a19b66fc0a4737bd16b9669aa503411dc.pdf`
- 8525 specification at a glance (structure, weightings): `https://www.aqa.org.uk/subjects/computer-science/gcse/computer-science-8525/specification/specification-at-a-glance`
