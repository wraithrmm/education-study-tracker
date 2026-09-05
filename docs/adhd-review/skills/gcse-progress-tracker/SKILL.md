---
name: gcse-progress-tracker
description: Maintain GCSE topic state and progress records in the live Education Tracker service, for any subject being tracked (maths, Spanish, science, English and so on). Use this skill whenever anyone pastes an Update Block, reports session or test results, asks "how is she doing" / "how am I doing", asks for a progress report or grade projection, uploads a marked paper or mock for logging, or asks to update, correct or review the tracker. Also use at every mock checkpoint and for any tier-entry decision. Records evidence against subjects that already exist; use gcse-tracker-dashboard to create or extend a subject.
---

# GCSE Progress Tracker

Keeps the record truthful. The **Education Tracker** service at <https://education.rmmann.co.uk>
is the single source of truth for every tracked subject; this skill reads evidence in and writes
state back through its MCP connector (tools named `tracker_*`).

Full tool reference: `references/tracker-service.md`.
Grade-conversion facts shared by every subject: `references/exam-grading-common.md`.
Per-subject targets, checkpoints and quirks: `references/subjects/<slug>.md`.

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
2. **Ingest evidence.** Update Blocks, quoted scores, a marked paper, conversation history.
   Evidence must be concrete — what was done, the score, the date. **No evidence, no status change.**
   Practice at ≥ 80% over several runs is evidence that a topic is ready for a check, not evidence
   for a status: an app score is a reason to schedule the check, never a substitute for it.
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
   the subject, plus the link <https://education.rmmann.co.uk/s/{slug}>. When the reader is the
   student, link <https://education.rmmann.co.uk/s/{slug}/today> instead: it carries the next step
   and the last thing that went well, and none of the countdown, coverage or grade the parent's
   board shows.

If the connector is unavailable, say so and stop short of claiming anything was recorded. Keep
the evidence in the reply so it can be entered later.

**If you see `tracker_log_assessment` or `tracker_list_assessments`, the connector's tool list is stale.** Those were replaced by `tracker_log_attempt` / `tracker_list_attempts` / `tracker_get_attempt`. Ask the user to remove and re-add the Education Tracker connector in Settings → Connectors to pick up the current 21 tools. Don't fall back to the old assessment tools: they no longer exist on the server, so the call will fail.

## Status rules

Service statuses: `gap` 🔴 · `notstarted` 🟠 · `developing` 🟡 · `secure` 🟢 · `examready` 🔵.
These are identical in every subject and are never re-themed.

- `gap`/`notstarted` → **`developing`**: topic taught + practice at roughly the *developing bar*
  with support.
- `developing` → **`secure`**: an independent exit ticket or check at the *secure bar*, no hints.
- `secure` → **`examready`**: passes a spaced re-test ≥3 weeks after going secure (a retrieval
  starter or mixed check counts). Check the date with `tracker_history(ref: …)` — don't take
  "it's been a while" on trust.
- **Lapse:** a secure/examready topic failed in a starter or check → record it (evidence,
  `outcome: fail`), **keep the status**, set `watch` to what failed, and bring the next retrieval
  forward to within a week. One bad morning is not new information about what she knows.
- **Demotion** to the level below needs one of: a second fail on the topic within 6 weeks, two or
  more items wrong in one check, or a fail where the answer was left blank. Demote **one level
  only** — `examready` falls to `secure`, never straight to `developing`. One failed item is a
  lapse to record, not a demotion to propose.
- **Promotion earned inside the teaching session is provisional** until confirmed in a later
  session — the check that promoted a topic was run by the person who had just taught it.
- **Ageing:** untouched secure for 8+ weeks → the review queue flags it automatically. Report the
  flag; **do not demote without evidence**.
- **Never promote two levels in one session. Never promote on a single question.**
- Proposed changes (marked "?" in an Update Block, or logged as evidence with no status):
  adjudicate against these rules and say which you accepted and which you did not.

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
- **A correction must not touch the topic.** `tracker_update_topic` stamps `last_touched` with
  today's date whenever the argument is omitted, so a correction silently tells the review queue
  the topic was revised today and pushes the next retrieval eight weeks out. Read the topic's
  current `last_touched` from `tracker_get_state` and **pass it back explicitly** on every
  correction. (Proposed: a `touched: false` argument that makes this the default — until it
  exists, passing the date back is the only way.)
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

## Progress reports ("how is she doing?")

Read before writing: `tracker_get_state`, `tracker_history(weeks: 12)`, `tracker_list_attempts`.
Then, briefly and honestly:

1. **Counts by status per strand** (e.g. "Algebra: 3🟢 4🟡 2🔴 5🟠") and movement since the last
   report — `tracker_history` gives this week by week, so quote real dates rather than impressions.
2. **Trajectory** against the plan: ahead / on / behind, naming the blocking topics.
3. **Latest attempt** converted to a grade, and the projection toward the target grade recorded in
   `references/subjects/<slug>.md`. Grades come from the service's boundary conversion; quote the
   paper and series. If the subject has no verified boundaries stored, report the raw score and
   say no grade can be given yet — see `references/exam-grading-common.md`.
4. **The behavioural metric, where the subject has one.** For written papers: is she attempting
   every question in timed work? Blanks are recorded per paper on each attempt and should be
   falling toward zero. For subjects where blanks aren't the failure mode (speaking, orals), name
   the metric that is and say so explicitly rather than reporting a blanks count that means nothing.
5. **One recommended focus** for the coming week, taken from the per-topic marks-lost breakdown
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
