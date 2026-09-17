# What each paper looks like

A summary, for choosing the shape of a question before opening the paper. The paper in the
knowledge base is the authority; where this file and a real paper disagree, the paper wins.

Paper files: `papers/<subject>/<code>-<series>-QP.pdf` (question paper) and `-MS.pdf` (mark
scheme). Specifications: `specs/<subject>-<code>.pdf`.

---

## Maths — AQA 8300, Higher (`8300/1H` non-calculator, `8300/2H` and `8300/3H` calculator)

Each paper 80 marks, 1 h 30 min. Roughly a mark a minute. Questions run easy to hard; the last
few pages are where blanks happen.

| Marks | What the question looks like | What the examiner wants |
|---|---|---|
| 1 | "Write down…", "Circle your answer", a single conversion | The answer alone. Working earns nothing. |
| 2 | "Work out…" with one step of method | The answer; working only rescues it if the answer is wrong (M1). |
| 3–4 | "Solve…", "Show that…", multi-step with "You must show your working" | Method marks per step, then accuracy. An answer with no working can score 1 of 4. |
| 5–6 | Problem solving, "Is she right? You must show how you decide" | A decision *and* the reasoning that reaches it. The decision alone scores nothing. |

House conventions to copy: "Work out", "Calculate", "Solve", "Show that", "Prove", "Give your
answer to 3 significant figures", "Give a reason for your answer", "You must show your working",
"Circle your answer". Units on the answer line where they belong. `oe` in the scheme for
equivalent forms; `ft` where a later part follows through from a wrong earlier answer.

Scheme codes: M (method), A (accuracy, usually dependent on an M), B (independent), SC (special
case), `dep`, `ft`, `oe`. See `gcse-maths-marker/references/marking-conventions.md`.

Avoid: anything needing a printed graph, grid, construction or diagram — the portal renders text
only. Coordinates, sequences, algebra, ratio, percentages, probability without a tree, standard
form, indices, surds, rearranging and worded problems all work in text.

---

## English Language — AQA 8700 (`8700/1` Explorations in Creative Reading and Writing, `8700/2` Writers' Viewpoints and Perspectives)

Paper 1: an unseen literary fiction extract, Q1 (4) list four things, Q2 (8) language, Q3 (8)
structure, Q4 (20) evaluation, Q5 (40) descriptive or narrative writing. Paper 2: two non-fiction
texts, Q1 (4) true/false, Q2 (8) summary of differences, Q3 (12) language, Q4 (16) comparison of
viewpoints, Q5 (40) transactional writing.

For the weekly paper, the reading questions work; the 40-mark writing task does not fit 45
minutes beside other subjects — set it only when the parent asks for a single-subject paper.
Supply the extract **inside** `question_md` and keep it short (150–250 words), in the register
of the paper it imitates, with the line references the question uses.

Marks map to levels, not points: Level 4 (top) down to Level 1, with a mark range each and
indicative content. The scheme text should read as the real ones do — "Level 3 (5–6 marks):
clear, relevant explanation of effects; judicious references; clear use of subject terminology."

Command words: "List four things", "How does the writer use language to…", "How has the writer
structured the text to interest you as a reader?", "To what extent do you agree?", "Summarise the
differences", "Compare how the writers convey their different attitudes". Each carries its AO —
name it in the scheme.

---

## English Literature — AQA 8702 (`8702/1` Shakespeare and the 19th-century novel, `8702/2` Modern texts and poetry)

Closed book; extract-based questions print the extract. Single essay questions per section,
30 marks (+4 AO4 SPaG on the Shakespeare and modern-text questions).

A full essay does not fit the weekly paper. Use instead: an extract with a focused question worth
8–12 marks written on the same descriptors ("Starting with this extract, explain how far
Shelley presents…" cut to a paragraph's worth); a quotation-and-effect question; an unseen poem
of 12–16 lines with a 12-mark question. Say in the question how long it is meant to take.

Print the extract or poem in `question_md`. Keep set-text extracts short and name the text and
the moment. Scheme in level descriptors with AO1/AO2/AO3 weighting, plus AO4 as its own band
where the real question carries it.

---

## Computer Science — AQA 8525 (`8525/1` Computational thinking and programming, `8525/2` Computing concepts), Python 3

Paper 1 is on-screen/written programming: trace tables, "complete the program", "write a program
that…", refinements to given code. Paper 2 is theory: data representation, networks, systems
architecture, SQL, Boolean logic, ethics, with 1–4 mark recall and explain questions and a 6–9
mark level-of-response extended answer.

| Marks | Shape |
|---|---|
| 1 | "State…", "Give one…" — a single fact or value |
| 2 | "Explain why…" — a point and its consequence; or two facts |
| 3–4 | "Describe the steps…", a small trace table, a two-line SQL query, convert with working |
| 6–9 | Level of response: "Discuss the impact of…" against a scenario |

Code in `question_md` goes in a fenced block and is Python 3 with the AQA conventions (the
pseudo-code paper style only where the real paper uses it). Trace tables are written as a Markdown
list of the columns and the starting values, and she types the rows — say so in the question.

Scheme as mark points with accept/reject lists ("Accept 'converts to machine code'; reject
'runs the program' alone"), and level descriptors on the extended answers.
