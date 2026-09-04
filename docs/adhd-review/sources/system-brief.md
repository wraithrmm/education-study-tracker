# System brief for research and review agents

Today is 2026-09-04. Everything below is fact taken from the repository, the skill files, and the live tracker. Do not invent anything about the student beyond what is here.

## The objective (from the parent)

"The single and only objective of this system is to help my daughter engage and get through her GCSEs. She has ADHD." The parent wants evidence-based recommendations and missing features, not guesses.

## The student (facts only)

- 13 years old, home-educated, studying largely autonomously with Claude acting as tutor through a set of skills. A parent oversees and sometimes runs or reviews sessions. Sitting GCSEs about two years earlier than the usual age of 16.
- Has ADHD. Nothing else is known: no detail on diagnosis, medication, existing accommodations, or comorbidities. Nothing in any skill or in the service mentions ADHD at all.
- Weekly study hours: not recorded anywhere. The maths tutor skill's "full session" is a ~90 minute block.
- Two subjects are tracked:
  - **GCSE Mathematics, AQA 8300**, Higher tier intended (Foundation entry open until a Feb 2027 decision; entries close ~21 Feb 2027). Exam 2027-05-14 (Paper 1, provisional). Target: secure grade 5, stretch 6. 64 topics; 23% "spec conquered" (16 secure, 12 developing, 24 not started, 12 gap). Last full paper: AQA June 2022 Foundation, 148/240, reported as "below grade 4" against stored June-2024 boundaries; Paper 3F lost 27 of 39 marks to blanks. Phase 1 exit check 19 Aug 2026: 22/25, zero blanks.
  - **GCSE Spanish, AQA 8692**, Higher tier "TO CONFIRM", exam 2027-06-09. 100 topics (12 themes, 65 grammar, 16 skills, 7 phonics); 1% covered (2 developing, 98 not started). Practice: 4 Shooting Gallery games (13, 18, 18 Aug and 4 Sep) at 71-81% accuracy. Speaking is non-exam assessment conducted by a centre; no centre is confirmed. Target grade, tier and checkpoints all "TO CONFIRM".

## Behaviour patterns visible in the session logs (maths, Aug-Sep 2026)

- Submits answers without working even when working was explicitly asked for (twice on 2 Sep).
- "Answer-targeting habit": answers every part when only one was asked for (e.g. gives all three colours when only blues were asked).
- Reports arithmetic in prose rather than writing numbers into the boxes of a frequency tree; a fill-in widget masked this.
- Two practice questions (Q4, Q5) "were not submitted before the session ended".
- Two full sessions were run on the same day (2 Sep).
- Historic "skipping habit": says "skip it" or "I can't"; the skill's scripted reply is "what's one thing you could write down?". Blanks fell from 27/39 marks lost on paper 3F (Jul 2026) to zero on the 19 Aug exit check.
- Sign errors and collecting-terms slips recur on the same sub-skill (A4) across three sessions.
- She likes seeing her Spanish score chart; the README says it is "the reason she keeps playing".

## The system

A self-hosted PHP + SQLite service at https://education.rmmann.co.uk (repo: wraithrmm/education-study-tracker). It exposes:
- A parent/teacher-facing dashboard per subject (countdown badge "N days to the exam", coverage %, strand bars, topic chips, loose ends, resources, attempts, sessions by week), plus detail pages per attempt, session and topic, and a practice scoreboard.
- 21 MCP tools (tracker_*) that Claude calls: list_subjects, get_state, review_queue, list_resources, list_attempts, get_attempt, history, export_markdown, update_topic, log_session, amend_session, log_attempt, add_resource, remove_resource, create_subject, log_practice, list_practice, practice_stats, void_practice, get_scoreboard, set_scoreboard.
- Topic statuses: gap, notstarted, developing, secure, examready. Promotion rules: developing ≈70% supported; secure ≈80% unaided; examready = passes a spaced re-test ≥3 weeks after securing; secure topic untouched 8+ weeks is flagged "ageing"; failure in a starter demotes to developing. Never promote two levels in one session, never on one question.
- Practice runs record attempted / correct / correct_after_retry / incorrect, optional per-item outcomes and topic refs, optional duration_seconds, source-specific metrics (e.g. Spanish top_speed). Practice never changes status.
- Deliberate design stance in the README: "no leaderboards, streaks, badges or nudges, no per-keystroke telemetry and no cross-subject comparison... Nothing on the board counts consecutive days or withholds anything for missing one. The chart exists because she likes seeing progress, not to manufacture obligation."
- Nothing records: session length, time of day, breaks, energy/mood, hours per week, how a session ended (finished vs ran out), reading-the-question errors, working-shown compliance, or any cross-topic behavioural pattern. Those appear only as free text in session summaries and per-topic "watch" notes.

## The skills (Claude-side instructions; on disk under the path given to you)

- **maths-tutor-session** — opens with tracker_review_queue; session shapes A (90-min: 10 min retrieval starter, 30-40 teach, 30 practise, 10 exit ticket, then log), B (quick help), C (weekly check 45 min), D (mock day). Teaching rules: never give the answer first, one hint at a time, "what's one thing you could write down?", every question labelled calculator/non-calculator, exam working conventions, foundation before higher, warm brief tone, if frustrated pause and suggest a break. Mandatory tracker_log_session at the end. Fallback Update Block if the connector is down.
- **gcse-progress-tracker** — the adjudicator: status rules above, evidence discipline, corrections, mock marking hand-off, progress reports (counts by strand, trajectory, latest attempt grade, behavioural metric "blanks per paper", one focus), checkpoint duties from a per-subject appendix. Maths appendix: Dec 2026 Foundation full mock expected ≥ grade 5; Jan 2027 pre-mock and Feb 2027 Higher mock decide tier; entries close ~21 Feb 2027. Spanish appendix: tier/series/target "TO CONFIRM"; component scaling; speaking is NEA needing a centre; behavioural metric = translation elements attempted and time-frame range in writing.
- **gcse-tracker-dashboard** — how to add a subject (research spec, seed 40-70 topics, attach verified resources, write the appendix) and how to change the service (edit PHP, run smoke test, PR to main deploys).
- **gcse-maths-marker** / **gcse-spanish-marker** — mark a paper against the official scheme, produce a For-Teacher file (with answers) and a For-Student file (no answers, method hints only, "where you lost marks" table, prioritised revision list), compute grade from real boundaries, log the attempt question by question.
- **language-learning-platform** — how to extend the single-file React Spanish app (vocabularySets, Flashcards, Shooting Gallery timed drill that speeds up every 3 correct answers, AI Chat); check the tracker before adding a set; source vocab from pasted spec pages; consolidation sets; validate with Babel.

## Live review queue for maths (2026-09-04, verbatim structure)

Ageing (8+ weeks): N1-N3, N16, A1-A3, R1-R3. Loose ends on 14 topics (N4, N10, N15, A4, A5, A8, A9-A10, A17, A22, R7-R8, G14-G16, P1, P2-P3). Priority gaps (Foundation first): G1, G3, P4-P5, P6-P7, P8, S2, S4, then A11, A23-A25, G17-G18, G20, G22-G23. Planned next: resume practice Q4/Q5, then P4-P5; fold A4 retest, harder HCF/LCM, one paper-drawn frequency tree into the starter.

## Maths topic state (2026-09-04)

Secure (16): N1-N3, N11-N12, N13, N14, N16, A1-A3, A5, A8, A9-A10, A17, A21, R1-R3, R4-R6, R7-R8, R9, R11. Developing (12): N4, N6-N7, N10, N15, A4, A22, R10, G4-G6, G9-G12, G14-G16, P1, P2-P3. Gap (12): A11, A23-A25, G1, G3, G17-G18, G20, G22-G23, P4-P5, P6-P7, P8, S2, S4. Not started (24): N5, N8, N9, A6, A7, A12-A14, A15-A16, A18, A19, A20, R12-R15, R16, G2, G7-G8, G10H, G13, G19, G21, G24-G25, P9, S1, S3, S5, S6.

Sessions logged: 19 Aug (exit check), 2 Sep ×2 (P1 frequency trees; P2-P3 expected/relative frequency). Practice runs logged: exit check 25 items, two starters (5 items each), frequency-tree practice (3), exit ticket (4), P2-P3 practice (3).

## Constraints agents must respect

- Web access: only the WebSearch tool works. WebFetch and curl are blocked by egress policy for research hosts. Cite URLs that WebSearch returned; say when a claim could not be confirmed beyond a search summary.
- Do not use the student's name; you do not know it and must not guess it.
- Recommendations must be tied to evidence with a source, and must say how strong the evidence is (meta-analysis, RCT, guideline, review, expert consensus, or none).
- The parent's design stance against streaks/badges is a deliberate decision; evaluate it against evidence rather than assuming either way.
