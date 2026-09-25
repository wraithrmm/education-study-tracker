---
name: spanish-maintenance-session
description: Run a short Spanish maintenance slot for the home-educated student — 10–30 minutes of spaced vocabulary retrieval, a short listening or reading, one grammar point — as foundation for a 2028 AQA GCSE Spanish (8692) entry, not a 2027 exam. Use this skill whenever the student asks for Spanish help of any kind in chat — vocab, a phrase, a tense, "what's my Spanish today", a quick test, help with a set from the vocabulary app — and even for one-off questions apply its rules. Also use when the parent asks to plan or review Spanish. Opens from the live Education Tracker, checks the weekly timetable, and logs the slot as practice so the block shows as done. Do not use for building or editing the vocabulary app itself — that is language-learning-platform; do not use for marking a paper — that is gcse-spanish-marker.
---

# Spanish Maintenance Session (AQA 8692 — foundation for 2028)

Short, frequent, spaced. This subject is **not** being sat in 2027; the slots exist to keep
vocabulary and core grammar warm so that 2028 preparation starts from a base, not from zero.
Companion skills: `language-learning-platform` (the app and its sets), `gcse-spanish-marker`
(if a paper is ever marked), `gcse-progress-tracker` (its `references/study-principles.md`
holds the shared teaching rules — principle 9 is about this subject — and its
`references/timetable.md` the timetable contract; read both before the first slot of a
conversation).

## The tracker is the source of truth


### Before anything else: is this a retrieval block?

If `tracker_today` says the current block's `kind` is `retrieval`, or the block names more than
one subject, **stop and load `retrieval-block-session`** — it owns those blocks for every
subject and logs them correctly. Come back here for the teaching block that follows. This is not
optional and not a judgement call: a warm-up improvised inside a subject skill is single-subject,
un-interleaved, and ticks a mixed block it did not satisfy.

`gcse-progress-tracker/references/block-kinds.md` maps every block `kind` to its owner, its shape
and how it progresses. Read it before running a kind you have not run before.

Subject slug: **`spanish`** (confirm with `tracker_list_subjects`). Open every slot with:

```
tracker_review_queue(subject: "spanish")
tracker_today()
```

If her request matches a Spanish block today (Wed 09:45, Thu 11:45, Fri 13:45), say so in one
line and keep the `block_key` for the log. If it doesn't, help anyway and log honestly — it shows
as *extra*. A Spanish block missed earlier today is mentioned once, without reproach; the same
work now, logged with today's date and that `block_key`, counts as it.


### Third call, every session: what the last session left

```
tracker_history(subject: "spanish", weeks: 2)
```

Study principle 11, and it is mandatory. Read the most recent non-void session's **Planned next**
and its summary tail, then say in one line what this session opens with — **in plain words, per study principle 12**:
what she was doing last time, where she got to, what happens now. Never the planner's, the
queue's or the timetable note's wording, and never an exam code as the name of the task.
**Unfinished work from the last session comes first** — an exit ticket cut short then is finished now, before anything
new, with the same questions. (This governs how a session *opens*. It never means today's work
must be finished before today's session can be closed — see Closing: a stop request is logged
as it stands.) If
the review queue now disagrees with that plan (a demotion, a failed ageing check), follow the
queue and say in one line why the plan changed. If the plan is stale or already covered, say so
and take the queue's top item. Never silently ignore it: every session writes `next_steps`, and a
plan nobody reads is a plan nobody will write honestly.

The queue's `last_review` block, when one exists, carries the last taught session's planner and
open signals; Spanish slots rarely produce one, so read it if present and otherwise plan from
`last_session.next_steps`. `this_week`, when a synthesis has run and Spanish has a block next
week, is the week's plan for the slot; open on its `retrieve_first` line unless
`tracker_retrieval_due` has overtaken it.

Then as needed: `tracker_get_state`, `tracker_list_resources`, `tracker_list_practice(subject:
"spanish", since: …)` to see which sets the app has already drilled this week. **Never invent a
URL.**

## The slot — 15 minutes by default, never more than 30

1. **Retrieval first (5–8 min).** 8–12 items from sets already met, on an expanding schedule
   (yesterday's set → 3 days ago → last week → a fortnight ago); the review queue and the practice
   log tell you which. Selection and order follow `retrieval-block-session`: items missed in the
   last fortnight first, mixed across sets rather than one list at a time, direction alternating
   English → Spanish and Spanish → English. Marked together, no
   drama about misses; a miss is just "goes back to tomorrow's pile".
2. **One new thing (5 min).** Either ≤ 6 new words from the next set in spec order, or **one**
   grammar point applied in three sentences she writes (ser/estar, one tense, a connector). Not
   both. Encoding matters for her: say it, see it, use it in a sentence she makes.
3. **Use it (3–5 min).** A 4-line dialogue, a short listening clip from the stored resources, or
   a 3-sentence reply to a prompt. Speaking counts: ask her to say the sentences aloud and tell
   you she did.
4. **Log** (below).

Rules: one instruction per message; timer visible; stay in Spanish for the slot; never a 40-word
list in a 15-minute slot. Blanks apply here too: "I don't know the word" → "what's the nearest
word you *do* know?" — a wrong attempt is logged as `retry`, a blank as `incorrect`, and both
are fine.

**Spanish always loses a tie.** If the day is overrunning, this slot is the one shortened or
dropped; say so plainly rather than squeezing an exam subject to fit it.

## Logging — mandatory, and it is practice, not a session

A maintenance slot is logged as **practice** (it never changes a topic status):

```
tracker_log_practice(
  subject: "spanish",
  runs: [{
    client_run_id: "chat-2026-09-09-spanish-1",   // stable per slot; a retry is a no-op
    source: "spanish_chat_retrieval",
    label: "Retrieval: free time + ser/estar",
    played_at: "2026-09-09T09:50:00+01:00",       // the real time the work was done
    block_key: 20,                                 // from tracker_today, when it ran in a slot
    duration_seconds: 900,
    attempted: 12, correct: 8, correct_after_retry: 3, incorrect: 1,
    topic_refs: ["…"],
    items: [ { outcome: "correct", prompt: "tiempo libre" }, … ]
  }]
)
```

`attempted` must equal `correct + correct_after_retry + incorrect` or the call is refused. A
maintenance slot is not reviewed: its block kind does not require a lesson review, and the next
review reads these runs through `tracker_list_practice`. If the slot also included a genuine
teaching step that **changed a status** (rare — promotion needs the 70%/80% bars over more than
one slot), close that part by loading **`lesson-review` (Mode A)**, which writes the review and
the `tracker_log_session` in one call with the same `block_key`; propose, don't assert, and
`gcse-progress-tracker` adjudicates. Evidence conventions are in `gcse-progress-tracker/SKILL.md`
§ Status rules, not here.

Timetable rules (`references/timetable.md`): never log a ceremonial run to tick a block; never
excuse a block; if the chat seems to be ending unlogged, say once *"not logged yet — say 'log it'
and today's Spanish slot will show as done"* — and when she does, log.

**A stop request is a log request — no exceptions.** "wrap up", "log it", "record it", "I need to
go", "that's enough" — anything meaning she wants to stop — is answered by making the
`tracker_log_practice` call *in that turn* with the counts as they stand, whatever step the slot
is on. Never finish the set first, never ask if she's sure. Items not yet asked go in the run's
`label` or the next-slot note as unfinished so the next slot re-asks them. When she is done is
her call, not yours.

**"Logged" means the call returned.** Say *"logged — https://education.rmmann.co.uk/s/spanish"*
only after `tracker_log_practice` (and `tracker_log_session`, where a status changed) succeeded
in this turn. Practice figures typed into chat are not a log; the fallback block below is only
for a call that was actually attempted and failed.

## Days off

If she asks for a day off in this chat, relay it: `tracker_request_day_off(date_from, date_to,
reason, requested_by: "student")`. Say it goes to Dad to approve; never approve it yourself.

## Fallback

Connector unavailable — meaning a `tracker_*` call was attempted this turn and failed, or the
tools are absent, never assumed → say so in one line, run the slot from what she tells you, and
end with the practice block below, never introducing it as a log. A slot that also changed a status ends instead with `lesson-review`'s
fallback block, which carries the practice figures in its retention line:

```
=== PRACTICE — [date] — spanish ===
Slot: [block_key or "extra"] | ~[x] min
Retrieval: [n attempted / correct / retry / blank] from sets [names]
New: [words or grammar point]
Used: [dialogue / listening / sentences]
Next slot: [one line]
=== END ===
```
