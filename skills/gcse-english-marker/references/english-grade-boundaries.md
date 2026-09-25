# AQA English grade boundaries — verified series

Read with `gcse-progress-tracker/references/exam-grading-common.md` (untiered scale, comparable
outcomes, no invented boundaries). Both qualifications are set on **160 raw marks**, untiered.
Boundaries are the minimum subject total for the grade.

## June 2025 (confirmed subject boundaries)

Source: AQA "GCSE subject grade boundaries – June 2025 exams", published 21 August 2025, via
<https://www.aqa.org.uk/exams-administration/results-days/grade-boundaries> (archive page for
earlier series: <https://www.aqa.org.uk/exams-administration/results-days/grade-boundaries/archive>).

| Grade | 9 | 8 | 7 | 6 | 5 | 4 | 3 | 2 | 1 |
|---|---|---|---|---|---|---|---|---|---|
| **8700 English Language** (/160) | 119 | 109 | 100 | 91 | 82 | 73 | 54 | 35 | 16 |
| **8702 English Literature** (/160) | 136 | 122 | 108 | 92 | 77 | 62 | 46 | 30 | 15 |

As percentages, grade 4: Language 45.6%, Literature 38.8%; grade 7: 62.5% / 67.5%; grade 9:
74.4% / 85%. Literature's floor is lower and its ceiling higher; that reflects the shape of the
mark schemes, not relative difficulty.

## Storing on the tracker subject row

`boundary_max: 160`, `boundaries: { U: [[9,119],[8,109],[7,100],[6,91],[5,82],[4,73],[3,54],[2,35],[1,16]] }`
for Language and the 8702 row for Literature. Confirm the exact key format the service expects
for an untiered subject with `tracker_list_subjects` before writing.

## Adding earlier series

June 2024 and June 2023 boundaries should be added from the archive URL above before the first
full mock, so a paper from those series can be graded on its own boundaries and cross-checked
against 2025. Do not add a figure that has not been read from the AQA document itself.

## Conversion method

1. Raw mark per paper, logged raw.
2. Scale to 160: 8700 lone paper ×2; 8702 lone Paper 1 ×2.5 (64→160), lone Paper 2 ×1.667
   (96→160). A full mock needs no scaling.
3. Apply the nearest series' row; name the series.
4. State: single paper = noisy; band-marked = noisier; a single essay/Q5 is never graded.
