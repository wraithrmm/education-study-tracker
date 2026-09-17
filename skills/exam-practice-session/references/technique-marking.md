# Reading a sitting for technique

After the marks are recorded, the paper is read a second time — not for what she knew, but for
what she did. This is the material of the `exam-skills` session.

## Was a mark lost to technique or to knowledge?

Ask: **could she have earned it with what she already knew, if she had read or written
differently?** If yes, it is technique and belongs here. If she did not know the content, it is
knowledge and belongs in that subject's session.

| What happened | Technique error | `exam-skills` ref | Review `error_type` |
|---|---|---|---|
| Right answer, no working, method marks lost | working not shown | R3 | `unchecked` |
| Right value in the working, wrong value on the answer line | answer-line slip | R4 | `transcription` |
| Missing units, or not rounded as asked | answer-line slip | R4 | `instruction_misread` |
| A paragraph written for a 1-mark question | tariff misread | R2, A1 | `instruction_misread` |
| Two words written for a 4-mark question | tariff misread | R2 | `instruction_misread` |
| Answered a different command word ("described" when asked to "explain") | command word missed | R1 | `instruction_misread` |
| Did not use part (a)'s answer in part (b) | multi-part | R5 | `missed_information` |
| Extended answer with points but no development | band not reached | A3 | `procedure` |
| SPaG costing AO4 marks on a question that carries them | SPaG | A4 | `procedure` |
| Blank with time left | avoidance | A5 | `undetermined` |
| Blank with no time left | pacing | T2, T3 | `rushed` |
| One question took more than twice its guide and scored under half | time hog | T3 | `persistence` |
| Section overran its guide and the next section suffered | pacing | T2 | `pace` |
| Flagged and never came back | flag discipline | T3 | `sequencing` |
| Finished with more than a fifth of the time unused and blanks on the paper | no checking | T4, A5 | `unchecked` |

Where a row does not fit, say so in `missing_evidence` rather than forcing one.

## The numbers to state

Every evidence string carries them. From `tracker_exam_get_test`:

- **sat minutes against the duration**, and who closed it — the timer, or her, and how early;
- **per section**: marks scored of marks available, minutes spent (sum the per-question times)
  against the section guide, blanks;
- **per question**: minutes against its own time guide, flagged or not, blank or not;
- **the time hog**: the question with the worst (time ÷ guide) among those scoring under half.
  Name it, its marks, its time. There is usually exactly one and it is the week's example.
- **blanks with time left**: any blank on a paper closed by her early, or where the section
  finished inside its guide. That combination is the one worth naming out loud.

Per-question time is what the page measured while the question had focus. It survives a reload
but not a closed laptop, and it does not count thinking done while staring at another question.
Treat it as a guide and say so when a conclusion rests on it.

## Statuses on the exam-skills topics

The same bars as everywhere: `gcse-progress-tracker` adjudicates, the review proposes. As a
guide for what to propose:

- **`developing`** — the behaviour appeared in one paper, prompted by the instructions.
- **`secure`** — it held across two consecutive papers with no prompt.
- **`examready`** — it held on a longer paper (60 minutes or more) three or more weeks after it
  became secure.

A single paper moves nothing on its own except a demotion with evidence: a technique that had
been secure and visibly failed under time pressure is a `gap` with the numbers attached.

## Signals

Key them `exam-…` so they group: `exam-pacing-last-section`, `exam-blank-under-pressure`,
`exam-working-on-tariff`. Give each a `next_test` naming what the following week's paper would
show — "next paper: does she write a method line on every question worth 3 or more without being
told?" One paper is never a pattern; the count rule does the rest.
