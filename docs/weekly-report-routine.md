# Replacement prompt for the Friday routine — paste this whole into the scheduled task

It is Friday afternoon. Write this week's weekly review for Paige's GCSE work in the Education
Tracker, save it as a draft, and email the digest to wraith.shadow@gmail.com before the 14:15
review block with Dad.

Follow the parent-weekly-review skill for the judgement and the wording. Work in Europe/London
and use the current ISO week (Mon–Fri).

1. Read the week with one call: `tracker_week_report()`. That returns every block with its
   status, the extras, the counts, the hours against what the timetable planned, each subject's
   topic movement with its evidence, anything sat with blanks, the practice runs, the top of each
   review queue, the pending days off and the Tuesday rotation. Do not make the twelve separate
   calls; if something is missing from the report, then read that one thing. Count study blocks —
   the ones judged from logged work — as the headline, and report the movement blocks and the
   Friday review block separately: a walk and a maths block do not belong in the same fraction.

2. Write the margin note — judgement only, no figures restated. Three short paragraphs, plus one
   carry-forward line per subject:
   - held: what actually held this week, named with the evidence behind it.
   - slipped: what did not, without softening. Name a pattern once if the same block has gone
     missing several weeks running, and offer term-planner rather than repeating the point.
   - next: what next week starts with, including which Tuesday timed piece is next in the rotation
     (Lang Q5 → Lit essay → Maths section → CS program) and which subject Thursday's deep block
     falls to (maths in odd ISO weeks, computer science in even).
   - carry_forward: one line each for maths, english-literature, english-language,
     computer-science and spanish. Drawn from the misses first, then the review queue.

3. Save it: `tracker_save_weekly_review(week: <this ISO week>, stage: "draft", written_by:
   "routine", sections: { held, slipped, next, carry_forward, rotation_next, decisions: [] })`.
   The tracker attaches its own snapshot of the counts, hours, blocks, movement, attempts and
   practice as they stand now, so do not put counts in your sentences — they are computed beside
   your words and will still be right when your wording has aged. Leave decisions empty: nothing
   has been decided yet.
   Hours are read against what the timetable actually planned for that week, not against the
   5 / 4.5 / 3.5 / 3.5 / 1.75 split in the skills — the two do not currently agree, and the
   timetable is the half the record can verify. If a subject is under its planned hours, say which
   blocks were lost, not that she is behind the split.

4. Email the digest to wraith.shadow@gmail.com. Plain text, no attachments, no charts. Subject
   line: `Week <NN> — <done> of <study blocks> study blocks · <missed> missed<, and · N decisions
   waiting when there are any>`. Body, in this order and nothing more: the headline line, with
   `Movement <n> of <n> · review block pending` under it; MISSED, one line per missed or short
   block with its day, time, label and what was absent; DAYS OFF pending the parent's decision,
   one line each; HOURS vs timetable on one line, each subject's done hours over the hours its
   blocks planned this week; TIMED / HANDWRITTEN, this week's or the last one if there was none;
   CARRY-FORWARD, the five lines; `Next Tuesday: <rotation>`; then the single link
   `Open the week → https://education.rmmann.co.uk/week/<ISO week>`.

Do not: excuse a block, decide a day off, tick the Friday review block, change a topic status, or
log a session. Those happen in the 14:15 review chat with Dad, on his word, and that conversation
saves the reviewed version. Do not write anything about Paige beyond the work — the page is
public. Do not offer reassurance the numbers do not support, and do not count streaks.

If the Education Tracker connector is unavailable, say so plainly in your final message and stop —
do not reconstruct the week from memory. If the mail connector is unavailable, still save the
draft and say the digest could not be sent.
