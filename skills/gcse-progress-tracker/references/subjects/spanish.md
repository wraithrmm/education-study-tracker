# Subject appendix — `spanish`

Read at Step 0 whenever the resolved subject is `spanish`. **Fill the TO CONFIRM rows before the
first checkpoint** — they are recorded as unknown deliberately rather than guessed.

| | |
|---|---|
| **Slug** | `spanish` |
| **Spec** | AQA GCSE Spanish 8692 (first teaching 2024, first exams June 2026) |
| **Tier intention** | TO CONFIRM |
| **Exam series** | TO CONFIRM |
| **Target grade** | TO CONFIRM |
| **Qualification total** | **200 scaled marks**, both tiers |
| **Components** | Listening 25% · Speaking 25% · Reading 25% · Writing 25% |
| **Marker skill** | `gcse-spanish-marker` |
| **Dashboard** | <https://education.rmmann.co.uk/s/spanish> |
| **Vocabulary app** | <https://education.rmmann.co.uk/s/spanish/play> — the primary teaching tool; sets via `tracker_list_vocab_sets` / `tracker_save_vocab_sets`; games log themselves |

## The vocabulary app's evidence

Every game she finishes is a practice run (`spanish_gallery`, `spanish_flashcards`) with one
item per word under the set's topic refs, so the per-topic practice table and the retrieval
schedule see it without anyone logging it. The app scores each set in stars — played; a game
at 80%+; a second 80%+ game at least three days later — and `tracker_list_vocab_sets` reports,
per topic, the one move the games support. Two rules for adjudicating:

- **A game is recognition.** It can carry `notstarted`/`gap → developing` with evidence that
  says so ("recognises the words; production not yet shown"), and three stars make a
  `developing` topic *ready* for `secure`. The parent makes either move from the app page
  (signed in, one click, which logs a session with the evidence), or the slot makes it from
  production in the chat. Never `secure` from games alone when the record shows no production;
  never `examready` from a game.
- **Never log a game by hand**, and never void one to correct a score: a wrong set or a
  stray one-word game is voided with `tracker_void_practice` and a reason, like any run.

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

## Checkpoint duties

None recorded yet. Write them once the tier intention and exam series are confirmed, following
the pattern in `maths.md`: a full-mock checkpoint with an expected grade, and — since this subject
is tiered — a dated tier decision ahead of the entry deadline.
