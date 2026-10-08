# Composing a week's paper

The paper is a technique exercise wearing the clothes of an exam, and every question on it is a
piece of evidence a topic is waiting for. These rules decide what goes in it.

## The shape

- **45 minutes, about 40 marks.** Roughly a mark a minute across the paper, with five minutes
  that belong to nobody: reading at the start, checking at the end. The section guides you send
  therefore sum to the duration **less five minutes** — about 40 for a 45-minute paper.
- **Three sections**, one subject each, in the order maths → English Language → English
  Literature → computer science unless the parent says otherwise. Four sections once a paper is
  60 minutes or longer. Which three is the rotation's decision, below.
- **No section under 8 marks**, and **marks follow need**: the subject with the most flagged
  refs gets the most marks.
- **A spread of tariffs in every paper**: at least one 1-mark question, at least one worth four
  or more, and one of 6 or more (5 or more in maths). That contrast is the whole point — she has
  to see that one wants the answer bare and the other wants the route.
- Lengthening the paper (45 → 60 → 90) is the parent's decision, recorded in the Builder
  project's instructions by `term-planner`. The build never changes it.

## Choosing the subjects

The exam subjects are maths, English Language, English Literature and computer science. Spanish
is never on the paper; `exam-skills` is never a section.

- **One exam subject sits out each week**: the one that sat out **longest ago**. A subject that
  has never sat out goes first. Ties are broken in this order: the subject with the most sittings
  of any kind in the last 21 days sits out (weekly papers, placement papers and resits all
  count); then the one with fewer flagged refs; then the later in the order maths → English
  Language → English Literature → computer science.
- **A subject that sat out last week is always in.**
- **One question of 6 marks or more per paper** (5 or more in maths), and never in the subject
  that carried it the week before. It rotates.
- The paper's **name carries its subjects** — `Exam practice — week 42 · maths, lit, cs` — so
  `tracker_exam_list_tests` alone shows who sat out when.

Read the history from `tracker_exam_list_tests` (names and dates) and, for the sittings count,
from the tests of every kind in the last 21 days.

**Worked example**, from the live record on 7 October 2026. Week 41 sat maths and English
Language; computer science and Literature sat out (the bank had nothing vetted for them). So in
week 42 computer science and Literature are in. Maths and Language have never sat out; Language
has four sittings in 21 days (three placement papers and week 41) against maths's one, so
**Language sits out** — week 42 is maths, Literature, computer science. Week 43: maths is the
only exam subject that has never sat out, so **maths sits out**. Weeks 44 and 45: computer
science and Literature sat out longest ago (week 41, a tie); the one with fewer flagged refs
sits out first, the other the week after. Then the cycle repeats.

## Choosing the questions

One code per question, stored on the question as a tag `why:<code>` with `wk:<ISO week>` beside
it. Priority runs top to bottom: take every `tech` question the target needs, then `bar`, and so
on until the paper is full.

| Code | Chosen because | Source of the flag | Its mark can… |
|---|---|---|---|
| `tech` | its shape makes the open `exam-…` technique target observable (an extended answer when the target is quotations per answer; a 3-marker when it is "method line before the answer") | `tracker_signals(subject: "exam-skills", status: "open")`; the last `exam-skills` session's next steps | answer the technique test |
| `bar` | a `developing` topic whose loose end or last review names unaided evidence still owed | `tracker_get_state` loose end; the `last_review` planner in `tracker_review_queue` | count toward secure |
| `retest` | a `secure` topic untouched for 21 days or more | `tracker_get_state` last touched; confirm the secure date with `tracker_history(subject, ref)` for those chosen | carry it to exam-ready, or show it has slipped |
| `due` | retrieval due or overdue, or the queue lists it as an ageing secure | `tracker_retrieval_due`; `tracker_review_queue` | confirm or demote |
| `loose` | a named loose end on a secure topic (units, a reason sentence, a working line) | `tracker_get_state` loose end | close the loose end |
| `stretch` | the one question per paper she is not expected to finish, placed mid-section | this file, "The hard question" | show she left it and moved on |
| `open` | a `gap` or `notstarted` topic — **only on the parent's word** | the parent | first evidence |
| `cover` | nothing above fits and the paper still needs its tariff spread | — | evidence only |

- `cover` questions are **at most a quarter of the paper's marks**.
- Default to `developing`, `secure` and `examready` topics. A `gap` or `notstarted` topic goes
  on a paper only as `open`, when the parent has said so.
- **Exclusions**: no ref twice in a paper (the first topic ref decides; a shared secondary ref
  is allowed and noted); no ref she was set in the last three weeks at the same tariff; never a
  question she has sat; a topic that lost marks for a **knowledge** reason on a paper is not set
  again until a taught session has logged evidence on it since.
- The flag behind each choice goes in the question's `note` when the `why:` tag is written, in
  one line, so the adjudicator can read it back without re-deriving it.

## The hard question

**Every paper carries one question she is not expected to finish**, placed in the middle of a
section, not at the end. It is how "leave it and come back" becomes a thing she has practised
rather than a thing that goes wrong under pressure. Its code is `stretch`. Do not warn her; the
instructions already say to flag and move on. The stretch question never demotes a topic.

One per paper. Two makes the paper unfair and teaches the wrong lesson.

## What each paper should make visible

Pick two or three of these per week and choose questions that expose them:

- **Tariff reading** — a 1-mark and a 4-mark question on the same kind of content.
- **Command words** — two questions that differ only in their command word
  ("State" versus "Explain why"; "Describe" versus "Compare"; "Estimate" versus "Work out").
- **Showing working** — a maths question whose answer alone scores 1 of 4.
- **Answer-line discipline** — units, rounding, "give your answer in its simplest form".
- **Level of response** — one English or computer-science extended answer, with its descriptors
  in the scheme, so the marking can show her what the next band needed.
- **Pace** — a section whose guide is genuinely tight for its marks.

## Across the term

- The **same ref at a different tariff**, three or more weeks apart, is how technique is tested
  without re-testing content.
- After a paper is marked, read its technique session and its adjudication before composing the
  next one: if she lost marks to blanks, put more short questions in; if she lost them to
  over-writing on 1-markers, put two 1-markers in; if a section ran over its guide, shorten that
  section next week rather than lengthening the paper. What the 7 October paper added:
  - **A typed non-attempt ("skip", "pass", "idk") is read as a blank** when deciding next
    week's shape, whatever the portal's blank count says.
  - **When marks were left unattempted with time unused, do not shorten the paper.** Keep one
    extended answer on it and say so in the instructions' technique line — the avoidance is the
    thing to practise, and removing the occasion removes the practice.
  - **When one low-tariff question ate the section** (more than twice its guide, scored under
    half), open the next section in that subject with two 1-mark questions, so the first thing
    she meets is the habit of answering and moving on.
- **Never repeat a question.** A sat question stays in the bank as the record of what she has
  seen.

## Instructions text

Short, and always in the same order: marks per section; any calculator rule; the one technique
target from the last paper, in plain words; the flag reminder; the blank reminder with its
"skip" clause. Nothing about a topic or a reason. For example:

> Maths 14 · English Literature 12 · Computer Science 12. No calculator in the maths section.
> This week: three quotations in any literature answer before you move on. Flag anything that is
> eating your time and come back to it. Never leave a question blank — typing 'skip' is a blank.
> A sensible attempt can earn marks, a blank cannot.
