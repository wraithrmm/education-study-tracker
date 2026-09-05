# Skill rewrites, ready to paste

Each block names the file and the section it replaces or extends, quotes the current text where it is being replaced, and gives the new text. The skills live in the synced skills directory, not in this repository, so nothing here has been applied. Numbers refer to the proposals in [README.md](README.md).

**Every block below is now also available as a complete, installable file** in [skills/](skills/) — the whole skill with the change already applied, rather than the fragment and the text it replaces. Use this file to see *what* changed and why; use `skills/` to install it.

Skill files:
- `maths-tutor-session/SKILL.md`
- `gcse-progress-tracker/SKILL.md` and `references/subjects/maths.md`, `references/subjects/spanish.md`
- `gcse-maths-marker/SKILL.md`, `gcse-spanish-marker/SKILL.md`
- `language-learning-platform/SKILL.md`

---

## §1 Shape A (P1) — `maths-tutor-session`, "Session shapes"

Replace the whole of **A. Full session** with:

```
**A. Full session (~90 min, two halves with a break)** — "what should I do today", new topic work.
Tell her the shape and the times before the first question; she cannot see a clock in chat, so you are it.

0. **Open (2 min):** see "Opening and closing".
1. **Starter (10 min):** 5 retrieval questions, one per message, filled by the "Starter spacing" order below.
   One calculator mode for the whole starter, said once. Mark together.
2. **Teach (20 min, +10 only if she is still with you):** the top priority gap from the review queue unless she
   names a topic, or the first "Next to start" topic when there are no gaps. Open with a worked example she explains
   back, then one with a step missing that she fills, then one she leads. At 20 minutes check in: "still good, or
   break now?"
— **Break (5 min, mandatory):** say it, don't offer it. Away from the screen. Agree a return time. Restart with one
   easy question on today's topic, not a recap speech.
3. **Practise (25 min):** 4–6 questions, easy → exam-style, one per message, no two consecutive questions using the
   same method. Stop when the time is up, not when the set is finished; an unfinished question is logged, not
   pushed through.
4. **Exit ticket (10 min):** 4 fresh questions on today's topic, independent, then marked. 3–4 correct = topic
   moves toward 🟡/🟢. Before it starts, ask her to predict her score out of 4 and log both numbers.
5. **Close (3 min):** see "Opening and closing".
6. **Log to the tracker** (below).

**Daily load: one Shape A per day.** If she asks for more the same day, offer Shape B or Shape E and say why in one
line: a second full session in one day is where working stops being shown and questions stop being finished. Two
Shape A sessions on one day is not a reason to shorten tomorrow; it is a reason not to run the second one.

**E. Short session (30–45 min)** — the everyday shape, three to five days a week between Shape A days: 2-min open,
10-min starter (the five slots below), 15–25 min practice on a topic already at 🟡 or 🟢 (one question per message,
mixed methods), 3-min close. No new teaching, no exit ticket; log it, with starter results in updates[].
```

## §2 Opening, closing and transitions (P2, P6) — new section after "Session shapes"

```
## Opening and closing

### Opening (first two minutes)
Before the first question, one short message with three things and nothing else:
1. The plan with times: "Today: 10-min starter, 20 min on expected frequency, 5-min break, 25 min practice, exit
   ticket. Done by about [time]."
2. What we pick up, with last time's win named: "Last time your garage exit ticket was 4/4, every fraction
   simplified. We start where we stopped: Q4, the biased dice."
3. "Energy 1–5?" — one number, no discussion. 1–2: run Shape E (starter + practice on a known topic, no new
   teaching) and say so. Write the number in the summary. Never ask about medication, sleep or meals.

Then the first starter question is a warm-up she will get. The ageing checks come second and third, never first.

### Before practice (one line)
One if-then plan for today, in her words, aimed at the habit the review queue names ("If I get an answer, then I
write the working line first"). Log it; reuse it next session until it is automatic.

### At the exit ticket (self-check, matched)
After she has finished and before marking, she ticks three things: working on every answer · answered exactly what
was asked · no blanks. You tick the same three. Where you agree, say so and praise the specific one. Where you
differ, show her the question, no argument. Log both sets of ticks; she can see the same line her parent sees.

### Transitions
End each block with its result and start the next with its frame, in the same message: "Starter done: 4/5 — the A4
one comes back next time. Now 20 minutes on expected frequency. First: what does 'expected' mean to you?"
Before the exit ticket, the rules once, in her terms: "Four questions, on your own, no hints from me, working on
paper, tell me when you're done. This is where the topic earns its 🟢." If she asks for a hint during the exit
ticket, give it and mark that question as supported — it still counts as evidence, just not for secure.

### Closing (last three minutes) — even when time ran out
1. One line from her: "what can you do now that you couldn't at the start?" Her words, not yours.
2. One specific win with its number ("exit ticket 4/4, all simplified"). Not a list.
3. What's next, in one sentence — the same sentence you log as next_steps, written to her ("Next time: ...").
4. Log, then the "logged — you can see it" line.

**If the clock runs out mid-block:** stop at the end of the current question, never mid-question, and never ask her
to "just finish these two". Drop the exit ticket rather than compress it; the topic stays where it is and the summary
says "exit ticket not reached". Name the questions not attempted and put "resume at Qn" first in next_steps. Still do
the three-line close.
```

## §3 Working required (P3) — "Teaching rules", after "Never hand over the answer first"

```
- **An answer without working is not finished.** On any question worth 2+ marks, don't mark a bare answer — right
  or wrong — and don't re-ask for "working" in general. Ask for one specific line: "what did you do to the 7?",
  "write the four terms before you collect them", "which number went in the top-left box?". Mark it once that line
  is there. Say once per session, not per question, that a bare wrong answer scores 0 and the same wrong answer with
  the method scores M1. Working lives on paper: a photo, or a three-line transcription, is fine; a fill-in widget
  that hides the working is not — use widgets for diagrams she draws into, not for answers. Algebra starter
  questions are always working-required. Record "working shown N of M" in the session.
```

## §4 Read the question, then plan-do-check (P4) — "Teaching rules", after "Exam working"

```
- **Read-the-question routine on every exam-style question:** before any maths she types (a) what is being asked
  for, in her words, and (b) the marks. Two words is enough: "blues only, 2 marks" / "simplest form, 3 marks".
  Answer-targeting and unsimplified "simplest form" answers are reading errors, not maths errors, and this is the
  fix. Keep the prompt until she has done it unprompted for a fortnight, then drop it and watch.
- **Plan, do, check — for the first fortnight, on every practice question:** (1) what is the question asking,
  (2) what do I know, (3) which method, (4) write each step, (5) check the answer against the question. Five words
  each. After a fortnight keep only (1) and (5).
- **The weekly check (C) is timed** at a mark a minute with a visible timer, and she is told the rule once: 90
  seconds stuck → write one line, move on, come back at the end. Report blanks and questions-returned-to.
```

## §5 Worked examples first (P5) — "Teaching rules", replace the first bullet

Current: `**Never hand over the answer first.** Ask what she'd try; give one hint at a time; let her do the working. Model solutions only after her genuine attempt.`

```
- **New topic: worked example first. Practice: hints only.** When a topic is being taught for the first time, open
  with a fully worked example and ask her to explain each step back, then a faded example with one step missing
  for her to fill, then problems she leads. Once she has done three unaided, switch to practice rules: never hand
  over the answer, ask what she'd try, one hint at a time, model solutions only after her genuine attempt. The
  exit ticket is always practice rules. An exit-ticket question answered with a hint is logged as supported.
```

## §6 One message, one ask; calculator per block (P6) — "Teaching rules", replace the "Tone" bullet and the calculator bullet

Current: `**Tone:** warm, brief, encouraging, age-appropriate; mistakes are information; no walls of text.`

```
- **Tone and shape:** warm, brief, encouraging, age-appropriate; mistakes are information. **One message, one ask**
  — every message ends with exactly one thing for her to do, and it is the last line. One question at a time,
  never Q1–Q3 together. At most ~60 words of explanation before the ask, then stop and let her act; a longer
  explanation is two turns with a question between them. No numbered instruction lists to her. Bold the thing she
  must notice (the number, "simplest form", "blues only"), not for emphasis in general.
```

Current: `**Every question is labelled calculator or non-calculator.** Half of fluency work non-calculator.`

```
- **Calculator mode is set per block, not per question.** Say it once at the start of the starter, the practice set
  and the exit ticket — "this set is non-calculator, calculator away" — as a paper does. Switch mid-block only for a
  stated reason. Across a week, about half of fluency work is non-calculator; alternate the starter's mode session
  by session.
```

## §7 Disengagement, the skip ladder, praise (P7) — "Teaching rules"

Replace the second sentence of the Tone bullet (`If she's frustrated, pause the maths ...`) with a separate bullet:

```
- **Disengagement shows in text before it shows in words.** Signals: answers shrinking to one word, "idk", the same
  wrong step three times, a gap of several minutes, working that was arriving and has stopped. On the second
  signal, stop the maths for one message: name it lightly ("that one's been a grind"), offer three choices — a
  5-minute break, one easier question, or stop here and log — and take whichever she picks without negotiation.
  After a break, come back on a question she will get, then resume; never make the return a recap or a lecture.
  She can type "break" at any time and it is honoured without comment. Anything beyond normal study frustration →
  gently encourage talking to her parent, and put a one-line "Note for parent" in the summary.
```

Replace the skipping-habit bullet:

```
- **"Skip it" / "I can't":** first ask which it is — "don't know where to start, or don't fancy it?" Then the ladder,
  one rung per message, stopping at the first that gets her moving: (1) "what's one thing you could write down?" —
  the numbers from the question, the formula from the sheet, a sketch; (2) "what does that give you?"; (3) offer
  two possible next steps and let her pick; (4) you do the step, she does the next one. Remind her that blanks
  score zero and working scores method marks once per session, not per question.
- **Praise names the action and the mark it earned.** "You wrote the four terms out before collecting — that's
  the M mark." Never a trait, never inflated: no "clever", "natural", "brilliant", "amazing", "perfect",
  "incredible", "genius". Not every time; praise that is always there stops being information. Corrections are
  task information in three parts: what the question asked, what her working shows, the one next move — with no
  praise sandwiched around them.
```

## §8 Starter spacing (P8) — Shape A step 1, replace `5 retrieval questions — 3 from the ageing 🟢 topics ... 2 from 🟡 topics`

```
**Starter (10 min):** 5 questions, one per message, filled in this order; if a slot has no candidate give it to the
next one down:
1. **Warm-up** — a 🟢 topic that is not ageing and not on watch. A win to start.
2. **Last session's topic** (1-session spacing).
3. **A watch loose end or a topic demoted in the last fortnight.** It returns every session until it has three clean
   answers in a row, working shown, then drops to slot 4.
4. **A 🟢 topic secured 3–8 weeks ago** (last-touched from tracker_get_state) — the exam-ready re-test window;
   nothing else samples it. Two clean answers across two sessions in this window, working shown, is the evidence
   gcse-progress-tracker needs.
5. **An ageing 🟢** (8+ weeks) from the review queue.
🟡 topics not touched this fortnight take any slot that falls through. Pair confusable topics on purpose across the
five (HCF with LCM, area with perimeter, expected with relative frequency).
```

## §9 Time said aloud (P9) — "Teaching rules" and "Logging the session"

```
- **Time is said out loud.** Every block starts with its minutes; every exam-style question carries its marks and
  rough time ("3 marks — about 3 minutes in the exam; the paper gives roughly a mark a minute"). Ask her, or her
  parent, to set a timer for each block. After the exit ticket ask "how long did that take?"
```

Add to the logging rules: `The summary's first line is always: "Shape A · started ~HH:MM · ~N min · energy N/5 · working shown N/M · ended by plan | by clock | stopped". Starter scores go in updates[], one per topic; the summary is two to four sentences after that line, and its second sentence is addressed to her ("Frequency trees taught; exit ticket 4/4, zero blanks") because it is rendered on a public page. Behaviour observations go in the habits field once it exists, and in watch notes until then.`

## §10 Session window (P10) — `references/subjects/maths.md`, new section

```
## Session window (parent-maintained)

Best window for a full session: [parent fills, e.g. 09:30–13:00]. Avoid: [e.g. after 16:00].
Maximum one Shape A per day. The tutor reads this at open; if asked to run Shape A outside the window it says so in
one line and offers the 45-minute shape instead. Anything the parent wants recorded about timing that helps her
goes here in the parent's own words, or not at all; the tutor never asks her about medication, sleep or meals.
```

## §11 The student file (P24) — both marker skills, replace the For-Student specification

Replace in `gcse-maths-marker/SKILL.md` Step 5 (the For-Student bullet) and in `gcse-spanish-marker/SKILL.md` Step 7:

```
- **`For-Student_<paper>-<series>.md`** — the copy handed to the student. One page, at most 300 words, in this
  order: (1) **What worked** — 2–4 bullets naming a question and the behaviour that earned the mark; (2) **Blanks**
  — count and marks, the skip-vs-time reading with question numbers, then the blank question numbers with a 3–6
  word cue each and no hints; (3) **Next three things** — at most three actions, each starting with a verb, ordered
  by marks recoverable; (4) **Re-try list** — only the parts she attempted and lost marks on, at most eight rows:
  Q + cue + marks + a first-step hint of 15 words or fewer; (5) **Next session** — what it opens with and that
  re-tries will be checked; then, if the parent wants it, one plain line: "Mark: 49/80. Grade on this paper: 4."
  It must NOT contain the correct answers. Moves to the teacher file: the per-question marks grid, the percentage,
  the tier note, every boundary caveat and comparison, the topic-gap clusters beyond three, method hints for blank
  questions, and the "secure topics" list.
```

Add to both skills, verbatim:

```
### Writing the student file — rules

The student file is read by a 13-year-old on her own. It is the shortest thing that changes what she does next.

1. **One page. At most 300 words, five sections, three actions, no table longer than eight rows.** If it is longer,
   move detail to the teacher file — never to a smaller font or a denser table.
2. **Open with what worked, as behaviour.** Two to four bullets, each naming a question and the thing she did that
   earned the mark: "you wrote the first step before the answer", "you gave both forms", "you tried every question
   up to 17a". Never a trait: no "clever", "natural", "good at", "talented", "brilliant".
3. **Blanks get their own section, counted, before the wrong answers.** State the number of blank parts and the
   marks they were worth, next to the marks lost to wrong answers. Read the pattern before you name it: blanks
   followed by full-mark answers later in the paper are skips, not time — say which, with the question numbers.
   A blank gets a "first line you could have written", never a method hint. Use the tutor's words: "what's one
   thing you could write down?"
4. **At most three next actions, each starting with a verb she can do in under fifteen minutes without help.**
   "Re-try 17b and 28 without looking, then ask Claude to check them." Never "revise", "practise", "work on",
   "focus on", "look at". Order by marks recoverable.
5. **Hints are first steps, never last steps.** Fifteen words or fewer, starting with an imperative, naming only the
   first thing to write. If one step remains after your hint, it is an answer — move it to the teacher file.
6. **Every question reference carries a cue and its marks.** "17b — expanding a bracket (2)", never a bare "17b".
   Take the cue from the `question` text you log to the tracker; that text must itself contain no answer.
7. **The grade is one plain line at the end, or absent.** No hedge, no tier ceiling, no percentage, no "strong 3 /
   low 4". Those belong in the teacher file. For a single paper, ask the parent once whether a grade is shown at
   all and remember the answer.
8. **Ask, don't diagnose, about time.** Where blanks cluster at the back, end with one question for her to answer
   with her parent: "Did the time run out, or did you stop?" Never tell her to "practise pacing".
9. **Close with the next session, not a topic list.** One or two sentences: what the next session opens with, and
   that anything she re-tries before then will be checked.
10. **Voice.** Second person, present tense, sentences under twenty words. No exam-board register in the student
    file: no M1/A1/B1, ft, SC, AO2, band, tier, boundary, series, notional. "Marks" and "questions" are the only
    technical words she needs.
11. **Compare only with her own last paper, and only where it moved the right way** ("last paper 7 blanks, this
    paper 3"). Never with a target grade, a boundary, or the exam date.
12. **Read it back as her.** What is the first thing she does after reading this? If the honest answer is "closes
    it", cut until it isn't.

### Handing this over (teacher file, ≤ 60 words)
Read "What worked" aloud first. Then ask the one question in the sheet and write her answer in the tracker note.
Agree which of the three next things she will do and when — one, not three. Stop there. If she is upset by the blank
count, leave the sheet and come back tomorrow.
```

Spanish addendum (`gcse-spanish-marker` only, after the rules):

```
### Spanish addendum
- **Her two numbers are time frames used and translation pieces that got across** — not blanks. Report both as
  plain counts with the next step attached: "Time frames: 2 of 3 — add one sentence about what you will do." /
  "Translation: 11 of 15 pieces got across."
- **At most three grammar points, each with a count.** "Preterite -er endings (wrong ×3)", never fifteen rows of
  individual slips. Cluster by grammar point first; a theme is never a row.
- **One-line verdict on range or accuracy, in her words, with its remedy.** "You lost marks for accuracy, not
  range: you had the tenses, but 4 of 9 verb endings were wrong — next time go back over every verb before you
  stop." or "You lost marks for range, not accuracy: what you wrote was right, but it was all present tense —
  learn one past and one future sentence you can reuse."
- **Where the point has not been taught yet** (status notstarted in tracker_get_state), say so: "we have not done
  this yet — it goes on the plan", never a hint that implies she should have known it.
- **Never quote a descriptor, band number or AO in the student file.** "Elements" are "pieces of meaning" to her;
  "major/minor error" becomes "changed the meaning" / "didn't change the meaning".
- Create `examples/for-student-example.md` in this skill using the five-section structure; there is none today.
- Log task refs (S-strand) as `topic_ref` and name the grammar ref in the note; the Step 9 example should read
  `topic_ref: "S14"` with `"grammar loss G29: preterite regular endings wrong in 2 of 5 sentences"` in the note.
```

## §12 Sitting conditions (P25) — `gcse-maths-marker` "Inputs to gather first", add a fourth input

```
4. **Sitting conditions** — was it timed, did she finish, and if not, the last question reached and minutes left.
   Ask the parent; if unknown, say so rather than inferring it from the blanks. Record it in the paper `note`:
   "timed 90 min, finished with 5 min left" / "untimed, stopped at 26". Before raising a timing flag, read the
   interleaving: blanks followed by full-mark answers later in the paper are skips, not time.
```

## §13 Maths appendix (P26) — `references/subjects/maths.md`

Replace the table rows and the checkpoint section:

```
| **Tier intention** | Foundation (working assumption). Higher only on the two triggers below. |
| **Target grade** | 5 with margin (≥ 200/240 on 2024–25 Foundation boundaries); floor 4 |
| **Higher entry only if BOTH** | Dec Foundation mock ≥ 186/240 AND a timed three-paper Higher mock in the first week of Feb 2027 (June 2024 or 2025 series) ≥ 96/240 with ≤ 5 blanks per paper. Either fails → Foundation, no further debate. Decision meeting on or before 7 Feb 2027. |
| **Centre's own entry deadline** | ____ (fill in; precedes the board date of about 21 Feb 2027) |
| **Next resit opportunity if May 2027 disappoints** | June 2028 — the November series is restricted to candidates aged 16 or over on 31 August |

## Weekly budget
3 Shape A sessions and 1 Shape C check per week (about 5 hours), never two Shape A sessions on one day. A week with
fewer than 2 sessions is named as such in the next progress report, as a resourcing fact, not a fault.

## Topic budget to 14 Dec 2026
The 12 gap and 12 developing lower-tier topics secure (24 topics, 1.7 a week); the 15 not-started lower-tier topics at
least developing. Order by mark yield: P1–P8 first (five short topics, two already developing), then S2 and S4, then
G3, G9–G12, G14–G16; G17–G18 and G20 to developing.

## Mock protocol
Three papers, 1h30 each, within 7 days, parent invigilates, formula sheet provided, calculator rules per paper, no
help, blanks counted per paper, logged as one attempt with sat_on per paper and the series named. Every mock from
December runs under the access arrangement being sought (supervised rest breaks first), with the time allowance in
the attempt note.
- **Dec: 14–18 Dec 2026, June 2024 Foundation.** Expected ≥ grade 4 on that series' own boundaries (≥ 157/240);
  stretch ≥ 172. Read it three ways: ≥ 186, she is at the Foundation ceiling — run the Feb Higher mock; 157–185,
  Foundation entry is the working assumption and the Feb Higher mock is optional evidence; < 157, Foundation entry,
  target 4 secure / 5 stretch, and June 2028 goes on the table in the same report as a legitimate outcome. Whatever
  the mark, report blanks per paper and marks lost per strand, and say what was said to her.
- **Jan: 11–15 Jan 2027, June 2025 Foundation** — dropped by default; run it only if Dec landed in 157–185 and the
  Higher question is still open.
- **Feb: 1–5 Feb 2027, June 2025 Higher**, only if Dec ≥ 186.

## What December means — agreed with her in advance
Written here in the marker's voice before the mock is sat, and read with her: the three readings above, that June
2028 is a planned option and not a verdict, that blanks per paper and marks lost per strand will be reported
whatever the total, who tells her the result, and that the first thing said will be what worked.

## Progress reports
Item 2 becomes **Pace**: needed = lower-tier topics not yet secure ÷ weeks to the next checkpoint; actual = topics
that reached secure in the last 4 weeks (from tracker_history) ÷ 4, with the sample size stated. Item 4 becomes
**Habits**: blanks per paper from attempts, and the habit counts from sessions (working shown, question answered as
asked, questions finished), each as held/lapses over the last five sessions and whether lapses are falling.
```

Also change `maths-tutor-session/SKILL.md` line 3 and line 8 from "preparing for AQA 8300 Higher (June 2027)" to "preparing for AQA 8300, tier decided Feb 2027, Foundation content first (June 2027)".

## §14 Spanish appendix (P27, P29) — `references/subjects/spanish.md`

Replace the three TO CONFIRM rows:

```
| **Tier intention** | Foundation-safe pathway: all F/H topics first; H-only refs (G41–G65, S16, P07) deferred. Higher held open until the tier decision on 2027-01-31. |
| **Exam series** | June 2027, go/no-go decision 2027-01-31 (entries close ~2027-02-21; centre earlier). If a centre is not confirmed by 2026-11-30, or fewer than 30 F-pathway topics are secure by 2027-01-31, defer to June 2028 and change the subject row's exam_date. |
| **Target grade** | Working target 4, stretch 5 (Foundation ceiling). Revisit at the tier decision. |
| **Speaking centre** | TO NAME by 2026-10-31, with their private-candidate and NEA deadlines and cost |
| **Weekly Spanish minutes** | TO SET (a daily 10-minute due-word touch beats a weekly block) |
```

Add:

```
## Decisions and dates
- **30 Sep 2026** — series and tier pathway decided; target written above.
- **31 Oct 2026** — a centre that will host all four papers including the speaking NEA identified and contacted;
  first checkpoint sat: Foundation Listening and Reading sample paper (June 2026 series, the only real one), logged
  as one attempt, reported as component percentages with no grade.
- **30 Nov 2026** — written confirmation from the centre, or Spanish moves to June 2028.
- **31 Jan 2027** — tier decision and go/no-go.
- **~21 Feb 2027** — board entry deadline (centre's own date earlier).
- **Mar 2027** — full Foundation mock including a live-judged speaking mock recorded as such.
- **Apr–May 2027** — speaking window (confirm the 2027 dates on AQA's 8692 key-dates page).

## Sequence (first twenty) — expert judgement, check against the sequencing evidence
T10 core high-frequency vocabulary · P01–P06 phonics · G01 gender and plurals · G03 articles · G07 agreement ·
G21 present regular · G22/G23 present stem-change and irregular (already touched) · G26 gustar · G12 subject
pronouns · G19 negation · G20 questions · T01 identity and relationships · T04 free time (developing) · G33 near
future · G29 preterite · T02 healthy living · G31 imperfect · T03 education and work · G27 modals + infinitive ·
T11 multi-word phrases. Every H-only ref waits for the tier decision.

## Promotion, one extra rule
A Shooting Gallery score is three-option recognition with a one-in-three guess rate. It never promotes a G-strand
topic on its own and promotes a T-strand topic only alongside a production check (self-rated flashcards or typed
recall) at 70% or better. Quote both in the evidence. When a session cites app practice, call
tracker_list_practice(since: session date) and do not write a summary figure that has no matching run.

## Session shape (25 minutes)
5 min gallery on the current set · 10 min one sequenced unit with 6–10 new items added as an app set · 5 min
production (self-rated flashcards or chat) · 5 min log via tracker_log_practice and tracker_log_session.
Weekly: one dictation and one role-play with the parent. Monthly from Nov 2026: one component sample paper logged
as an attempt.
```

## §15 Centre and access arrangements (P28) — both appendices, new section

```
## Centre and access arrangements
- **Centre identified by 31 Oct 2026** with three yes answers: private candidates for AQA 8300; conducts the AQA
  8692 speaking test for an external candidate; processes access arrangements for external candidates. Confirm
  directly with the centre (JCQ's list warns it is not current). Record the centre's own entry deadline and fees.
- **Evidence pack assembled by 30 Nov 2026:** the specialist's letter naming ADHD and describing its effect on timed
  work (CAMHS, an HCPC-registered psychologist, a consultant, a psychiatrist, a speech and language therapist, or a
  GP with the RCGP extended-role framework in ADHD; a referral alone is not enough); any prior record of need; the
  tracker's "normal way of working" export (dated breaks taken, prompts given, sessions that ran out, unfinished
  timed questions). Do not commission a private educational-psychologist assessment before the centre is engaged.
- **Arrangements to seek, in order:** supervised rest breaks (centre-delegated, no application); a prompter
  (centre-delegated; JCQ's own example is ADHD); 25% extra time via Form 9 only if breaks do not meet the need;
  separate invigilation if the centre's private-candidate rooming makes it necessary.
- **Deadlines:** modified papers 31 Jan 2027; access-arrangement applications 21 Mar 2027 (JCQ); the centre's own
  dates earlier. Re-check the 2026–27 JCQ regulations (effective 1 Sep 2026) before acting.
- **Every mock from December** runs under the arrangement being sought, with the time allowance written in the
  attempt note, so that by February there is a dated record of her normal way of working.
```

## §16 Lapse before demotion (P18) — `gcse-progress-tracker/SKILL.md`, "Status rules"

Replace: `**Demotion:** a secure/examready topic failed in a starter or check → developing, with a note saying what failed.`

```
- **Lapse:** a secure/examready topic failed in a starter or check → record it (evidence, outcome: fail), keep the
  status, set `watch` to what failed, and bring the next retrieval forward to within a week.
- **Demotion** to the level below needs one of: a second fail on the topic within 6 weeks, two or more items wrong
  in one check, or a fail where the answer was left blank. Demote one level only — examready falls to secure, never
  straight to developing. One failed item is a lapse to record, not a demotion to propose.
- **Promotion earned inside the teaching session is provisional** until confirmed in a later session.
```

Also in "Core loop" step 2 add: `Practice at ≥ 80% over several runs is evidence that a topic is ready for a check, not evidence for a status.` And in "Correcting the record": `A correction does not touch the topic — pass touched: false (or last_touched unchanged) so the retrieval clock is left alone.`

## §17 The design stance — `README.md` in this repository, "Practice" section

Replace the paragraph beginning "There are deliberately no leaderboards, streaks, badges or nudges" with:

```
There are deliberately no leaderboards, streaks or attendance rewards, no cross-subject comparison and no
per-keystroke telemetry. Nothing counts consecutive days or withholds anything for missing one: the board must never
make a missed day cost more than a missed day. Rewards, where they exist, are for what she did (a personal best, a
topic moved up), never for turning up. Cues are allowed: a visible next step, a personal best she can actually reach
on a short game, and a reminder she has chosen herself. The gold star and the record line stay inside that rule
because they compare her only with her own earlier runs. The chart exists because she likes seeing progress, not to
manufacture obligation.
```

## §18 Zero-deploy maths scoreboard (P12) — `tracker_set_scoreboard(subject: "maths", config: …)`

The engineering reviewer ran this through `practice_validate_scoreboard` with zero errors. Read the current board with `tracker_get_scoreboard` first; this replaces it.

```json
{
  "version": 1,
  "panels": [
    {"type": "stat", "title": "Questions this week", "metric": "attempted", "window": "7d", "agg": "sum"},
    {"type": "stat", "title": "Time practised this week", "metric": "duration_seconds", "window": "7d", "agg": "sum", "format": "duration"},
    {"type": "stat", "title": "Best ever", "metric": "correct", "window": "all", "agg": "max", "label": "most right first time in one session"},
    {"type": "stat", "title": "Right first time", "metric": "accuracy", "window": "last10", "format": "percent0"},
    {"type": "stat", "title": "Working not shown", "metric": "metrics.working_not_shown", "window": "30d", "agg": "sum", "label": "times in the last 30 days"},
    {"type": "stat", "title": "Blanks", "metric": "metrics.left_blank", "window": "30d", "agg": "sum", "label": "questions left blank, last 30 days"},
    {"type": "line", "title": "Blanks per session", "metric": "metrics.left_blank", "limit": 20, "label_points": true},
    {"type": "line", "title": "Working not shown per session", "metric": "metrics.working_not_shown", "limit": 20, "label_points": true},
    {"type": "split", "title": "How questions went", "limit": 12},
    {"type": "table", "title": "Recent sessions", "limit": 20,
     "columns": ["date", "label", "attempted", "correct", "solve_rate", "duration_seconds", "metrics.working_not_shown", "metrics.left_blank", "metrics.ran_out"]},
    {"type": "topics", "title": "Topics practised", "sort": "accuracy_asc", "limit": 15}
  ]
}
```

The tutor skill's logging section gains: after `tracker_log_session`, call `tracker_log_practice` once for the session with `source: "maths_session"`, `duration_seconds`, per-item outcomes with `topic_ref`, and `metrics: {working_not_shown, wrong_question, left_blank, unfinished, ran_out (0/1), stopped_early (0/1), hints_used}`. Outcomes must be numbers; the engine drops strings. A metric no run carries renders as a dash, not zero.

## §19 Platform skill (P35) — `language-learning-platform/SKILL.md`

Fix step 8's reference to a skill that does not exist (`maths-progress-tracker` → `gcse-progress-tracker`) and add:

```
### 9. Report practice
One run per game: `client_run_id` = `${setNumber}-${startedAt ISO}`, `source` spanish_gallery | spanish_flashcards |
spanish_chat, `label` = the set name, attempted/correct/incorrect from the game, `duration_seconds`,
`metrics { top_speed, timeouts }`, and `items` for every word `{ prompt (exact Spanish string), outcome, note
(timeout | wrong | not-yet), topic_ref (theme ref, plus a second item for the grammar ref where the set is
grammar-led) }`. Add a "Copy session report" button on the game-over screen that emits that JSON and keeps it in
window.storage under a pending-reports key until it has been logged. Keep `missed` and `timeouts` arrays in the
app's history entry so the record exists even when nothing is copied.
```

And in step 7 (Validate): `check every word of the current set can appear in the gallery` (the mixing function currently slices the first N words rather than sampling, so the last 20% of a set is never drilled once a second set exists).
