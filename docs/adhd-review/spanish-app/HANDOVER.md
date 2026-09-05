# Spanish app: nine fixes, ready to hand to Claude

The working app lives in a Claude artifact, which this session cannot reach. So this file is written to be **handed over**: paste the prompt in section 1 into the chat that has the app, and it has everything needed to make the same changes to the live file.

`app-template.jsx` in this folder is the same fixes already applied to the skill's template, if you would rather diff against a finished copy. It parses clean under Babel (JSX + preset-env) with balanced braces, and `verify-fixes.js` demonstrates the two logic fixes against the old behaviour (`node verify-fixes.js`).

Nothing here changes what she plays. The gallery is still the gallery, the ramp is still there, the chart is untouched.

---

## 1. The prompt to paste

> I need nine fixes to my Spanish vocabulary app. Please make them one at a time and show me the diff for each before moving on. Do not change the Shooting Gallery's look, the score chart, or the `window.storage` history key — she uses those every day and the tracker has golden snapshots pinned to the chart's geometry.
>
> 1. **Words that can never be practised.** `getMixedVocabulary` slices the current set to `Math.floor(current.length * currentPercent)` and pads the rest from earlier sets. With a second set in play that silently drops the last 20% of every set from the Shooting Gallery and the last 40% from AI Chat. Fix it so every current word is always in the pool and earlier words are added *on top*: `const extra = Math.max(0, Math.ceil(current.length * (1 / currentPercent - 1)))`, start `mixed` as a full copy of `current`, then push `extra` random previous words.
> 2. **The speed ramp fires one answer late.** In `nextWord`, `score` is the pre-increment value because `handleAnswer` calls `setScore(prev => prev + 1)` and then `setTimeout(() => nextWord(true), 800)`. So "faster every 3 points" actually speeds up on the 4th, 7th, 10th. Change `nextWord` to take the new score as an argument and test that instead.
> 3. **Misses are double-counted under React 18.** The movement `useEffect` calls `setFails`, `setConsecutiveFails` and `nextWord` *inside* the `setWordPosition` updater. Updaters can run twice, which counts one escaped word as two misses. Move the escape handling into its own effect that watches `wordPosition`, and leave the updater doing nothing but `prev - 1`.
> 4. **"Too slow" and "didn't know" are the same number.** Both increment `fails`. Add separate `timeouts` and `wrongPicks` counters, and a `missedWords` array recording `{ word, english, pronunciation, reason: 'timeout' | 'wrong', speed }` for each miss.
> 5. **The game-over screen never shows what she missed.** Add a "These got away" section listing the missed words with their reason, and a **Practise these — no timer** button that restarts the game with only those words and the clock switched off (add `untimed` and `reviewWords` state; skip the movement effect and the ramp when `untimed`; three-in-a-row does not end an untimed round; give it a **Done** button instead). An untimed review round must not set a record or join the score chart.
> 6. **Steady and Turbo.** On the start screen offer two buttons: Turbo (the current ramp) and Steady (holds at 6x, floor `speed = 4`). Both always available, nothing unlocked or withheld. Record which was used.
> 7. **AI Chat is pitched at the wrong student and gives the answer away.** Add `const STUDENT = { age: 13, level: 'GCSE (AQA 8692) Spanish' }` near `LANGUAGE_NAME` and use it in both system prompts instead of "12-year-old Key Stage 3 student" (and in the welcome-screen footer). Replace the five prompt types with the four AQA speaking shapes: `role_play`, `photo_card`, `four_questions`, `conversation`. Change the evaluation JSON from `{correct, feedback, suggestion}` to `{conveyed: 0|1|2, time_frames: [], major_errors, minor_errors, feedback, hint}` — `conveyed` mirrors the role-play mark scheme, and `hint` must name the structure to fix ("the verb ending for -er verbs in the past") and never write out the corrected sentence. Show it as "Message got across / Partly across / Not across yet" rather than a red cross.
> 8. **An API failure is scored as a correct answer.** The `catch` in `handleSubmit` pushes `correct: true` and increments the score, so an outage inflates her results. Make it score nothing, say plainly that the answer was not marked, and leave the score alone. Same for `checkAnswer`'s catch: return `{ failed: true, ... }` rather than a verdict.
> 9. **Nothing reaches the tracker.** Add a `buildRunReport` helper and a **Copy session report** button on the gallery game-over screen, the flashcards summary, and the chat header, emitting exactly this shape so it can be pasted to Claude and logged with `tracker_log_practice`:
>
> ```json
> { "subject": "spanish",
>   "runs": [{ "client_run_id": "spanish_gallery-set1-2026-09-05T15:11:00.000Z",
>     "source": "spanish_gallery", "label": "Shooting Gallery — Sports",
>     "played_at": "2026-09-05T15:11:00.000Z",
>     "attempted": 17, "correct": 13, "correct_after_retry": 0, "incorrect": 4,
>     "metrics": { "top_speed": 8.2, "timeouts": 3, "wrong_picks": 1 },
>     "items": [{ "prompt": "juega al", "outcome": "incorrect", "note": "timeout at 8.2x" }] }] }
> ```
>
> `attempted` must equal `correct + correct_after_retry + incorrect` or the tracker refuses the whole call. Sources are `spanish_gallery`, `spanish_flashcards`, `spanish_chat`.
>
> Also rebuild **Flashcards** so a round produces real evidence: shuffle the deck, put **Got it / Not yet** on the back of each card, bring the "not yet" cards back for another pass, and finish with counts of right-first-time / right-after-a-retry / not-got. At the moment a card counts as "completed" the instant it is flipped, whatever she knew, so a flashcards run can only ever be logged as all-correct.
>
> When you are done, please confirm: every word of a 10-word set can appear in the gallery once a second set exists; the ramp fires on the 3rd correct answer; an escaped word adds exactly 1 to the miss count; and an API failure changes no score.

---

## 2. The nine fixes in detail

Each one gives the current code, why it matters here, and the replacement.

### Fix 1 — the vocabulary pool drops the end of every set

**Where:** `getMixedVocabulary`, near the top of the component.

**Now:**
```js
const currentCount = Math.floor(current.length * currentPercent);
const previousCount = current.length - currentCount;
const mixed = [...current.slice(0, currentCount)];
for (let i = 0; i < previousCount; i++) {
  const randomPrevious = previous[Math.floor(Math.random() * previous.length)];
  mixed.push(randomPrevious);
}
```

**Fixed:**
```js
// n current words are currentPercent of (n + extra) → extra = n * (1/pct - 1)
const extra = Math.max(0, Math.ceil(current.length * (1 / currentPercent - 1)));
const mixed = [...current];
for (let i = 0; i < extra; i++) {
  mixed.push(previous[Math.floor(Math.random() * previous.length)]);
}
```

**Why it matters.** The comment says "80% current set + 20% previous", which is a proportion; the code implemented it as a truncation. `Math.floor(10 * 0.8) = 8`, so words 9 and 10 of a ten-word set are only ever seen in flashcards. In the chat (`0.6`) it is words 7 to 10. It does not bite today because she is on set 1 and the function returns early when there are no previous sets — it bites the moment set 2 is added, which is the next thing anyone will do. A word that is never shown is also never missed, so a review queue built on the item history would record it as known.

Verified: `node verify-fixes.js` shows the old code dropping `w9,w10` at 0.8 and `w7,w8,w9,w10` at 0.6, and the new code dropping none while holding the intended share (77–80%).

### Fix 2 — the speed ramp is off by one

**Where:** `nextWord`, and its two call sites in `handleAnswer`.

**Now:** `if (score > 0 && score % 3 === 0) { setSpeed(...) }` — but `score` here is captured before `setScore(prev => prev + 1)` has been applied, so it is always one behind.

**Fixed:** give `nextWord` the new score.
```js
const nextWord = (wasCorrect, newScore) => {
  ...
  if (newScore > 0 && newScore % 3 === 0) { setSpeed(prev => Math.max(floor, prev - 0.8)); }
};

// in handleAnswer:
const newScore = score + 1;
setScore(newScore);
setTimeout(() => nextWord(true, newScore), 800);
```

**Why it matters.** The start screen promises "faster every 3 points" and it actually accelerates after 4, 7, 10. Small, but the top-speed number is used as evidence about her, and the ramp is the thing that decides whether a miss means "didn't know" or "couldn't read it in time".

Verified: old fires after answers 4 and 7; fixed fires after 3, 6 and 9.

### Fix 3 — an escaped word can count as two misses

**Where:** the movement `useEffect`.

**Now:** the escape branch lives inside the `setWordPosition` updater, which also calls `setFails`, `setConsecutiveFails` and `nextWord`.

**Fixed:** the interval only moves the word; a second effect handles the escape.
```js
useEffect(() => {
  if (!gameStarted || gameOver || feedback || untimed) return;
  const interval = setInterval(() => setWordPosition(prev => prev - 1), speed * 10);
  return () => clearInterval(interval);
}, [gameStarted, gameOver, feedback, speed, untimed]);

useEffect(() => {
  if (!gameStarted || gameOver || feedback || untimed) return;
  if (wordPosition >= -10) return;
  recordMiss(currentWord, 'timeout');
  setTimeouts(t => t + 1);
  setFails(f => f + 1);
  const newConsecutive = consecutiveFails + 1;
  setConsecutiveFails(newConsecutive);
  if (newConsecutive >= 3) { setGameOver(true); setWordPosition(100); }
  else { nextWord(false, score); }
}, [wordPosition, gameStarted, gameOver, feedback, untimed]);
```

**Why it matters.** React may invoke a state updater twice (it does so deliberately in development StrictMode). Side effects inside one are not safe: one escaped word becomes two misses and two advanced words. Her accuracy figure is evidence in the tracker, so a phantom miss is a phantom weakness.

### Fix 4 — split "too slow" from "didn't know"

Add `timeouts`, `wrongPicks` and `missedWords` state; increment the right one in `handleAnswer` (wrong pick) and the escape effect (timeout); record each miss as `{ word, english, pronunciation, reason, speed }`.

**Why it matters.** Her four logged games have 43 items she "didn't get", and nobody can tell how many of those she knew perfectly well but lost to the clock. That distinction decides whether the answer is more vocabulary or less speed. The accelerating drill makes it worse: adolescents with ADHD are measurably worse at re-balancing speed against accuracy, so an accelerating game pushes toward fast wrong answers rather than a fair test of what she knows.

### Fix 5 — show her the words that got away, and let her have them back without the clock

Add the "These got away" list to the game-over screen and a **Practise these — no timer** button that calls `startGame({ reviewWords, untimed: true })`. In untimed mode: skip the movement effect and the ramp, do not end on three in a row, show a **Done** button, and do not write the round to the score history.

**Why it matters.** The moment she most wants to fix something is the moment the old screen showed her a score and nothing to act on. This turns the fail into the next step — the app's version of "what's one thing you could write down?".

### Fix 6 — Steady and Turbo

Two buttons on the start screen. Turbo is the existing ramp with floor `speed = -2` (12x); Steady holds `speed = 6` (4x display) with floor `4` (6x). Nothing is withheld and either can set a record.

### Fix 7 — the chat is written for the wrong student and hands over answers

Add near `LANGUAGE_NAME`:
```js
const STUDENT = { age: 13, level: `GCSE (AQA 8692) ${LANGUAGE_NAME}` };
```
Use it in both system prompts and in the welcome-screen footer (`Key Stage 3 {LANGUAGE_NAME} • Vocabulary Practice` → `{STUDENT.level} • Vocabulary Practice`).

Replace the five prompt types with `role_play`, `photo_card`, `four_questions`, `conversation`, each with a one-line brief, and change the evaluation contract:

```json
{ "conveyed": 0, "time_frames": ["present"], "major_errors": 0, "minor_errors": 0,
  "feedback": "one or two sentences, second person, naming what she actually did",
  "hint": "name the structure to fix, 12 words max" }
```

The old prompt asked for `"suggestion": "if incorrect, show the correct way"` — that is the corrected sentence, handed over. The largest field experiment on AI help in school maths found unguarded answer-giving raised practice scores while leaving students markedly worse once the help was removed; a hint-only tutor largely removed that harm. Her maths sessions already follow the hint-only rule, and the chat should not be the hole in it.

Also ban the superlatives in the prompt ("amazing", "perfect", "incredible", "brilliant") and require praise to name what she did, not what she is.

### Fix 8 — an outage must not score points

**Now**, in `handleSubmit`'s catch:
```js
setMessages(prev => [...prev, { type: 'feedback', text: "Great try! Keep practicing! 🎉", correct: true }]);
setScore(prev => ({ ...prev, correct: prev.correct + 1 }));
```

**Fixed:** say it was not marked, change no score. `checkAnswer`'s catch returns `{ failed: true, feedback: "...", hint: "" }` and `handleSubmit` only scores when `conveyed` is a number and `failed` is not set.

**Why it matters.** Any run logged from that screen could contain points for answers nobody ever read, and the tracker treats those figures as evidence.

### Fix 9 — the app must hand the session to the tracker

Add `copyText` and `buildRunReport` helpers at component level, and a **Copy session report** button on all three activities. The payload shape is in section 1; sources are `spanish_gallery`, `spanish_flashcards`, `spanish_chat`.

**Why it matters.** The tracker's design says the app "supplies items only for the words missed", but nothing in the app ever produced them: the numbers were retyped from memory. That is how 4 September was recorded twice — the session summary says 40 of 50, the only logged run says 13 of 17, and 27 correct answers are unaccounted for. `client_run_id` makes re-pasting safe: the same id twice is a silent no-op.

### Plus: flashcards produce evidence

Shuffle, **Got it / Not yet** on the back of the card, "not yet" cards return for another pass, and the round ends with right-first-time / right-after-a-retry / not-got. Today a card is "completed" when it is flipped, so a flashcards run can only ever be all-correct and the tracker learns nothing from it — which is why the 4 September session could say only "10 flashcards reviewed".

---

## 3. What to check once it is done

| Check | Expected |
|---|---|
| Add a second set, play the gallery, watch for the last two words of the current set | They appear |
| Count correct answers to the first speed-up | 3, not 4 |
| Let one word escape | Misses goes up by exactly 1 |
| Let one word escape, then look at the end screen | It is listed as "too slow at Nx" |
| Pick a wrong answer | Listed as "wrong pick", counted separately from timeouts |
| Press "Practise these — no timer" | Same words, no movement, Done button, no new record |
| Start in Steady, get 6 right | Speed stays at 6x |
| Turn wifi off, answer in the chat | "isn't marked", score unchanged |
| Ask the chat something you get partly right | "Partly across", a hint naming a structure, no corrected sentence |
| Flip a flashcard | Got it / Not yet, and the card comes back if you say Not yet |
| Press Copy session report | Valid JSON, `attempted == correct + correct_after_retry + incorrect` |

---

## 4. Two things deliberately not changed

- **The score chart and the `shooting-gallery-history` storage key.** She reads that chart, the tracker's Spanish board is pinned to reproduce it exactly, and a golden snapshot in `tests/golden` fails the build on a two-pixel drift. New fields (`timeouts`, `wrongPicks`, `missed`, `mode`) are added to history entries, which is additive; nothing existing is renamed.
- **The speed ramp itself.** The evidence says time pressure is where an accelerating drill starts measuring impulsivity rather than vocabulary, but it is also what makes it fun, and engagement is the whole objective. So the ramp stays, Steady sits beside it, and the data now says which mode a result came from.
