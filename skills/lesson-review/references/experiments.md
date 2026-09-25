# Teaching experiments — writing `next_test`

The prompt asks that the next lesson test the analysis: where a pattern is suspected, the next
lesson is arranged so that the pattern can be confirmed or refuted. In the tracker this is the
`next_test` field on a signal. This file says what a test may vary, what it may not, and how it
is written so that the following review can read it back as an answer.

## The one rule

**A test varies the teaching within the study principles. It never varies the principles.**
`gcse-progress-tracker/references/study-principles.md` is the fixed frame: warm before hard, one
instruction per message, 20–25-minute chunks with a visible timer, externalise everything, never
blank, retrieval and spacing by default, interleave maths, type to draft and handwrite to train,
Spanish loses a tie, warm brief tone. The prompt's own example — teaching the second concept
"using the previous longer explanation format" — is a breach of principle 4 and would not be
written here. Nor is a test ever a deliberately created failure; the prompt says so and so does
the never-blank rule.

## What a test may vary

- **Order** — model → practise vs practise-first-then-model on a structurally identical item;
  retrieval at the very start vs after the movement break.
- **Grain** — one modelled step then practice, vs two steps then practice (within one chunk).
- **Scaffold withdrawal rate** — organiser fully filled vs headings only, over successive
  sessions, on question types she avoids.
- **Item format** — multiple-choice vs open response for the same retrieval item; typed vs
  handwritten for a short answer.
- **Task quantity** — 4 vs 6 practice items before the exit ticket, holding time constant.
- **Feedback timing** — immediate after each item vs after a set of three, on fluency work
  (never on new content).
- **Modality of the first explanation** — diagram first vs worked example first, for one
  concept, with the other held for the next.
- **Placement of the hard item** — first after the break vs last before it.
- **Interleaving ratio** — 1 earlier topic per 3 new-topic items vs 1 per 2.

## What a test may not vary

- Chunk length beyond 15–25 minutes; break presence; timer visibility.
- The retrieval warm-up before new content in the first 30–45 minutes.
- One instruction per message; the plan-in-her-words-first step; the tick-box checklist.
- The never-blank script and the praise-the-attempt rule.
- Subject blocking (no cross-subject switching inside a block).
- Anything that would score the learner against herself ("see if she notices we skipped the
  organiser").

## How to write one

A `next_test` is 10–300 characters and has three parts, in order:

1. **The manipulation** — what the next lesson does differently, concretely.
2. **The comparison** — against what (last lesson, the other concept, the other half of the
   items).
3. **The read-out** — the number or observation the next review will report.

Examples that pass:

- "Model one step then one practice item, repeat for each of the three steps; compare exit-ticket
  accuracy with 2026-09-08 (2/4 after three steps modelled together)."
- "Run the five retrieval items multiple-choice first, then the same five open-response next
  session; compare unaided-correct counts."
- "Organiser with headings only on the two worded problems; record whether she fills 'what's
  asked' unprompted and the blank count."

Examples that fail:

- "Try a different approach and see if it helps." (no manipulation, no read-out)
- "Explain for longer before practice to test whether she needs more input." (breaches §4)
- "Give her the exit ticket cold to see whether she copes." (created failure)

## Tests the synthesis or the parent set

A `next_test` with `test_set_by: synthesis` or `parent` arrives in the queue's `this_week`
block with its design (`how`, `collect`, `supports`, `challenges`). It is run **as written**: a
session may not narrow it, swap the comparison, or drop an item from `collect`. If the lesson
could not run it — wrong topic, block cut short — the review says so in `missing_evidence` and
leaves the test open; it does not answer with a partial run. A review may set or replace a test
only on a signal whose current test it (or an earlier review) set.

## Closing a test

The next review answers the test in its `signals[]`: the same `key`, `direction: supports` or
`contradicts`, and evidence that reports the read-out. That is what lets the strength rule move
the signal. A test the following review does not answer stays open; after three sessions of that
subject the audit flags it, and the weekly review puts it to the parent as a decision — run it,
rewrite it, or drop the signal.
