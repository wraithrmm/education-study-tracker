# The amended skills

Complete replacement files for the five skills that drive the system, carrying the ADHD
amendments from [the review](../README.md). Each file here is the current skill with the changes
already applied — copy the file over the one in the synced skills directory, or paste it into the
skill's editor. Nothing here is a fragment or a diff; the fragment form, with the current text it
replaces quoted beside it, is in [skill-rewrites.md](../skill-rewrites.md).

The skills live outside this repository, so none of this is live until you install it.

| File | Replaces | Proposals applied |
|---|---|---|
| `maths-tutor-session/SKILL.md` | the whole file | P1–P9, P14 (the self-check), P12 (practice logging), P26 (tier wording) |
| `gcse-progress-tracker/SKILL.md` | the whole file | P18, and the practice/correction rules |
| `gcse-progress-tracker/references/subjects/maths.md` | the whole file | P10, P13, P26, P28 |
| `gcse-progress-tracker/references/subjects/spanish.md` | the whole file | P27, P28, P29 |
| `gcse-maths-marker/SKILL.md` | the whole file | P24, P25 |
| `gcse-maths-marker/examples/for-student-example.md` | the whole file | P24 |
| `gcse-spanish-marker/SKILL.md` | the whole file | P24 + the Spanish addendum |
| `gcse-spanish-marker/examples/for-student-example.md` | **new file** — the skill referenced one that never existed | P24 |
| `language-learning-platform/SKILL.md` | the whole file | P35, and the two app bugs as validation steps |
| `maths-scoreboard.json` | a `tracker_set_scoreboard` argument, not a file | P12 |

`gcse-tracker-dashboard` is unchanged, and so are the marker skills' teacher examples, their
handover brief and every `references/` file except the two subject appendices.

The two student examples matter more than they look: the maths skill says "follow the templates in
`examples/` exactly", so the old 755-word example — a full marks grid, a seventeen-row loss table,
five revision headings and an instruction to "practise your timing/pacing" — would have overridden
the new rules on every run. Both new examples are worked sheets from real paper codes, 300 and 273
words, and each was checked against the twelve rules rather than written and hoped for.

## What actually changes, in one line each

**`maths-tutor-session`** — the session gets a shape she can see: times said out loud, a mandatory
break at 20 minutes rather than an offered one, one ask per message, a two-minute open that names
last time's win and asks for an energy number, and a three-minute close that happens even when the
clock beat us. Working is required on anything worth 2+ marks and asked for as one named line, not
as "show your working". "Skip it" gets a four-rung ladder instead of a nudge. Praise names the
action and the mark it earned, never a trait. The starter's five questions are filled by a spacing
order, one slot of which exists solely to sample the 3–8 week window that nothing else was
sampling. And a new Shape E — 30–45 minutes, no new teaching — is the everyday shape, so that
"can't face a full session" has an answer that isn't "nothing".

**`gcse-progress-tracker`** — a single failed question is a *lapse*, recorded with the status kept
and the next retrieval pulled forward, not a demotion. Demotion needs a second fail within six
weeks, two wrong in one check, or a blank, and drops one level only. App practice at 80% is a
reason to schedule a check, never a status on its own. And a correction must pass `last_touched`
back explicitly, or the service stamps today and quietly pushes the next retrieval eight weeks out.

**`maths.md`** — Foundation becomes the working assumption with two named triggers for Higher and a
decision date, rather than Higher with Foundation "open". The target is a mark, not a grade. It
gains a parent-maintained session window, a weekly budget, a topic budget with the arithmetic shown,
a three-way reading of the December mock written *before* it is sat, and the centre and
access-arrangement timeline with its real deadlines.

**`spanish.md`** — the three TO CONFIRM rows are decided, with a go/no-go date and the condition
that moves the subject to June 2028. It gains the first twenty topics in sequence, the rule that a
three-option gallery score cannot promote a grammar topic on its own, a 25-minute session shape,
and the same centre and access-arrangement section.

**Both markers** — the student file is rewritten from "a marks grid and a revision list" into one
page of at most 300 words that opens with what worked, counts the blanks in their own section
before the wrong answers, gives at most three next actions each starting with a verb, and closes
with the next session. Twelve rules govern how it is written, including the one that matters most:
read it back as her, and if the honest answer is "she closes it", cut until it isn't. The maths
marker also gains sitting conditions as a fourth required input, because blanks read differently
when you know whether the clock ran out — and because that note is the dated record an
access-arrangement application rests on.

**`language-learning-platform`** — two validation steps that would have caught the two bugs in the
Spanish app (every current-set word must be reachable in the gallery at every interleaving
percentage; a ramp threshold in questions must not be compared against a counter that also counts
retries), a corrected reference to a skill that does not exist, and the practice-reporting contract
the app needs so its runs reach the tracker.

**`maths-scoreboard.json`** — the maths board, which is currently the default, becomes a board about
the habits the review says are the actual constraint: blanks, working not shown, and time practised.
It needs no deploy — it is one `tracker_set_scoreboard(subject: "maths", config: …)` call.

## Verifying the scoreboard config before you paste it

It validates against the service's own validator with zero errors, and every panel renders against
real practice rows:

```
php -r 'define("TRACKER",true); function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,"UTF-8");}
  require_once "php/lib/store.php"; require_once "php/lib/practice.php";
  $c = json_decode(file_get_contents("docs/adhd-review/skills/maths-scoreboard.json"), true);
  $e = practice_validate_scoreboard($c, ["maths_session"]);
  echo $e ? implode("\n", $e)."\n" : "VALID\n";'
```

Read the current board with `tracker_get_scoreboard(subject: "maths")` before replacing it. One
invalid panel rejects the whole configuration and leaves the stored one untouched, so a bad edit
cannot half-apply.

## Two things to decide before installing

1. **The maths tier.** `maths.md` now says Foundation is the working assumption. That is a real
   change of plan, not a wording tidy — section 4 of the review sets out the arithmetic behind it.
   If you disagree, change the two rows and the December readings before installing, not after.
2. **Whether she sees a grade.** The marker rules say the grade is one plain line at the end, or
   absent, and that you are asked once. Decide which, and the skill will hold to it.
