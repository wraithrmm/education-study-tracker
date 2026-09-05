---
name: language-learning-platform
description: >
  Use this skill any time you are building, editing, or extending a
  language-learning app or platform — flashcards, vocabulary games,
  shooting-gallery/quiz-style drills, conversation practice, or any
  single-file React tool that teaches a foreign language. ALWAYS load this
  skill when the task touches a GCSE (or equivalent) language syllabus, such
  as adding a new vocabulary or grammar set, covering a new spec topic, or
  deciding what comes next for a student. Trigger even without the word
  "skill" — cues include a vocabulary set being added to an existing app, a
  pasted BBC Bitesize or exam-board page for a language, mentions of
  AQA, Edexcel, OCR, or Eduqas language specs, or a request tied to a
  student's progress through their syllabus (check the Education Tracker).
  Applies to Spanish, French, German, or any other target language, and to
  any app following the vocabularySets-style architecture, not just one
  specific file.
---

# Language Learning Platform

Operating manual for extending single-file, React-based language-learning apps
(flashcards + games over a `vocabularySets`-style data structure) in a way that
is **syllabus-accurate** and **progress-aware**, rather than ad hoc.

## Starting a brand-new app: use the bundled template

If the user doesn't already have an app and wants one started from scratch —
for a new language, a new student, or a fresh topic — don't build one from
memory. Copy `assets/app-template.jsx` and seed it, rather than regenerating
the whole file freehand.

The template is a trimmed, already-validated copy of the working pattern:
Welcome screen with searchable set selector, Flashcards, a Shooting Gallery
game with persistent score history (best score, recent-games table, trend
chart via `window.storage`), and an AI Chat mode that calls the Anthropic API.
It ships with two small placeholder sets (greetings, numbers 1–10) purely to
show the expected shape — replace them, don't build alongside them.

To seed it:
1. Copy `assets/app-template.jsx` to the new file the user wants.
2. Change the `LANGUAGE_NAME` constant near the top (e.g. `"French"`,
   `"German"`) — this drives all the on-screen copy (titles, instructions, the
   AI Chat's system prompts) automatically, so it only needs to be set once.
3. Replace the two placeholder sets (`1` and `2`) in `vocabularySets` with the
   user's real first sets, following steps 3–4 below for sourcing and style.
   Each word entry uses the generic shape `{ word, english, pronunciation }` —
   `word` holds the target-language term regardless of which language it is.
4. Validate (brace balance + Babel parse, per step 7 below) before delivering.

If the user already has an existing app (their own file, not the template),
work directly on that file instead — the template is only a scaffold for
starting fresh, never a replacement for a file that already exists and has
its own history/content.

## Core architecture pattern to expect

These apps are typically one `.jsx` file with:
- A `vocabularySets` object keyed by an incrementing integer, each entry having
  a `name` and a `words` array of `{ word, english, pronunciation }` (the field
  holding the target-language term may instead be named after the language
  itself, e.g. `spanish`, in older files — check the actual file rather than
  assuming).
- Generic game/UI logic (flashcards, a timed drill, a chat/quiz mode, a
  searchable set-selector) that iterates over `vocabularySets` without caring
  how many sets exist or what's in them.

Because the games are generic, **adding a set is a data-only change** — new
sets need no wiring elsewhere unless the user asks for a new game mode. Treat
this as the default assumption, but confirm by skimming the file before
editing (game logic occasionally has set-specific branches, e.g. category
detection in a flashcard component — check for `selectedSet === N` conditionals
before assuming pure genericism).

## Workflow for adding a new set

### 1. Identify what's being taught
Confirm: target language, exam board/spec if GCSE (AQA, Edexcel, OCR, Eduqas,
WJEC), and the specific topic (e.g. "the immediate future tense", "house and
home", "healthy living"). If the user hasn't said, ask — don't guess a spec
code.

### 2. Check the Education Tracker before proposing content
If the Education Tracker connector (`tracker_*` tools) is available and the
student has a tracked subject for this language:
- Call `tracker_list_subjects` / `tracker_get_state` to see the topic list and
  current RAG status for that language.
- Use this to figure out what's **already covered** (don't duplicate a set
  unless the user explicitly wants a review/consolidation set — see step 6) and
  what's **coming up next** in spec order.
- If the student's tracker shows a topic as `notstarted` or `developing` and
  the user is adding vocab for it, that's a strong signal this is the right
  next set to build.
- If no tracker subject exists yet for this language, mention that one could
  be set up (point to the `gcse-tracker-dashboard` skill) but don't block on
  it — proceed with the vocabulary work either way.

### 3. Source fidelity — don't invent exam-board wordlists from memory
Exam-board and revision-site pages (BBC Bitesize etc.) are often blocked to
direct fetching and are copyrighted — don't reproduce their text, and don't
rely on your own training-data memory to guess the *exact* word list a spec
page uses, since accuracy against the source matters a lot here.
- **Ask the user to paste the page content** if you don't already have it in
  the conversation. This is the reliable path.
- If asked to propose sets before content is pasted, clearly label your
  proposal as a draft based on general knowledge of the topic, and invite the
  user to correct/replace it with the real source — don't present a guess as
  verified curriculum content.
- Once given pasted content, build vocabulary directly from it rather than
  paraphrasing or "improving" the wordlist.

### 4. Set construction conventions
Match the existing file's style exactly:
- `name`: short, descriptive (e.g. `"Immediate Future (Ir + a)"`).
- Each word entry: the target-language term, an accurate English gloss, and a
  UK-friendly phonetic pronunciation guide (not IPA — approximate syllables,
  e.g. `'ab-LAR'`), matching the style already used in the file.
- Typical set size: roughly 6–20 items, matching the density of nearby sets.
  Consolidation sets (see step 6) are smaller (~8 items).
- Phrases (not single words) are fine and often the right choice for grammar
  points (e.g. `voy a`, `tengo que`) — keep them exactly as the spec presents
  them.

### 5. Numbering and placement
- **Append new sets at the end** of the existing numbering; never renumber or
  edit existing sets. Other systems (the Education Tracker, the user's own
  notes, prior conversation) may reference set numbers, so stable IDs matter.
- Keep edits surgical: touch only the `vocabularySets` object (or whichever
  data structure holds sets) unless the user has asked for a feature change.
  No incidental refactors of game logic, styling, or unrelated sets.

### 6. Consolidation / entrenchment sets
Periodically — especially after adding several new grammar-heavy sets in one
sitting — offer or build a review set that:
- Is compact (~8 items).
- Mixes in familiar vocabulary from recently-added sets with **no more than
  one genuinely new word or phrase per entry**, so each item reinforces old
  material while teaching a small amount extra.
- Favours high-frequency, everyday/idiomatic language (classroom phrases,
  time expressions, connectors) over more spec-topic vocabulary, since the
  point is entrenchment plus real-world usefulness.

### 7. Validate before delivering
Before presenting the edited file:
- Check brace/bracket/paren balance.
- Parse the file through Babel (JSX + ES2015+ presets) to catch syntax errors
  a naive read-through would miss.
- Skim the diff to confirm no unrelated code moved or changed.
- **Check every word of the current set can appear in the gallery.** The mixing
  function must keep the whole current set and *add* prior-set words around it,
  not take a slice of it — a slice silently drops the last 20–40% of the words
  the set exists to teach, and nothing in the UI reveals it. Assert it: build
  the mixed pool for a ten-word set at each interleaving percentage and confirm
  all ten current words are present every time.
- **Check any streak or ramp counter counts what its threshold means.** A
  threshold expressed in questions must not be compared against a counter that
  also increments on retries; the ramp then fires early and erratically, and
  again nothing in the UI reveals it.

### 8. Close the loop with the tracker (if applicable)
If the new set corresponds to a topic tracked in the Education Tracker for
this student and language:
- Consider logging the coverage (e.g. via `tracker_update_topic` /
  `tracker_log_session`, following the `gcse-progress-tracker` /
  `gcse-tracker-dashboard` skill conventions) so the tracker and the app's
  vocabulary don't drift apart — but confirm with the user first, since not
  every vocab set maps 1:1 onto a tracked topic.
- If unsure whether this language has a tracked subject, ask rather than
  assuming; don't create a new tracker subject unprompted.

### 9. Report practice

One run per game: `client_run_id` = `${setNumber}-${startedAt ISO}`, `source`
`spanish_gallery` | `spanish_flashcards` | `spanish_chat`, `label` = the set
name, attempted/correct/incorrect from the game, `duration_seconds`,
`metrics { top_speed, timeouts }`, and `items` for every word `{ prompt (exact
Spanish string), outcome, note (timeout | wrong | not-yet), topic_ref (theme
ref, plus a second item for the grammar ref where the set is grammar-led) }`.

`attempted` must equal `correct + correct_after_retry + incorrect` or the whole
call is refused; the `client_run_id` makes a repeat a silent no-op, so a retry
after a failed report is always safe.

Add a **"Copy session report"** button on the game-over screen that emits that
JSON and keeps it in `window.storage` under a pending-reports key until it has
been logged. The artifact sandbox can only reach `api.anthropic.com`, so the
app cannot call the tracker itself — copy and paste is the transport, and the
pending key is what stops a run being lost when the tab closes before it is
pasted. Keep `missed` and `timeouts` arrays in the app's history entry too, so
the record exists even when nothing is copied.

## Multi-language note

Everything above generalizes beyond Spanish. If the user is building or
extending a French, German, or other-language app, use the same
`vocabularySets`-style pattern and the same workflow — just swap the
language-specific field name (e.g. `french` instead of `spanish`) and adjust
pronunciation conventions to that language's phonetics. If the user maintains
separate app files per language, keep each one internally consistent rather
than trying to unify them into one file unless asked.

## Quick checklist

- [ ] Language, exam board/spec, and topic confirmed
- [ ] Education Tracker checked for this subject (if connected) — know what's
      done vs. next
- [ ] Real source content obtained (pasted) rather than guessed from memory
- [ ] New set(s) match existing style (name, fields, pronunciation format,
      typical size)
- [ ] Appended at the end of existing numbering, nothing else touched
- [ ] File validated (brace balance + Babel parse) before delivery
- [ ] Every current-set word reachable in the gallery at every interleaving
      percentage, asserted not assumed
- [ ] Any ramp or streak counter compared against a threshold in the same units
- [ ] Consolidation set offered if several heavy sets were just added
- [ ] Tracker updated if appropriate and confirmed with the user
