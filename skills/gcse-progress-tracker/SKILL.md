---
name: gcse-progress-tracker
description: Maintain GCSE topic state and progress records in the live Education Tracker service, for any subject being tracked (maths, Spanish, science, English and so on). Use this skill whenever anyone pastes a LESSON REVIEW fallback block (or an older Update Block), reports session or test results, asks "how is she doing" / "how am I doing", asks for a progress report or grade projection, uploads a marked paper or mock for logging, or asks to update, correct or review the tracker. Also use at every mock checkpoint, for any tier-entry decision, for the Friday weekly review of the timetable ("how did the week go", "what did she miss"), and to excuse or set timetable blocks. Records evidence against subjects that already exist; use gcse-tracker-dashboard to create or extend a subject.
---

# GCSE Progress Tracker

Keeps the record truthful. The **Education Tracker** service at <https://education.rmmann.co.uk>
is the single source of truth for every tracked subject; this skill reads evidence in and writes
state back through its MCP connector (tools named `tracker_*`).

Full tool reference: `references/tracker-service.md`.
Grade-conversion facts shared by every subject: `references/exam-grading-common.md`.
Per-subject targets, checkpoints and quirks: `references/subjects/<slug>.md`.
The shared teaching rules every tutor skill applies: `references/study-principles.md`.
The weekly timetable and how blocks are judged done/missed: `references/timetable.md`.

> The old loop — regenerate a `03-TOPIC-STATE.md` and ask a human to swap the file into project
> knowledge — is retired. The service holds the state and writing to it is a tool call. If anyone
> wants the document form, `tracker_export_markdown(subject: …)` renders the current state on demand.

## Step 0 — establish which subject, before anything else

Nothing in this skill is safe to run against the wrong subject: evidence logged to the wrong slug
is a false record in two places at once. So resolve the subject first, every time.

1. `tracker_list_subjects()` — the authoritative list of slugs. Never guess a slug from the
   subject's name.
2. If exactly one subject exists, use it.
3. If several exist, take the slug from what the person actually said — a named subject, an
   obviously subject-specific topic, a paper code, the language of the work in front of you.
4. If it is still ambiguous, **ask in one line.** Do not infer from which subject was discussed
   most recently, and do not carry a slug over from earlier in the conversation once the topic
   has changed.
5. Once resolved, **name the subject in your closing summary** so a mis-resolution is visible to
   the reader rather than buried in a tool call.

Then read `references/subjects/<slug>.md` if one exists. It holds that subject's target grade,
checkpoint dates, tier policy, marker skill and anything else that isn't a field on the subject
row. Absent that file, take what you can from the subject row itself (`spec_code`, `tier`,
`exam_date`, `boundary_max`, `boundaries`, `notes`) and say plainly what you don't know.

## Core loop

1. **Read the current state.** `tracker_get_state(subject)` for the topic table,
   `tracker_history(subject, weeks: 12)` for what has been happening. Never work from memory or
   an older chat.
2. **Ingest evidence.** A pasted `lesson-review` fallback block (or an older Update Block),
   quoted scores, a marked paper, conversation history.
   Evidence must be concrete — what was done, the score, the date. **No evidence, no status change.**
3. **Apply the promotion/demotion rules** below. Adjudicate anything a tutor skill proposed
   rather than asserted.
4. **Write it back:**
   - Session-shaped evidence → `tracker_log_session` with its `updates[]`, so every change is
     stamped with the session that caused it.
   - A marked paper or check → `tracker_log_attempt`, then a `tracker_log_session` or
     `tracker_update_topic` for any status the result justifies.
   - A single correction outside a session → `tracker_update_topic`.
5. **Verify.** Read back what you wrote (`tracker_history(subject, weeks: 1)`) and confirm it
   landed. Never report a change you have not seen recorded.
6. **Close** with a 3-line summary: what changed, current trajectory, next checkpoint — naming
   the subject, plus the link <https://education.rmmann.co.uk/s/{slug}>.

If the connector is unavailable, say so and stop short of claiming anything was recorded. Keep
the evidence in the reply so it can be entered later.

**If you see `tracker_log_assessment` or `tracker_list_assessments`, the connector's tool list is stale.** Those were replaced by `tracker_log_attempt` / `tracker_list_attempts` / `tracker_get_attempt`. Ask the user to remove and re-add the Education Tracker connector in Settings → Connectors to pick up the current tool list (it now includes the practice, scoreboard and timetable tools). Don't fall back to the old assessment tools: they no longer exist on the server, so the call will fail.

## Status rules

Service statuses: `gap` 🔴 · `notstarted` 🟠 · `developing` 🟡 · `secure` 🟢 · `examready` 🔵.
These are identical in every subject and are never re-themed.

- `gap`/`notstarted` → **`developing`**: topic taught + practice at roughly the *developing bar*
  with support.
- `developing` → **`secure`**: an independent exit ticket or check at the *secure bar*, no hints.
- `secure` → **`examready`**: passes a spaced re-test ≥3 weeks after going secure (a retrieval
  starter or mixed check counts). Check the date with `tracker_history(ref: …)` — don't take
  "it's been a while" on trust.
- **Demotion:** a secure/examready topic failed in a starter or check → `developing`, with a note
  saying what failed.
- **Ageing:** untouched secure for 8+ weeks → the review queue flags it automatically. Report the
  flag; **do not demote without evidence**.
- **Never promote two levels in one session. Never promote on a single question.**
- Proposed changes (marked "?" or "proposed:" in a pasted block, a review's `proposed_status`,
  or evidence logged with no status): adjudicate against these rules and say which you accepted
  and which you did not.

**Lesson reviews propose; this skill adjudicates.** A review's `progress[]` entry carries
`status_seen` (what that lesson's evidence supports) and, where the evidence points further than
the bar allows, `proposed_status`. Both are proposals. `status_seen: secure` in a review is
**not** evidence that the secure bar was met — the bar is met only by the score or descriptor in
the `updates[]` evidence string, judged here. Accept a proposal with `tracker_update_topic` and
evidence saying which bar it met and in which session; decline it by saying so and leaving the
status where it is. The audit (`lesson-review` Mode B) flags a review that claims secure without
either a matching status change or a proposal, and its corrections arrive as `tracker_update_topic`
calls with evidence beginning "audit:" — treat those as corrections, not as new evidence.

**The weekly synthesis proposes too.** Its Part 2 `readiness` per subject and its Part 6
retention verdicts (`retaining` / `needs_spacing` / `false_secure`) are proposals in the same
sense. `false_secure` — a topic marked secure whose retrieval record since shows `retry` or
`incorrect` — is the verdict that most often warrants a demotion, and it is decided here with
the retrieval evidence in hand: `tracker_history(subject, ref)` for the outcomes and dates, then
`tracker_update_topic(status: "developing", evidence: "synthesis W37 false_secure; retrieval
<date> retry, <date> incorrect")` if the record bears it out, or a `watch` and no move if one
retry is all there is. Never demote on the verdict alone.

**Turning a pasted fallback block into the record.** When a `=== LESSON REVIEW … ===` block is
pasted (the connector was down when the session ran), it is one `tracker_log_session` call: the
header gives `subject`, `date`, `block_key` and `duration_minutes`; `UPDATES` lines are
`updates[]` with their `status`, `retrieval_outcome` and `watch` exactly as written, minus any
status you decline under the rules above; every other line is the field of the `review` object
of the same name (`lesson-review/references/analysis-rules.md` §3 lists them). Transcribe, do not
reinterpret; if a line will not validate, fix the field and say what you changed. Then verify
with `tracker_history(subject, weeks: 1)` and confirm the review landed
(`tracker_list_lesson_reviews(subject, limit: 1)`).

### Where the bar sits depends on how the subject is marked

The rules above are the same everywhere; only the threshold is subject-shaped. Use whichever of
these fits the evidence in front of you, and say which you applied.

**Points-marked work** (maths, science, MFL listening/reading, any right-or-wrong question set) —
the default:
- developing bar ≈ **70%** with support · secure bar ≈ **80%** unaided (e.g. 4/4, 5/6).

**Level- or band-marked work** (essays, extended writing, speaking, source analysis) — a
percentage of a band-marked mark is close to meaningless, so judge against the descriptor:
- developing bar: **reaches the band below target** in a supported attempt.
- secure bar: **meets the target-grade band descriptor unaided, twice, on different tasks.**
  Twice, because a single band judgement carries far more noise than a single mark scheme does.
- Quote the descriptor you matched in the evidence string, not just the mark.

**Performance work with no script** (speaking tests, practicals, orals): mark it however the
subject's criteria say, but record in the evidence that the judgement was made live and by whom.
An unrecorded live judgement is weaker evidence than a marked script and should not on its own
carry a topic to `examready`.

### Evidence discipline

The service refuses an evidence string under 10 characters, but the real bar is higher: evidence
must let a future reader reconstruct the judgement. "exit ticket 4/4 unaided, incl. 2(x+3)=16" is
evidence. "AO3 band 4 unaided — two time frames, verb errors minor" is evidence. "good progress"
is not — and it passes the length check, so this one is on you, not the schema.

### Correcting the record

- A session logged wrongly → `tracker_amend_session(subject, session_id, …)` to fix the date,
  summary or next steps, or pass `void_reason` to void it. Voiding keeps the row and the reason
  and marks it VOID; it stops counting toward the review queue and the export. Get the
  `session_id` from `tracker_history`.
- A topic status that was wrong → **never rewrite it silently.** Call `tracker_update_topic` with
  the correct status and evidence saying it is a correction and why. That appends to the trail
  instead of erasing it, which is the entire point of keeping one.
- **Evidence logged to the wrong subject** → void or correct it in *both* subjects: remove it
  where it doesn't belong and enter it where it does, each with evidence naming the mistake.

## Mock and paper marking

- **Marking itself belongs to the subject's marker skill**, not to this one. Check
  `references/subjects/<slug>.md` for which one — currently `gcse-maths-marker` and
  `gcse-spanish-marker`. If the subject has no marker skill, log the attempt from the marks the
  person supplies and say that the marking was theirs, not yours. **Never mark a subject using
  another subject's conventions** — AQA maths method marks have no meaning in an essay or a
  speaking test, and reaching for them because they are the marking rules you happen to have to
  hand produces confident nonsense.
- The marker skill logs the attempt with `tracker_log_attempt`, question by question, including
  each question's spec ref, the answer given, and why the marks went the way they did. If it
  hasn't been logged, log it — an unlogged paper is invisible to every later report.
- **One sitting is one attempt**, however many papers it holds. A whole mock is one call with all
  its papers and a single grade across the full mark total; a lone past paper is its own
  one-paper attempt. Never split the papers of one sitting into separate attempts: each paper
  would then be scaled against the whole-qualification boundary table on its own and report a
  grade for an exam only partly sat.
- Log a sitting once all its papers are marked, using `sat_on` per paper when it was spread over
  several days.
- `kind: "check"` for topic checks — the service never grade-converts those, by design.
- The service refuses a question breakdown that doesn't sum to the paper total. Treat that
  refusal as a free arithmetic check on the marking, not an obstacle.
- **Where components are scaled** (as in MFL, where a Foundation listening paper is worth more
  than its raw mark), record the raw marks per paper and do the scaling only in the grade
  conversion. Never log a scaled figure as if it were the raw score.
- After logging, `tracker_get_attempt(subject, attempt_id: …)` returns marks lost per topic,
  worst first. That list — not the raw score — drives the next fortnight's teaching.

## The weekly timetable review — mandatory on Fridays, and whenever asked about the week

The tracker stores the weekly timetable and judges each block from the evidence it already holds
(`references/timetable.md`). This skill owns the parent-facing side of it.

1. `tracker_week_status()` — every block of the week (or `week: "2026-W37"` for a past one).
2. Report **done / short / missed / excused / extra** counts, and **name every missed block** with
   day, time and subject. Plain words: "Tuesday 13:00 timed handwritten — missed". No softening,
   no streak language, no "nearly".
3. Ask — don't assume — whether any missed block should be excused. Excuse **only** with a reason
   the parent states, via `tracker_excuse_block(date, block_key, reason)`; the reason appears on the
   board. The model never excuses a block on its own initiative and never backdates a session to
   cover one. `reason: null` un-excuses.
3b. Pending **day-off requests**: `tracker_days_off(status: "requested")`. Put each to the parent
   in one line (dates, who asked, reason); record the answer with `tracker_decide_day_off`. The
   model never approves; a parent asking for a day off in this chat is booked at once with
   `tracker_request_day_off(requested_by: "parent")`.
4. Hours actually done per subject against the target split (Maths ~5 · Lit ~4.5 · Lang ~3.5 ·
   CS ~3.5 · Spanish ~1.5–2), from the durations of done blocks.
5. Handwriting minutes sustained and blanks from the week's timed work (from attempts).
6. One recommended carry-forward, taken from what was missed and from the review queue.
7. Tick the review block: `tracker_tick_block(date, block_key, by: "parent")`.

Changing the timetable is `tracker_set_timetable(blocks[], valid_from?)` — replaces the whole set,
versioned by date. Always `tracker_get_timetable` first and echo the diff back before writing.
Holidays and days off are **not** part of the timetable: they are day-off records, requested by
anyone and approved only by the parent (`references/timetable.md`, "Days off"). Only the parent
asks for a timetable change; a student request is relayed, not applied.

## Progress reports ("how is she doing?")

**Gloss every exam code, every time.** Q-numbers, paper numbers and AO codes mean nothing to
the parent on a phone. Write "Q5 (the 40-mark description piece)", "Q4 (the 20-mark 'do you
agree' question)", "AO6 (accuracy)" on each appearance in a report, review or checkpoint — the
subject appendix's plain-names table is the source. Codes stay bare only inside evidence
strings and tracker refs.

Read before writing: `tracker_get_state`, `tracker_history(weeks: 12)`, `tracker_list_attempts`.
Then, briefly and honestly:

1. **Counts by status per strand** (e.g. "Algebra: 3🟢 4🟡 2🔴 5🟠") and movement since the last
   report — `tracker_history` gives this week by week, so quote real dates rather than impressions.
2. **Trajectory** against the plan: ahead / on / behind, naming the blocking topics.
3. **Latest attempt** converted to a grade, and the projection toward the target grade recorded in
   `references/subjects/<slug>.md`. Grades come from the service's boundary conversion; quote the
   paper and series. If the subject has no verified boundaries stored, report the raw score and
   say no grade can be given yet — see `references/exam-grading-common.md`.
4. **Timetable adherence** for the period, from `tracker_week_status`: blocks done vs missed for
   this subject, and whether the misses cluster (a day, a time, a kind such as timed handwritten).
5. **The behavioural metric, where the subject has one.** For written papers: is she attempting
   every question in timed work? Blanks are recorded per paper on each attempt and should be
   falling toward zero. For subjects where blanks aren't the failure mode (speaking, orals), name
   the metric that is and say so explicitly rather than reporting a blanks count that means nothing.
6. **One recommended focus** for the coming week, taken from the per-topic marks-lost breakdown
   rather than a general impression.

Never fabricate or smooth data. Missing evidence = say so. Point the reader at
<https://education.rmmann.co.uk/s/{slug}> — every claim in a report should be clickable there.

## Checkpoint duties

Checkpoints, target grades and tier-decision policy are **per subject** and live in
`references/subjects/<slug>.md`. Read that file at Step 0 and honour whatever it says; the
duties there are mandatory, not advisory, and a tier flag is raised whichever way it points.

Two rules hold for every subject:

- Remind the parent of entry-logistics deadlines when a checkpoint report lands within 6 weeks of
  one.
- A tier or entry recommendation is stated plainly and early enough to act on, never softened
  because the news is unwelcome.

If a subject has no appendix file, say at the checkpoint that no checkpoint plan is recorded and
offer to write one rather than inventing dates.

## Adding a subject

To start tracking a new GCSE, use `tracker_create_subject` — the **gcse-tracker-dashboard** skill
owns that workflow, including seeding the syllabus, resources and grade boundaries. Re-running it
later adds new topics without resetting statuses already earned. When a new subject is created,
write its `references/subjects/<slug>.md` appendix at the same time so the first checkpoint isn't
the moment anyone notices it's missing.
