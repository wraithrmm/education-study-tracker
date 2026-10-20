# The vocabulary app — service contract

What `php/lib/vocab.php`, `php/lib/dashboard_vocab.php`, `php/lib/mcp_vocab.php`
and the `/s/<subject>/play` routes in `php/index.php` do, and the decisions
taken on the way. Written against schema step 17.

## What moved, and what changed by moving

The single-file Spanish app — a React artifact with a `vocabularySets`
object, Flashcards and a Shooting Gallery — now runs on the site at
`/s/<subject>/play`, in plain JavaScript with no build and no CDN, like the
class bell and the exam sitting. Three things change:

1. **The sets are data.** Table `vocab_sets` (subject_slug, number, name,
   topic_refs JSON, words JSON, note), one row per set, numbered as the app
   numbered them. `tracker_list_vocab_sets` reads, `tracker_save_vocab_sets`
   upserts by number and deletes with `delete: true`. A set names the
   tracker topics it teaches in `topic_refs` (the app's `trackerRefs` is
   accepted); the first ref is the one each word's practice item carries.
2. **Games log themselves.** A finished game POSTs to `/s/<subject>/play/run`.
3. **Results read as steps.** Stars per set; a proposed move per topic; the
   parent marks it off and that logs a session.

## Decisions

- **The browser is trusted with outcomes and nothing else.** The POST carries
  `game`, `set`, `client_run_id`, `played_at`, `duration_seconds`,
  `top_speed` and `items[] of { word, set, outcome }`. The server builds the
  run: label from the set, run-level `topic_refs` from the set, each item's
  `topic_ref` from the set the word came from (a word mixed in from an
  earlier set carries that set's ref), `item_key` `vocab:<set>:<word>` for
  the retrieval schedule, `metrics.set` so a run joins back to its set.
  `played_at` is held to the last six hours; counts are capped (200 items).
- **One validation path.** The run goes through `mcp_practice_prepare()` and
  `mcp_practice_store()`, extracted from `tracker_log_practice` so the two
  cannot drift: the arithmetic, the registered source, the item caps and the
  retrieval scheduling are the same whichever door a run comes in by.
- **Registry keys are `<subject>_gallery` and `<subject>_flashcards`,**
  registered on first use when missing. For Spanish those are the keys the
  old app already logged under, so the trend line continues; the metrics
  schema gains `set`.
- **The block is read back, not predicted.** After storing, the server judges
  the run's local date and reports the block whose evidence is this run, so
  the game-over screen can say "that counts as today's Spanish slot". A game
  binds by subject and date like any practice run (the `spanish` block kind
  has no shape rule); nothing special-cases it.
- **No login to play or to log**, the same trust the exam sitting extends:
  she has no login and needs none. A failed post is kept in `localStorage`
  and sent the next time the page opens; `client_run_id` makes the retry a
  no-op if the first attempt did land.
- **Flashcards have outcomes.** After flipping a card she says "knew it" or
  "not yet", so the round logs with real items rather than a count of cards
  looked at. A round of fewer than five judged cards is stored but earns
  nothing.
- **Stars are the subject's own bar.** One: played, at least five words. Two:
  a game at ≥ 80% right first time. Three: a second ≥ 80% game at least
  three days after the first — `developing → secure` from
  `spanish-maintenance-session` applied to the game. The thresholds are
  constants in `vocab.php` (`VOCAB_MIN_ITEMS`, `VOCAB_GOOD_ACCURACY`,
  `VOCAB_SPACING_DAYS`).
- **A game never moves a status.** The steps propose at most one move per
  topic: `notstarted`/`gap → developing` on a first good run, with evidence
  that says *recognition, production not yet shown*; `developing → secure`
  on the spaced pair, quoting both runs and the gap. `secure` and
  `examready` are never proposed. The parent, signed in, posts
  `/s/<subject>/play/mark` (cookie + CSRF) with the ref, the status and the
  evidence as shown or edited; the server calls `tracker_log_session` with
  one update, so the move is a session with evidence, stamped on the change,
  dated today. Two levels in one step are refused.
- **The seed is an import, not the record.** `php/lib/seed/<slug>-vocab.json`
  is written by `node deploy/import-vocab.js app.jsx` (evaluates the
  `vocabularySets` literal in a bare VM, carries `trackerRefs`) and loaded
  once per subject, on the first request after the subject exists, under the
  meta key `vocab_seeded_<slug>`; a bad seed is logged, never a 500. The
  seed's refs are not checked against the subject's topics, so a smaller
  subject still gets its sets. `php deploy/import-vocab-csv.php master.csv`
  turns the AQA master list (item, english, word_class, tier, aqa_units,
  tracker_topic; CSV, or six fields one per line) into sets of 15 by topic,
  tier and word class, named "Identity 8: Adjectives" / "… (Higher)",
  numbered from `--start`, each word keeping its `tier` and `word_class`.

## Pages and routes

| Route | Who | What |
|---|---|---|
| `GET /s/<subject>/play` | anyone with the link | the app, and the steps under it |
| `POST /s/<subject>/play/run` | anyone with the link | one finished game as JSON → `{ ok, status, run_id, block, message, board }` or a 400 naming the fault |
| `POST /s/<subject>/play/mark` | parent (cookie + CSRF) | one step: `ref`, `status` (developing \| secure), `evidence` |

The subject page links to the app from its Practice heading, and shows the
heading even before a run exists when the subject has sets.

## Tests

`deploy/vocab-test.php` (CI and deploy): the seed and its marker, both
tools and their refusals, a game taken in and bound to the Wednesday block,
the duplicate no-op, the refusals, the clock guard, flashcards, stars across
three games, the per-topic proposals, the mark-off and its refusals, the
page for both readers, and the tool count. The smoke test renders the page
for a subject with no sets and checks the two write guards.
