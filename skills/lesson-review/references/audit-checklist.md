# Audit checklist — Mode B

The audit is the impartial reviewer: a scheduled run in each exam-subject project that confirms
every taught session was logged and reviewed, re-derives each draft review from the transcript,
corrects what the evidence does not support, and closes with a stamp. It reads the same rules
the session did (`analysis-rules.md`, `experiments.md`) and applies them without the session's
stake in the outcome. It writes through `tracker_save_lesson_review`, `tracker_update_topic`
and `tracker_update_signal`, and never through `tracker_log_session` — an unlogged session is
a finding the parent decides, not a row the audit invents.

## 0. Open

`tracker_review_audit_queue(subject)`. It returns, computed server-side:

1. sessions since the last stamp with `review_required` and no review;
2. reviews still at `draft`;
3. consistency flags (§3 below), each with ids;
4. the previous audit's note.

An empty queue is still stamped (§5) — "nothing to verify since <date>" is a record. On a
night with no sessions owed, no drafts and no new flags, open no chat and re-read no audited
review: stamp and stop. Anything already named in the previous stamp note is carried, not new
work.

## 1. Sessions logged without a review

For each: find the chat. `recent_chats` bounded to the session date, or
`conversation_search` within the project on the topic names in the session summary. This
project's chats are the only ones visible, so:

- **Chat found** → write the review from the transcript as Mode A would (third person, tally
  first, cite everything), `tracker_save_lesson_review(stage: "draft", written_by: "audit")`.
  A draft, because nobody has verified it — the next audit will. Note in the review's
  `missing_evidence[]` that it was written after the fact.
- **Chat not found** → do not write a review from the summary alone. Record the session id
  and date in the stamp note under "transcript unavailable" so the parent can decide. It will
  be listed again tomorrow, marked carried over, until it is reviewed or the parent waives it;
  a session already named in the previous stamp note is carried, not re-searched.

## 2. Drafts — re-derive, then compare

For each draft:

1. Open the chat (`conversation_search` with `within_conversation_id`, then `read_conversation`
   at the hit). Build the tally: set / attempted / correct unaided / correct after hint / blank,
   per question, with her answers.
2. `tracker_get_lesson_review(subject, session_id)` — read the draft and its snapshot.
3. Compare section by section against the tally and the transcript:
   - `progress[]` evidence strings cite things that happened, with the right numbers;
   - `status_seen` is what the evidence supports, not what the tutor hoped;
   - every `updates[]` promotion in the snapshot met its bar (points: 80% unaided; band: target
     descriptor unaided, twice on different tasks — `gcse-progress-tracker` § Status rules);
   - `errors[]` classifications are defensible from her actual answers; `undetermined` where
     the transcript does not distinguish;
   - `learner_voice[]` quotes appear verbatim in her turns, not the tutor's;
   - `process[]` entries at `observed` have a citation; the `avoidance` count matches the tally;
   - `signals[]` strengths match what the count rule allows; keys do not duplicate an existing
     signal (`tracker_signals(subject, status: "any")`);
   - the `planner` is specific enough to act on without the review.
   If the draft's chat cannot be found, the comparison is against the record only: check the
   snapshot's statuses, outcomes and signal counts against the sections, and save
   `stage: "audited"` with `note: "transcript unavailable; verified against record only"`.
4. **Nothing wrong** → `tracker_save_lesson_review(stage: "audited", written_by: "audit",
   sections: <the draft unchanged>, note: "verified against transcript; no corrections")`.
   An identical re-save adds no version, so send the draft's sections as read plus the
   `note` — the stage change is what is recorded.
5. **Corrections needed** → save `stage: "audited"` with the corrected sections and a `note`
   that lists each change: "§2 A17 status_seen secure → developing (exit ticket 3/4, bar is 4/4);
   §18 removed one quote (tutor's words); §11 signal x strength emerging → one_off (only this
   session cites it)". The note is the audit trail the parent reads.

## 3. Consistency flags — what each means and what to do

| Flag | Meaning | Action |
|---|---|---|
| secure seen, no move, no proposal | §2 claims a bar met that `updates[]` did not apply | Re-read the evidence. Bar met → `tracker_update_topic(status: "secure", evidence: "audit: bar met in session N — …")`. Not met → correct `status_seen` in the audited version. |
| promotion with no number in its evidence | A status moved on prose | Find the score in the transcript. Present → amend the evidence via the audited review note and `tracker_update_topic` with the number. Absent → `tracker_update_topic` back to the previous status, evidence "audit: promotion in session N unsupported — no score recorded". |
| two-level rise in one session | Should be impossible via `tracker_log_session`; reached through `tracker_update_topic` | Revert one level with evidence naming the rule. |
| retention entry without an outcome | §6 lists a ref no `retrieval_outcome` backed | Cannot be fixed on the session; note it, and set the outcome by `tracker_update_topic` on the ref with evidence "audit: retention outcome from session N — correct/retry/incorrect". |
| test untested for 3+ sessions | A `next_test` nobody ran | Leave the signal; it goes to the parent through the weekly review. Note it in the stamp. |
| synthesis test unanswered | A test the synthesis or the parent set for a week that has ended, with no evidence row on any signal of that key that week | Leave the signal; note it in the stamp with who set it — the weekly review puts it to the parent. Never answer it from the record. |
| watch unreferenced for 5+ sessions | Nobody looked | Read the last three sessions' transcripts for the behaviour. Seen → `tracker_update_signal(id, session_id, direction, evidence)`. Not seen → `tracker_update_signal(id, status: "resolved", evidence: "audit: not observed in sessions N–M")`. |
| quote also in the session summary | Likely the tutor's words attributed to her | Check her turns. Not hers → remove it in the audited version and say so in the note. |

Statuses are append-only: every correction is a new change whose evidence starts "audit:".
Never `tracker_amend_session` to reword a summary the review disagrees with; the review is
where the disagreement is recorded.

## 4. What the audit does not do

- Does not raise a signal's strength directly (strength is derived; write evidence rows).
- Does not promote a topic the session did not earn just because the review is good.
- Does not excuse blocks, decide days off, or edit the timetable.
- Does not write a review for a session whose chat it cannot read.
- Does not rewrite prose for style. A draft that is right but plain is audited unchanged.
- Does not print any of the review in a student-facing chat. The audit runs in the subject
  project as a scheduled task; its output is the stamp note and the versions it saved.

## 5. Close

`tracker_audit_stamp(subject, note)`. The note, 10–1000 characters, in this order:

1. Sessions verified (ids), and how many were corrected.
2. Corrections made, one clause each, with the session id.
3. Reviews written after the fact (ids).
4. Transcript unavailable (ids, dates) — for the parent.
5. Flags left for the weekly review (untested tests, by signal id).
6. "No corrections" when that is the case.

The stamp empties the queue; the next run starts from here.
