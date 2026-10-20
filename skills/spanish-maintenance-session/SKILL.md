---
name: spanish-maintenance-session
description: Run a short Spanish maintenance slot for the home-educated student — 10–30 minutes built around the vocabulary app on the tracker site (education.rmmann.co.uk/s/spanish/play), which is the primary teaching tool for this subject — a game or two on the set that is due, then production in the chat of what the game only recognised, one new thing, and a short use of it — as foundation for a 2028 AQA GCSE Spanish (8692) entry, not a 2027 exam. Use this skill whenever the student asks for Spanish help of any kind in chat — vocab, a phrase, a tense, "what's my Spanish today", "which set should I play", a quick test, what her stars mean, help with a set from the app — and even for one-off questions apply its rules. Also use when the parent asks to plan or review Spanish, or what the app's results say. Opens from the live Education Tracker, checks the weekly timetable, reads the games the app has already logged for itself, and logs the chat half as a session whose updates move the topics it touched, so the subject's progress page and forecast see Spanish moving. Do not use for building or editing the app or its sets — that is language-learning-platform; do not use for marking a paper — that is gcse-spanish-marker.
---

# Spanish Maintenance Session (AQA 8692 — foundation for 2028)

Short, frequent, spaced. This subject is **not** being sat in 2027; the slots exist to keep
vocabulary and core grammar warm so that 2028 preparation starts from a base, not from zero.
Companion skills: `language-learning-platform` (the app and its sets), `gcse-spanish-marker`
(if a paper is ever marked), `gcse-progress-tracker` (its `references/study-principles.md`
holds the shared teaching rules — principle 9 is about this subject — and its
`references/timetable.md` the timetable contract; read both before the first slot of a
conversation).

## The app is the primary teaching tool

The vocabulary app lives on the tracker site at **https://education.rmmann.co.uk/s/spanish/play**:
Flashcards and the Shooting Gallery over the sets the tracker holds, grouped by the AQA topic
each set teaches. It is where the vocabulary is met and kept warm; this chat is where it is
**produced** — said, written, used in sentences she makes — which is the half a game cannot do.
Every slot is built around the app, in this order: the app first, the chat second.

What the app does on its own, so you never do it from here:

- **Logs every finished game** as a practice run (`spanish_gallery` or `spanish_flashcards`),
  one item per word shown, under the set's topic refs. The run binds to the day's Spanish
  block, so a game played in the slot ticks it, and the game-over screen tells her so. **Never
  log an app game from the chat** — it is already in the record, and a second row would count
  her answers twice. Read games with `tracker_list_practice(subject: "spanish", source:
  "spanish_gallery")` (or `spanish_flashcards`).
- **Advances the retrieval schedule** on every word, at item grain, so `tracker_retrieval_due`
  already knows which words the games keep missing.
- **Scores each set in stars**, read with `tracker_list_vocab_sets(subject: "spanish", words:
  false)`: one star for played (five or more words), two for a game at 80%+ right first time,
  three for a second 80%+ game at least three days after the first — the subject's own secure
  bar applied to the game. The same call lists, per topic, the move the games support.
- **Offers Dad the mark-off.** Under the app, a topic whose games have met a bar shows a
  "ready to mark" line with the evidence written out; signed in, he marks it with one click and
  that logs a session. Recognition in a game never moves a status by itself, and this chat does
  not either: it moves a status only on production she did here, under the rules below.

What stars mean for the slot:

| Stars on the set that is due | What the slot does with it |
|---|---|
| ☆☆☆ not played | Flashcards on it in the app first (she judges each card), then the gallery once; the chat takes ≤ 6 of its words into production as the *one new thing*. |
| ★ played, no 80% game yet | One gallery game on it to open the slot; the chat re-asks the words the game missed (`tracker_retrieval_due` has them), then production on the ones it got. |
| ★★ one 80% game | Leave it until three days have passed since that game, then one more gallery game; meanwhile production in the chat is what it needs. |
| ★★★ met the bar | Done with recognition. Production only — sentences, a dialogue, the grammar point behind it. One gallery game a fortnight keeps it warm; never a recognition round in the chat. |

**Which set is due.** The lowest-numbered set below three stars whose last game is at least
three days old, plus — when there is time — one three-star set that has not been played for a
fortnight. A new set is opened only when the set before it has two stars or more: the sets are
sequenced, and the gallery mixes earlier sets into later ones, so skipping ahead makes every
later game harder than it should be. Say which set and why in one plain line ("Set 3 today —
you got 80% on Monday, so one more game and it's yours"); never the ref or the star rule.

**Sets are data.** Which sets exist, their words and their topic refs come from
`tracker_list_vocab_sets`, never from memory of the old file. A set that teaches a topic she
is being asked to produce but which has no games yet is the first thing to send her to play.
Adding or fixing a set is `language-learning-platform`'s job, with `tracker_save_vocab_sets`.

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

**Homework out.** If `tracker_today` lists a `set` homework sheet — any Spanish sheet, or another
subject's once it is `OVERDUE` — the first message names it before the slot: what it is on, when
it is or was due, the link, and that her Homework project is where she does it. Once, without
reproach. Never work or re-set a question from the sheet (`references/timetable.md` § Opening;
`homework-session` runs the sheet).

Then `tracker_now()` at the start of every turn while the slot runs. Only the clock ends the slot
or any extra time she asks for — never your estimate of how long a set should have taken. If she
says she has more time, write the agreed end time in your reply, pass it as `until`, and keep
giving items until the clock passes it. `duration_minutes` is the clock at close against open.


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

### Fourth call, every slot: the app's record

```
tracker_list_vocab_sets(subject: "spanish", words: false)
tracker_list_practice(subject: "spanish", source: "spanish_gallery", since: <a fortnight ago>)
```

The first gives every set with its stars, last game and next step, and the per-topic moves the
games support; the second says what she has played this week, including a game she may have
played before opening this chat today. A game logged today in the slot's block has already
ticked it: say so ("the app's logged your game, so today's Spanish is already on the board")
and the chat half is the production. If she has not played yet, step 1 below sends her to play.

Then as needed: `tracker_get_state` (the refs behind a set), `tracker_list_resources`, and
`tracker_retrieval_due(subjects: ["spanish"])` for the words the games keep missing, which are
the first things the chat asks for in production. **Never invent a URL**; the app's is the one
above, and it is the only link a slot sends her to.

## The slot — 15 minutes by default, never more than 30

1. **The app first (5–8 min).** Send her to https://education.rmmann.co.uk/s/spanish/play with
   the set named in plain words and the game to play (the stars table above decides which). One
   instruction: "Set 3, Shooting Gallery, one game, then come back and tell me the score." The
   game logs itself and the retrieval schedule moves; while she plays, read nothing and teach
   nothing. When she is back, `tracker_list_practice(subject: "spanish", source:
   "spanish_gallery", limit: 1)` confirms the run landed — quote her score back from the record,
   not from what she typed — and `tracker_retrieval_due(subjects: ["spanish"])` names the words
   it missed. If the app is down or she cannot open it, fall back to a chat retrieval of 8–12
   items as `retrieval-block-session` runs one (missed-first, mixed across sets, direction
   alternating), logged as `spanish_chat` practice; that is the only case the chat logs
   retrieval.
2. **Production of what the game recognised (4–6 min).** The words the game just showed her,
   now the other way: she produces the Spanish from the English, aloud and typed, then puts
   three of them into sentences she makes. Start with the words the game missed. This is the
   step that turns a star into a status: recognition is the app's, production is yours, and
   only production goes in the session's evidence.
3. **One new thing (3–5 min).** Either the next set's first ≤ 6 words — met in the app's
   flashcards if she has not played that set, then produced here — or **one** grammar point
   applied in three sentences she writes (ser/estar, one tense, a connector). Not both.
   Encoding matters for her: say it, see it, use it in a sentence she makes.
4. **Use it (2–4 min).** A 4-line dialogue, a short listening clip from the stored resources, or
   a 3-sentence reply to a prompt. Speaking counts: ask her to say the sentences aloud and tell
   you she did.
5. **Log** (below).

Rules: one instruction per message; timer visible; stay in Spanish for the slot; never a 40-word
list in a 15-minute slot. Blanks apply here too: "I don't know the word" → "what's the nearest
word you *do* know?" — a wrong attempt is logged as `retry`, a blank as `incorrect`, and both
are fine. A slot that is only a game is a game, not a slot: if she played and left, the block
is ticked by the game and nothing is owed, but no status moves until a chat produces something.

**Steps 3–4 are the three-step teach of study principle 13, scaled to the slot:** the one new
thing is taught with ≥1 teaching question, confirmed with ≥2 hinted items (say it back, fill the
blank with the frame shown), then applied in ≥2 unaided items in step 4. **Touched means
moved:** a tracker topic she answered on does not stay `notstarted` — unaided items right →
`developing`; answered below that → `gap` with a watch; only an item set and not answered stays
`notstarted`. The status goes in the session's `updates[]` (below), with the counts as evidence.

**Spanish always loses a tie.** If the day is overrunning, this slot is the one shortened or
dropped; say so plainly rather than squeezing an exam subject to fit it.

## Logging — mandatory: the session, and a practice run only for retrieval done in the chat

The block is judged on a practice run, and the app's game is that run when she played one
in the slot: it is already logged, so **do not log it again**. The session is what moves the
topics: it is the only thing the progress page (`/s/spanish/progress`), its forecast and the
weekly review's `tracker_progress_forecast` read, and a slot that is only a game leaves Spanish
reading as stalled week after week however much was learned. So every slot ends with the
session call, in the same turn as the slot ends, and the session's summary names the app run
it built on (its run id from `tracker_list_practice`).

**1. The practice run** — only when the retrieval was done in the chat (the app was down, or
the slot was a chat retrieval of items the games keep missing). Never for an app game:

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

`attempted` must equal `correct + correct_after_retry + incorrect` or the call is refused.
When the app's game is the slot's run, pass no `block_key` on the session below if the game was
played in the block: the game already holds it, and the session is the slot's second record.

**2. The session, with one update per topic the slot touched** (never skipped):

```
tracker_log_session(
  subject: "spanish", date: "2026-09-09", duration_minutes: 15,
  summary: "App: Set 2 gallery 11/13 (run 91). Production: 9/11 from English, 3 sentences; then Set 3 opened: 6 words, used in three sentences.",
  updates: [
    { ref: "T01", status: "developing",
      evidence: "opened: Set 2 (6 words) taught, said aloud and used in 3 sentences she wrote" },
    { ref: "T04", evidence: "retrieval 7/8 unaided on Set 1, 5 days after last asked" },
    { ref: "G22", evidence: "retrieval 2/4: stem changes in nosotros form still slipping" }
  ],
  next_steps: "Re-ask the two G22 misses first; then Set 3 of T01."
)
```

Every topic the slot retrieved from or taught gets an `updates[]` entry. An entry with no
`status` keeps the status and records the evidence: it is what the progress page counts as a
touch, and a topic retrieved from three slots running with no status change is exactly the
signal the parent should see. Map every set and grammar point to its topic ref with
`tracker_get_state(subject: "spanish")`; the app's set names are not refs.

**The status rules, in this subject's shape.** They are `gcse-progress-tracker`'s rules (§ Status
rules, points-marked bars) applied to little-and-often work; say which one you applied in the
evidence string.

- `notstarted` → **`developing`**: the slot's *one new thing* was taught, confirmed and then
  applied right in ≥2 unaided items (principle 13) — words or a grammar point used in sentences
  she made without the frame. Answered below that → **`gap`** with a watch naming the step owed.
  An 80% game on the set is evidence of recognition and goes in the string ("Set 3 gallery
  12/15 on 9 Sep, run 91"), but the move rests on the production she did here. Dad can also
  make this move from the page, on the game alone, with evidence that says so; if he has, the
  topic is already `developing` and the slot's job is the production that keeps it there.
- `developing` → **`secure`**: production on that topic's sets at **≥ 80% unaided in two
  different slots at least three days apart**, the later one at least a week after the topic
  was opened. Quote both scores and dates. Two slots, because one good retrieval the day after
  teaching is recency, not retention. The app's three stars on a set are the same bar met in
  recognition; they make the topic *ready* for this move and the page offers it to Dad, but
  from the chat the move needs the production figures.
- `secure` → **`examready`**: a spaced re-test at ≥ 80% at least three weeks after going secure
  (`tracker_history(subject: "spanish", ref: …)` for the date). Rare here and never proposed
  from a single set.
- **Demotion**: a secure topic below 50% in a retrieval → `developing`, with what failed in the
  note. One miss is a `retry` on the schedule, not a demotion.
- Never two levels in one slot; never on a single item; never a status with no score or date in
  its evidence. If the evidence points further than the bar allows, keep the status and say so
  in `next_steps`; `gcse-progress-tracker` adjudicates a disputed one.

A maintenance slot is not reviewed: its block kind does not require a lesson review, and the
`tracker_log_session` above is the whole record. Only when a slot became a genuine taught
lesson (a grammar point worked through with examples and an exit check, 20 minutes or more)
close it by loading **`lesson-review` (Mode A)** instead, which writes the review and the session
in one call with the same `block_key`.

Timetable rules (`references/timetable.md`): never log a ceremonial run to tick a block; never
excuse a block; if the chat seems to be ending unlogged, say once *"not logged yet — say 'log it'
and today's Spanish slot will show as done"* — and when she does, log both calls.

**A stop request is a log request — no exceptions.** "wrap up", "log it", "record it", "I need to
go", "that's enough" — anything meaning she wants to stop — is answered by making both calls
*in that turn* with the counts and the updates as they stand, whatever step the slot is on.
Never finish the set first, never ask if she's sure. Items not yet asked go in `next_steps` as
unfinished so the next slot re-asks them. When she is done is her call, not yours.

**"Logged" means the calls returned.** Say *"logged — https://education.rmmann.co.uk/s/spanish"*
only after `tracker_log_session` (and `tracker_log_practice`, when the chat did the retrieval)
succeeded in this turn. Practice figures typed into chat are not a log; the fallback block below
is only for a call that was actually attempted and failed. The app's game is logged by the app:
check it landed, never re-log it.

## Days off

If she asks for a day off in this chat, relay it: `tracker_request_day_off(date_from, date_to,
reason, requested_by: "student")`. Say it goes to Dad to approve; never approve it yourself.

## Fallback

Connector unavailable — meaning a `tracker_*` call was attempted this turn and failed, or the
tools are absent, never assumed → say so in one line, run the slot from what she tells you, and
end with the block below, never introducing it as a log. `gcse-progress-tracker` turns it into
the calls when it is pasted: a chat retrieval line becomes a practice run (the App line is already logged), and each UPDATES
line becomes an `updates[]` entry with its status (or none) and its evidence, exactly as
written. A slot that became a taught lesson ends instead with `lesson-review`'s fallback block.

```
=== PRACTICE — [date] — spanish ===
Slot: [block_key or "extra"] | ~[x] min
App: [set and game, score, run id if known — or "not played"]
Retrieval (chat only): [n attempted / correct / retry / blank] from sets [names] — or "none, the app did it"
Produced: [n from English unaided / sentences made]
New: [words or grammar point]
Used: [dialogue / listening / sentences]
UPDATES:
- [ref] [status or "—"]: [evidence with score and date]
- [ref] [status or "—"]: [evidence]
Next slot: [one line]
=== END ===
```
