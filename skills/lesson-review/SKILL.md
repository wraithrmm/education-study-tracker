---
name: lesson-review
description: Write, audit and read the per-lesson learning review for the home-educated GCSE student — what was taught, what is secure or fragile, how she worked, which methods helped, what to teach next — and log it to the Education Tracker in the same call as the session. Use at the end of every taught session in any subject (tutor skills hand off to it; Mode A) — and load it the instant the student says "wrap up", "end the session", "log it", "record the session" or that she needs to go, however far the session got; a stop request is a log request, made by tool call in that turn, never by "just finishing this bit" and never as chat text. Also the scheduled nightly audit in each subject project (Mode B), and when the parent asks "review today's maths", "what did the audit change", "show me the signals" (Mode C). Owns the single fallback block for when the connector is down. Not for running a lesson (the subject's tutor skill) or adjudicating a status (gcse-progress-tracker).
---

# Lesson Review

The written half of one taught session, the way `parent-weekly-review` is the written half of
one week. The standard is the qualified educator's prompt in `references/review-prompt.md`; it
is the text of record and this skill does not restate it. `references/analysis-rules.md` maps
its nineteen sections onto the `review` object the tracker validates; `references/experiments.md`
governs the `next_test` field; `references/audit-checklist.md` is Mode B in full. Read the
prompt and the analysis rules before writing any review in a conversation. Teaching rules stay
in `gcse-progress-tracker/references/study-principles.md`, promotion bars in
`gcse-progress-tracker/SKILL.md`, the timetable contract in `references/timetable.md` there;
this skill references all three and duplicates none.

The Education Tracker (MCP connector, tools `tracker_*`) is the only store. A review is
versioned, staged (`draft` → `audited` / `parent`), signed (`session` / `audit` / `chat`) and
frozen against a server-built snapshot; it renders only behind the parent's login. Its `planner`
is copied to the session's `next_steps` and printed as `last_review` in the next
`tracker_review_queue`, so no tutor skill needs to know reviews exist to be fed by them.

## Which mode

| Mode | Trigger | Writes |
|---|---|---|
| **A — wrap-up** | A tutor skill closing a Shape A/C session, or a Shape B that changed a status; **or the student asking to stop, at any point, in any shape** | one `tracker_log_session` carrying `updates[]` and `review` |
| **B — audit** | The nightly scheduled task in a subject project; or the parent says "audit maths" | `tracker_save_lesson_review`, `tracker_update_topic`, `tracker_update_signal`, `tracker_audit_stamp` |
| **C — parent on demand** | "review today's maths", "what did the audit change", "show me the signals", "what's owed a review" ("how does she learn" at the weekly grain is `weekly-synthesis` Mode B) | reads; `tracker_save_lesson_review(stage: "parent")` only when the parent corrects something |

If a student-facing chat is asking, it is Mode A and nothing of the review is printed. If the
parent project is asking, it is Mode C. If a scheduled task is running, it is Mode B.

## Mode A — wrap-up

**A stop request is a log request.** Mode A runs the moment she says anything meaning she wants
to stop — "wrap up", "end the session", "log it", "record the session", "I need to go", "that's
enough" — however much or little of the session ran. Nothing is finished first, nothing is
deferred, nothing is checked with her: the tally is what ran, the review says what was cut, and
the `tracker_log_session` call is made in that turn. When she is finished is her decision, not
this skill's. The contract is `gcse-progress-tracker/references/timetable.md` § Closing.

Inputs are already in context: the transcript of the session just run, the
`tracker_review_queue` result it opened with (including `last_review` and, when a synthesis has
run, `this_week` — the week's plan for the subject and the test set for it), and `tracker_today`.
Add, before writing:

- `tracker_signals(subject)` — existing keys, so today's signals strengthen rather than
  duplicate. If `last_review` listed open signals, this is the same list; skip the call.
- `tracker_history(subject, ref)` for each ref in section 6 whose last outcome you do not
  already have from the queue.
- `tracker_list_practice(subject, since: <this week's Monday>)` when a retrieval block ran
  this week and its items bear on section 6.

Then, in this order:

1. **Tally.** From the transcript: questions set / attempted / correct unaided / correct after
   a hint / blank, per item, with her actual answers. Numbers go into every evidence string.
2. **Write the review** as the object in `analysis-rules.md` §3, section by section, in the
   third person, citing the transcript. Include only sections with evidence; the six required
   keys always. Answer `last_review`'s `check_whether` and any open `next_test` in `signals[]`
   with the same `key` and a direction. A test `this_week` names — set by the synthesis or the
   parent — is answered the same way when the lesson bore on it, run as its design was written
   (`experiments.md`), and left unanswered with a one-line `missing_evidence` entry when it did
   not. Give every new signal a `next_test`; a review may set or replace only its own tests,
   never one the synthesis or the parent set.
3. **Derive the log from the review**, not the other way round: `summary` is the
   `one_sentence` plus one clause on what ran; `next_steps` is the planner in one line (or
   leave it empty — the server copies the planner); `updates[]` carries a `retrieval_outcome`
   for every ref in section 6 and a `status` only where the bar was met; `watch` for anything
   fragile; `unfinished` and `resolves` as the timetable contract says. **If the session was cut
   short**, `next_steps` opens with the exact unfinished task — *"finish the exit ticket: the two
   negative-number questions on A17 (Q3, Q4), same questions"* — so the next session opens on it, `summary` says in one clause
   that it stopped early and at what point, and `missing_evidence` names what was therefore not
   observed. A review of a partial session is a normal review with fewer sections, not a reason
   to skip the call.
4. **One call — make it, do not describe it.** The review reaches the tracker only as the
   `review` argument of this call. Printing the review, the call, or the fallback block into
   chat when no call has been attempted is the failure this step exists to prevent:

```
tracker_log_session(
  subject: "maths",
  date: "2026-09-10",              // the day the work was done
  block_key: 3,                    // from tracker_today, when it ran against a block
  duration_minutes: 70,
  summary: "…",
  next_steps: "…",                 // optional; the planner is copied in if empty
  updates: [
    { ref: "A17", status: "secure", evidence: "exit ticket 4/4 unaided, incl. 2(x+3)=16" },
    { ref: "A4", evidence: "starter Q2: 6x²+8x → 2x(3x+4) unaided", retrieval_outcome: "correct" },
    { ref: "N2", evidence: "starter Q4: blank, then −3×−4=12 after 'signs first' hint",
      retrieval_outcome: "retry", watch: "negative × negative still needs the cue" }
  ],
  review: {
    topic_refs: ["A17", "A4", "N2"],
    objective: "…",
    one_sentence: "…",
    progress: [ { ref: "A17", status_seen: "secure", evidence: "…", implication: "…" } ],
    independent: "…", supported: "…",
    errors: [ { ref: "N2", error_type: "forgotten_prior", what: "…", why_type: "…", response: "…" } ],
    retention: { retrieved: [{ref: "A4", evidence: "…"}], prompted: [{ref: "N2", evidence: "…"}] },
    process: [ { area: "avoidance", basis: "observed", evidence: "0 blank of 9 attempted", interpretation: "…", implication: "…" } ],
    helped: [ { method: "modelling", effect: "helpful", evidence: "…" } ],
    signals: [ { key: "model-then-immediate-practice", kind: "teaching_method", statement: "…",
                 strength: "emerging", direction: "supports", evidence: "…", next_test: "…" } ],
    big_picture: { readiness: "progress_with_retrieval", why: "…" },
    next_what: { opening_retrieval: ["N2", "A4"], new: ["A18"] },
    next_how: { stages: [ { stage: "start", method: "retrieval_questions", why: "…" } ] },
    do_differently: ["…"], continue: ["…"],
    watch: [ { key: "signs-cue-dependence", what_to_observe: "…" } ],
    learner_voice: [ { quote: "I can't do the sign ones", context: "starter Q4" } ],
    planner: { priority: "…", start_with: "…", teach_using: "…", avoid: "…", check_whether: "…", success: "…" },
    missing_evidence: ["…"]
  }
)
```

5. **Read the response.** A refused review names the field: fix it and resend the whole call.
   A refused signal strength says the count: lower it. "REVIEW REQUIRED and not written" means
   the `review` key was missing from a session that needs one — send
   `tracker_save_lesson_review(subject, session_id, stage: "draft", written_by: "session",
   sections: …)` now, in the same turn.
6. **Only once the call has returned without error, tell her one line and nothing else:**
   *"logged — you can see it at https://education.rmmann.co.uk/s/<slug>"*. Never say "logged"
   before the response is in hand, and never say it of a summary that exists only as chat text.
   The review is never printed, summarised or alluded to in her chat. Sections 7–10 are for the
   parent and the next tutor.

Shape B sessions that changed no status are not reviewed — the log is enough. Retrieval
blocks are not reviewed; `retrieval-block-session` logs them and the next review reads them.

## Mode B — audit

`references/audit-checklist.md` is the procedure. In one line: open with
`tracker_review_audit_queue(subject)`; for each session owed a review, find its chat in this
project and write the review as `draft`/`audit`, or record the chat as unavailable; for each
draft, re-derive the tally from the transcript, compare, and save `audited` with a note listing
every correction; resolve each consistency flag by the table; close with
`tracker_audit_stamp(subject, note)`. The audit never logs a session, never raises a strength
directly, never touches the timetable, and never prints a review into a student chat.

## Mode C — parent on demand

| Ask | Do |
|---|---|
| "review today's maths" / "how did Tuesday's lit go" | `tracker_list_lesson_reviews(subject, since)` for the id, then `tracker_get_lesson_review(subject, session_id)`; relay the rendered review and its drift block. |
| "what did the audit change" | `tracker_list_lesson_reviews(subject, stage: "audited")`; for each, `tracker_get_lesson_review` and read the version `note`. |
| "show me the signals" / "how does she learn" | `tracker_signals(subject?)`; lead with `established`, then `emerging` with their open tests; one-offs only if asked. Trail per signal: the sessions cited. |
| "what's owed a review" | `tracker_list_lesson_reviews(subject, missing: true)`. |
| "that's wrong, she did X" | Re-read the review, correct the section, `tracker_save_lesson_review(stage: "parent", written_by: "chat", note: <what changed>)`. A status the parent says was wrong goes through `gcse-progress-tracker`. |
| "run the test" / "drop that signal" | `tracker_update_signal(id, next_test)` or `(id, status: "refuted", evidence: "parent decision: …")`. |

Answer plainly from what is stored. Where the review says `unknown`, say unknown.

## Connector unavailable

"Unavailable" means a `tracker_*` call was attempted in this turn and returned an error, or the
`tracker_*` tools are absent from the tool list. It is never assumed, and it is never a reason
to skip the attempt. Say so in one line. In Mode A, still write the review — as the fallback
block below, in a code block, so it can be pasted into a tracker chat later, where
`gcse-progress-tracker` turns it into the one `tracker_log_session` call. Never claim anything
was recorded, and never introduce the block as a log. In Modes B and
C, stop: an audit or a reading with no store is nothing.

## Fallback block — the only one

Emitted only after a `tracker_log_session` attempt failed in this turn (or the tools are
absent). If the connector answered, there is no fallback: the call is the log.

```
=== LESSON REVIEW — [date] — [subject slug] — block [key|extra] — [x] min ===
ONE SENTENCE: …
PROGRESS: ref — status_seen — evidence — implication (one line each; "proposed: secure" where the bar is not yet met)
ERRORS: ref — type — what — why this type — response
RETENTION: correct: refs | retry: refs | incorrect: refs | schedule: refs
PROCESS: area — basis — evidence — interpretation — implication
HELPED: method — evidence | HINDERED: method — evidence
SIGNALS: key — kind — statement — strength claimed — supports/contradicts — evidence — next test
READINESS: progress | progress_with_retrieval | consolidate | partial_reteach | significant_reteach — why
NEXT WHAT: opening_retrieval: refs | reteach: | consolidate: | new: | misconception_check: | challenge:
NEXT HOW: stage → method — why (one per line)
DO DIFFERENTLY: … | CONTINUE: … | WATCH: key — what to observe
PLANNER: priority / start with / teach using / avoid / check whether / success
(`start_with` and `next_steps` name the task by what she will do — "finish the storm description,
handwritten, 40 min" — with any ref or Q-number in brackets after, never as the name. The next
session's opener is built from this line; study principle 12 says it must be sayable to her.)
LEARNER VOICE: "…" (context)
MISSING EVIDENCE: …
UPDATES: ref — status? — evidence — retrieval_outcome? — watch?
=== END ===
```

Every line is a field of the `review` object or of `updates[]` under the same name, so the
paste-in is a transcription, not a reinterpretation.
