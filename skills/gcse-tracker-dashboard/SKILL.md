---
name: gcse-tracker-dashboard
description: Add and manage GCSE subjects in the hosted Education Tracker service, and change how its dashboard looks and behaves. Use this skill whenever the user asks to create a tracker or dashboard for a GCSE subject (e.g. "build one for English Language", "make a science tracker"), to seed or extend a subject's syllabus and resources, to change what the dashboard shows, or asks where a subject's progress can be viewed. Supersedes the old React-artifact trackers.
---

# GCSE Tracker Dashboard — the hosted service

Every subject's dashboard is served by the **Education Tracker** at
<https://education.rmmann.co.uk>, rendered from a database on every request.
Adding a subject is a tool call, not a build.

> **This replaces the React-artifact trackers.** Do not build a new
> `window.storage` dashboard artifact — the old ones were per-account, invisible
> to chat Claude, and each was a separate copy of the truth. `references/dashboard-template.jsx`
> is kept only as the visual ancestor of the current design; it is not a template
> to instantiate. If the user asks for "a tracker artifact", explain that the
> service does this now and give them the URL.

## Where things are

| Page | Shows |
|---|---|
| `/` | **The week's timetable** (today marked; each block done / short / missed / excused / extra; hours by subject), then every subject with a coverage percentage · `?week=YYYY-Www` shows another week, next included, on the same board |
| `/week/{iso-week}` | A past week judged against the timetable in force then |
| `/s/{slug}` | The dashboard: exam countdown, coverage, per-strand bars, topic chips, loose ends, resources, attempts, sessions by week |
| `/s/{slug}/a/{id}` | One sitting: papers, every question with marks, the answer given, the note, then marks lost per topic |
| `/s/{slug}/session/{id}` | One session: what happened, and every status it changed with its evidence |
| `/s/{slug}/t/{ref}` | One topic: its whole status history, the session behind each change, every question it has been examined by, and its materials |

The dashboard is public to anyone with the link. Writing needs the MCP connector
("Education Tracker", tools named `tracker_*`).

Full tool reference: the `gcse-progress-tracker` skill's `references/tracker-service.md`.
Shared grading facts: its `references/exam-grading-common.md`.

**If you see `tracker_log_assessment` or `tracker_list_assessments`, the connector's tool list is stale.** Those were replaced by `tracker_log_attempt` / `tracker_list_attempts` / `tracker_get_attempt`. Ask the user to remove and re-add the Education Tracker connector in Settings → Connectors to pick up the current tool list (it now includes the practice, scoreboard and timetable tools). Don't fall back to the old assessment tools: they no longer exist on the server, so the call will fail.

## Adding a subject

`tracker_create_subject` — one call. Re-running it later **adds new topics without
touching statuses already earned**, so it is also how you extend a syllabus.

```
tracker_create_subject(
  slug: "combined-science",
  name: "GCSE Combined Science",
  spec_code: "AQA 8464", tier: "Higher",
  exam_date: "2027-05-14",
  strands: { B: "Biology", C: "Chemistry", P: "Physics" },
  boundary_max: 240,
  boundaries: { F: [[5,186],[4,157]], H: [[9,…],[8,…]] },
  topics: [ { ref: "B1", name: "Cells", strand: "B", tier: "F/H" }, … ]
)
```

### Workflow

1. **Gather** (ask only for what's missing): subject, board and spec code; tier
   structure (tiered like Maths/Science/MFL, untiered like English/History — if
   untiered, omit `tier` and the per-topic tier); exam series and first-paper date;
   number of papers and their max marks.
2. **Research and verify.** Fetch the official spec's topic list and organise it
   into strands exactly as the spec does. Find recent published grade boundaries
   for that subject and board — note the total they are expressed against, which
   varies by subject (240 for maths, 160 for English Language). Verify links before
   citing them.
3. **Seed at spec-reference granularity** — aim for 40–70 topics with chip-sized
   names (≤ ~6 words). If a diagnostic exists, seed statuses from it; otherwise
   everything starts `notstarted` and you say so.
4. **Attach the materials** with `tracker_add_resource` — Bitesize pages, videos,
   worksheets, past papers, the spec PDF itself. Per topic where they're
   topic-specific, subject-wide (omit `ref`) where they aren't. The review queue
   hands these back alongside the topics needing work, so a session can be planned
   from one call. **Verify every URL before storing it.**
5. **Write the subject appendix.** Create
   `gcse-progress-tracker/references/subjects/{slug}.md` in the same pass, following the pattern in
   `maths.md`: target grade, tier intention, exam series, qualification total and any component
   scaling, which marker skill applies, which promotion bar the subject's questions call for
   (points or bands), the behavioural metric worth tracking, and the checkpoint dates. Record
   unknowns as explicit **TO CONFIRM** rows rather than guessing them. Without this file, the
   tracker reaches its first checkpoint with no plan and no target.
6. **Deliver** the link `/s/{slug}` plus a line on what was seeded and which
   boundary series the grades use.

### Marking and tiering vary by subject; statuses do not

Before seeding, establish how the subject is actually assessed, because it changes what the topic
refs should be and how the tracker will judge promotion:

- **Points-marked** (maths, science, MFL comprehension) — refs are content topics; the 70/80%
  bars apply.
- **Band- or level-marked** (English, History essays, MFL writing and speaking) — refs are often
  better as assessment objectives or skills than as content topics, and `gcse-progress-tracker`
  applies its descriptor-based bar instead. Say which in the appendix.
- **Component scaling** — some subjects scale raw component marks before boundaries apply. Record
  `boundary_max` as the *scaled* total and note the factors in the appendix.
- **Untiered subjects** — omit `tier` and the per-topic tier entirely.
- **NEA or coursework components** — note them, and note that a private candidate may need a
  centre to host them. That constraint belongs in the appendix, not in someone's memory.

### Statuses (identical across every subject, never re-themed)

`gap` 🔴 · `notstarted` 🟠 · `developing` 🟡 · `secure` 🟢 · `examready` 🔵, scoring
0/0/1/2/3 toward "spec conquered %".

### Truthfulness rules

Grade conversion only from real published boundaries with the series named. If
boundaries aren't verified, leave `boundaries` unset — the dashboard then shows the
raw score rather than inventing a grade. Checks (`kind: "check"`) are never
grade-converted at all.

## The timetable on the index page

The index page's first section is the weekly timetable, rendered from `timetable_blocks` and
judged live from sessions, attempts, practice runs, ticks and excusals (`gcse-progress-tracker/
references/timetable.md` is the contract; read it before touching this code). Three layouts ship
behind one switch — `meta.timetable_design` = `a` (week strip), `b` (today-first with a visible
time bar and a week dot-row beneath), `c` (register grid) — and `?design=a|b|c` previews any of
them. Change the default with a one-line `store.php` meta write, not by deleting the others, until
the parent has chosen. Rules that must survive any redesign:

- Today is unmistakable; the current block is the most prominent thing on the page.
- A missed block is visibly missed (red-pencil cross) on the week view, from across the room.
- No streak counters. Progress is shown as blocks done this week; a missed day never resets anything.
- Status colours stay semantic and shared: done = emerald, short = amber, missed = red-pencil,
  excused = stone strike-through, upcoming = hollow. Subject accent hues come off the subject row.
- Approved days off render as a labelled ribbon, never as a row of misses; a requested one
  renders as an outlined "requested — awaiting Dad" ribbon with its blocks still judged.
- There is no UI for booking or approving days off; that happens through the MCP tools.

## Changing what the dashboard shows

The dashboard is code in `wraithrmm/education-study-tracker`:

| File | Holds |
|---|---|
| `php/lib/dashboard.php` | All markup and CSS for every page, including the detail pages |
| `php/lib/mcp.php` | The tool definitions and handlers |
| `php/lib/store.php` | Schema, queries, grade conversion, the migration ladder |
| `php/index.php` | Routing |
| `deploy/smoke-test.sh` | The whole test suite |

Workflow: change it, run `bash deploy/smoke-test.sh` (boots the app against a
throwaway database and exercises every page and tool), open a PR to `main`. Merging
deploys automatically and re-verifies against production. There is no branch-deploy
path — production changes only through a merge.

Add a check to the smoke test for whatever you changed; a page that renders is not
the same as a page that is right.

**Schema changes** go in the numbered migration ladder in `store.php`
(`meta.schema_version`), each step in its own `BEGIN IMMEDIATE` transaction. The
production database holds the only copy of the record: prove any migration against
a copy in production's shape, and check it is idempotent and safe under concurrent
requests, before shipping.

## Design system

The look is the "exercise book": pale paper `#fcfcf9`, a faint grid, Georgia serif
display, monospace numerals, stone ink, white cards, a red-pencil countdown badge.
It is shared by every subject — one service, one stylesheet — so subjects are
siblings by construction rather than by discipline.

If per-subject character is wanted later, vary the **grid motif** and the **accent
hue** of the countdown and progress fill, driven off the subject row. Status colours
are semantic and must stay identical everywhere: red / stone / amber / emerald / sky.

## Migrating from an old artifact tracker

1. Read the artifact's seed arrays for topics and assessments — the artifact's
   *stored* data cannot be read by chat Claude, so ask the user to read the live
   values off the page if they diverge from the seed.
2. `tracker_create_subject` with the topics and their current statuses.
3. `tracker_log_attempt` for each recorded paper, one call per sitting.
4. Give them the new URL and tell them the artifact is now superseded, so entries
   made there won't reach the service.
