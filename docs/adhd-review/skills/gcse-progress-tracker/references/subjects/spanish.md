# Subject appendix — `spanish`

Read at Step 0 whenever the resolved subject is `spanish`. **Fill the TO NAME and TO SET rows before
the first checkpoint** — they are recorded as unknown deliberately rather than guessed.

| | |
|---|---|
| **Slug** | `spanish` |
| **Spec** | AQA GCSE Spanish 8692 (first teaching 2024, first exams June 2026) |
| **Tier intention** | Foundation-safe pathway: all F/H topics first; H-only refs (G41–G65, S16, P07) deferred. Higher held open until the tier decision on 2027-01-31. |
| **Exam series** | June 2027, go/no-go decision 2027-01-31 (entries close ~2027-02-21; centre earlier). If a centre is not confirmed by 2026-11-30, or fewer than 30 F-pathway topics are secure by 2027-01-31, defer to June 2028 and change the subject row's `exam_date`. |
| **Target grade** | Working target 4, stretch 5 (Foundation ceiling). Revisit at the tier decision. |
| **Speaking centre** | TO NAME by 2026-10-31, with their private-candidate and NEA deadlines and cost |
| **Weekly Spanish minutes** | TO SET (a daily 10-minute due-word touch beats a weekly block) |
| **Qualification total** | **200 scaled marks**, both tiers |
| **Components** | Listening 25% · Speaking 25% · Reading 25% · Writing 25% |
| **Marker skill** | `gcse-spanish-marker` |
| **Dashboard** | <https://education.rmmann.co.uk/s/spanish> (parent) · <https://education.rmmann.co.uk/s/spanish/today> (hers) |

## Decisions and dates

- **30 Sep 2026** — series and tier pathway decided; target written above.
- **31 Oct 2026** — a centre that will host all four papers including the speaking NEA identified and
  contacted; first checkpoint sat: Foundation Listening and Reading sample paper (June 2026 series,
  the only real one), logged as one attempt, reported as component percentages with no grade.
- **30 Nov 2026** — written confirmation from the centre, or Spanish moves to June 2028.
- **31 Jan 2027** — tier decision and go/no-go.
- **~21 Feb 2027** — board entry deadline (centre's own date earlier).
- **Mar 2027** — full Foundation mock including a live-judged speaking mock recorded as such.
- **Apr–May 2027** — speaking window (confirm the 2027 dates on AQA's 8692 key-dates page).

## Sequence (first twenty) — expert judgement, check against the sequencing evidence

T10 core high-frequency vocabulary · P01–P06 phonics · G01 gender and plurals · G03 articles ·
G07 agreement · G21 present regular · G22/G23 present stem-change and irregular (already touched) ·
G26 *gustar* · G12 subject pronouns · G19 negation · G20 questions · T01 identity and relationships ·
T04 free time (developing) · G33 near future · G29 preterite · T02 healthy living · G31 imperfect ·
T03 education and work · G27 modals + infinitive · T11 multi-word phrases.

Every H-only ref waits for the tier decision.

## Component scaling — the thing that catches people out

Boundaries are set on the **total scaled mark of 200**, not on raw marks.

| Component | Foundation raw | Factor | Higher raw | Factor | Scaled |
|---|---|---|---|---|---|
| Paper 1 Listening | 40 | ×1.25 | 50 | ×1 | 50 |
| Paper 2 Speaking | 50 | ×1 | 50 | ×1 | 50 |
| Paper 3 Reading | 50 | ×1 | 50 | ×1 | 50 |
| Paper 4 Writing | 50 | ×1 | 50 | ×1 | 50 |

Log **raw** marks per paper in the tracker. Scale only at grade conversion. A Foundation
listening paper logged as 50 raw is simply wrong.

## Promotion thresholds — mixed, by component

This subject uses both bars in the main skill, so say which one you applied:

- **Listening Section A, Reading Section A, Writing Q3 (grammar), role-play tasks** — points-marked.
  Default bars: 70% supported → developing, 80% unaided → secure.
- **Dictation, translation, extended writing, all of Speaking** — band-marked. Judge against the
  descriptor: reaches the band below target supported → developing; **meets the target band
  unaided on two different tasks** → secure. Quote the descriptor matched, not just the mark.

## Promotion, one extra rule

A Shooting Gallery score is three-option recognition with a one-in-three guess rate. It never
promotes a G-strand topic on its own, and promotes a T-strand topic only alongside a production
check (self-rated flashcards or typed recall) at 70% or better. Quote both in the evidence. When a
session cites app practice, call `tracker_list_practice(since: session date)` and do not write a
summary figure that has no matching run.

## Session shape (25 minutes)

5 min gallery on the current set · 10 min one sequenced unit with 6–10 new items added as an app set ·
5 min production (self-rated flashcards or chat) · 5 min log via `tracker_log_practice` and
`tracker_log_session`. Weekly: one dictation and one role-play with the parent. Monthly from
Nov 2026: one component sample paper logged as an attempt.

## Topic refs are grammar as well as theme

The per-topic marks-lost analysis is more useful here than in maths, because a Spanish paper
loses marks to *grammar points* — preterite of regular -ar verbs, adjective agreement, ser vs
estar, por vs para — as much as to themes. Seed both, and tag questions with the grammar ref
where the loss was grammatical, even when the question sat inside a thematic text.

## Speaking is NEA, and this is a real obstacle

Paper 2 is non-exam assessment: conducted and audio-recorded by a teacher in a five-week window
running April–May, then marked by an AQA examiner. That is 25% of the qualification which a
private or home-educated candidate **cannot sit without a centre that will host it**.

- Confirm early which centre will conduct the speaking test, and note their deadline here.
- Until then, do not report a projected overall grade as though all four components were
  available. Report the three examinable components and say the speaking mark is unmodelled.
- Mock speaking marks are live judgements with no script. They are legitimate evidence, but
  record who made the judgement and never let one carry a topic to `examready` on its own.

## Behavioural metric

**Blanks are the wrong metric here.** Track instead:

- **Attempted-but-empty translation elements** in the translation grids (ticks out of 15).
- **Time-frame range** in extended writing — the AO3 grids reward references to two or three time
  frames, and a student who writes only in the present is capped well below target however
  accurate the Spanish.

## Centre and access arrangements

- **Centre identified by 31 Oct 2026** with three yes answers: private candidates for AQA 8300;
  conducts the AQA 8692 speaking test for an external candidate; processes access arrangements for
  external candidates. Confirm directly with the centre (JCQ's list warns it is not current). Record
  the centre's own entry deadline and fees.
- **Evidence pack assembled by 30 Nov 2026:** the specialist's letter naming ADHD and describing its
  effect on timed work (CAMHS, an HCPC-registered psychologist, a consultant, a psychiatrist, a
  speech and language therapist, or a GP with the RCGP extended-role framework in ADHD; a referral
  alone is not enough); any prior record of need; the tracker's "normal way of working" export
  (dated breaks taken, prompts given, sessions that ran out, unfinished timed questions). Do not
  commission a private educational-psychologist assessment before the centre is engaged.
- **Arrangements to seek, in order:** supervised rest breaks (centre-delegated, no application); a
  prompter (centre-delegated; JCQ's own example is ADHD); 25% extra time via Form 9 only if breaks
  do not meet the need; separate invigilation if the centre's private-candidate rooming makes it
  necessary.
- **Deadlines:** modified papers 31 Jan 2027; access-arrangement applications 21 Mar 2027 (JCQ); the
  centre's own dates earlier. Re-check the 2026–27 JCQ regulations (effective 1 Sep 2026) before
  acting.
- **Every mock from December** runs under the arrangement being sought, with the time allowance
  written in the attempt note, so that by February there is a dated record of her normal way of
  working.

## Checkpoint duties — mandatory

The dates are in "Decisions and dates" above; these are the duties attached to them.

- **31 Oct 2026** — first checkpoint: Foundation Listening and Reading sample paper, logged as one
  attempt, reported as component percentages **with no grade** (two components of four cannot carry
  one). Say plainly whether a centre has been found.
- **30 Nov 2026** — report whether the centre is confirmed in writing. If it is not, recommend the
  move to June 2028 in that report, in plain words, and change the subject row's `exam_date`.
- **31 Jan 2027** — tier decision and go/no-go, against the F-pathway secure count. Raised whichever
  way it points.
- **Mar 2027** — full Foundation mock including a live-judged speaking mock, recorded as a live
  judgement with the judge named.
- Remind the parent of entry-logistics deadlines when a checkpoint report lands within 6 weeks of
  one — including the centre's own deadline and the 21 Mar 2027 access-arrangement date.
