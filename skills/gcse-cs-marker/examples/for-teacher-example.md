> **Illustrative example output** from this skill — an invented script in the real format, not a
> reproduction of a live AQA paper. Use as the exact format template; swap in the paper being
> marked.

# For Teacher — Marked Script: AQA GCSE Computer Science 8525B/1 (June 2023, Python 3)

**Contains correct answers, model code and mark justifications — do not give this copy to the student.**

| Metric | Result |
|---|---|
| **Raw mark** | **54 / 90** |
| **Percentage** | **60%** |
| **Indicative grade** | **Grade 6** — exactly on the line, not clear of it |
| **Tier** | Untiered — grades 1–9 available |
| **Specification version** | Old spec (2020). Paper 1 assesses 3.1–3.2 only, which v1.3 did not change — **no questions voided**, full 90 marks marked. |
| **Blanks** | **2** (Q11, Q20 — 7 marks). Both are written-explanation questions. |

*Derivation:* scaled across two papers, 54 × 2 = 108/180. June 2024 boundaries (option 8525C, the
only verified row) put grade 6 at 108 and grade 7 at 128 → grade 6 by nothing at all. Boundaries
are borrowed from a different series and a different language option, so this is indicative.
Paper 1 only: nothing here samples 3.3–3.8, which is the other half of the qualification.

## Full marking record

| Q | Ref | Max | Student answer | Correct answer / band | Awarded | Justification |
|---|---|---|---|---|---|---|
| 1 | 3.2.1 | 1 | B | B | **1** | Correct. |
| 2 | 3.1.1 | 1 | C | C | **1** | Correct. |
| 3 | 3.2.3 | 1 | A | D | **0** | `11 MOD 2` is the remainder, not the quotient. |
| 4 | 3.2.1 | 2 | integer; string | integer; string | **2** | Both correct. |
| 5 | 3.2.3 | 2 | 5 and 1 | 5; 1 | **2** | DIV and MOD both correct. |
| 6 | 3.2.5 | 2 | `NOT A AND B` | `NOT (A AND B)` | **1** | Correct operators identified; bracketing changes the meaning, so the second mark is lost. |
| 7a | 3.1.1 | 4 | *(trace table — image)* | count: 0,1,2,3; total: 0,2,6,12; output 12 | **3** | Three columns traced correctly; final `total` row shows 10, an arithmetic slip. No follow-through in this subject — the row is simply wrong. |
| 7b | 3.1.1 | 1 | *(blank)* | Sums the even numbers below 8 | **0** | Not attempted. |
| 8 | 3.1.3 | 3 | "binary is faster; linear checks each one" | Binary halves the search space each pass; requires sorted data; linear works on unsorted | **2** | Two valid points. The sorted-data precondition — the discriminating point — is absent. |
| 9 | 3.1.4 | 4 | *(partial merge sort — image)* | Correct split and merge stages | **2** | Split stages correct (2 marks). Merge stages combine out of order; the final list is unsorted. |
| 10a | 3.2.11 | 5 | `if len(pwd) < 8: print("too short")` inside no loop | Loop until valid; length and empty checks; message | **3** | Mark A (length condition) and Mark C (message) awarded; Mark B (empty-string check) and Mark D (re-prompt loop) not — a single `if` cannot re-prompt. Missing colon after `else` ignored per scheme. |
| 10b | 3.2.11 | 3 | "normal 5, boundary 8, erroneous 'cat'" | One valid value per class with justification | **3** | All three classes correctly exemplified. Note: 8 is a boundary for a minimum of 8 — accepted. |
| 11 | 3.2.10 | 3 | *(blank)* | Reuse; easier testing; readability; independent maintenance | **0** | Not attempted. Written-explanation question. |
| 12 | 3.2.10 | 4 | "a parameter is what you put in the brackets" | Parameter passes data in; local variable exists only during execution and is only accessible inside | **1** | One mark for the parameter idea. Nothing offered on local variables, scope or lifetime. |
| 13a | 3.2.8 | 4 | `name[0:3]` and `len(name)` used correctly; `.upper()` omitted | Substring, length, concatenation, case conversion | **3** | Marks A–C awarded; Mark D not — no case conversion attempted. |
| 13b | 3.2.8 | 2 | `Jo Bloggs` | `Jo Bloggs` | **2** | Correct, including the space. |
| 14 | 3.2.6 | 6 | 2-D array declared; nested loop indexes rows only | Declare; nested loop over rows and columns; accumulate; output | **4** | Marks A, B and D awarded. Inner loop iterates the outer index, so only the first column is visited — Mark C lost, and the cap "max 4 if any errors in code" applies. |
| 15 | 3.2.11 | 6 | Username/password compared; no loop; no failure message | Input both; compare to stored; grant/deny; re-prompt | **4** | Comparison and grant/deny logic correct. No re-prompt, no failure message. |
| 16 | 3.1.1 | 5 | *(flowchart — image)* | Start/stop terminators; decision diamond with labelled Yes/No; correct loop-back | **4** *(provisional)* | Symbols and flow correct; the No branch is unlabelled. Read from a photograph — confirm the arrowheads on the physical script. |
| 17 | 3.2.2 | 5 | `for i in range(1,101):` / `if i % 2 == 0:` / `print(total)` | Loop, condition, accumulate, output | **3** | Marks A, B and D awarded; Mark C not — `total` is never updated inside the loop, so the output is always 0. |
| 18 | 3.2.11 | 3 | "syntax = spelling, logic = wrong answer"; identified line 4 | Syntax error breaks the rules of the language; logic error runs but gives the wrong result | **2** | Both types distinguished acceptably and one correctly identified; the second identification names a line with no error. |
| 19 | 3.2.11 | 4 | "add more tests" | Named refinement tied to a test outcome | **1** | Generic. One mark for recognising that testing drives refinement; nothing specific to this program. |
| 20 | 3.2.10 | 4 | *(blank)* | Modularisation, documented interfaces, parameters and return values, advantages | **0** | Not attempted. Written-explanation question. |
| 21 | 3.2.9 | 2 | `random.randint(1,6)` | Correct random call in range | **2** | Correct. |
| 22 | 3.2.2 | 4 | Nested loop written; inner loop bound wrong | Correct nesting and bounds | **3** | Nesting structure and indentation correct (3 marks); inner bound off by one. |
| 23 | 3.2.6 | 4 | Record fields listed; no types given | Record definition with named fields and types | **2** | Fields correct; data types omitted, which the scheme requires. |
| 24 | 3.1.1 | 3 | "abstraction is removing detail" | Abstraction removes unnecessary detail; decomposition breaks a problem into smaller parts; applied to the scenario | **1** | Definition of abstraction only. Decomposition absent and neither applied to the scenario. |
| 25 | 3.1.2 | 2 | "the second one is quicker" | Fewer comparisons, so less time | **2** | Correct; time efficiency is the only efficiency required. |

**Total: 54 / 90.**

## Gaps, by failure type

**Knowledge — the concept isn't there**

1. **Subroutines: parameters, return values, local variables and scope** (Q11, Q12, Q20 — 11 marks,
   the single largest cluster on this paper). Ref 3.2.10.
2. **Merge sort mechanics** (Q9). Split is understood; merge is not. Ref 3.1.4.
3. **Decomposition, and applying abstraction to a scenario** (Q24). Ref 3.1.1.
4. **Binary search preconditions** (Q8) — the sorted-data requirement. Ref 3.1.3.

**Precision — the concept is there but the code isn't**

5. **Accumulators inside loops** (Q17, and the same fault behind Q10a's missing loop) — the
   pattern "initialise before, update inside" is not automatic. Ref 3.2.2.
6. **Nested loop bounds and 2-D array indexing** (Q14, Q22) — the inner index is the recurring
   error. Ref 3.2.6.
7. **Validation completeness** (Q10a, Q15) — one check written where the question asked for a
   routine that re-prompts. Ref 3.2.11.
8. **Required detail omitted** (Q23 data types, Q6 bracketing, Q16 unlabelled branch) — each cost
   a mark that the underlying understanding had already earned.

**Blank — nothing attempted**

9. **Q11 and Q20, 7 marks, both written-explanation questions on the same topic.** This is the
   documented skipping pattern, not a separate knowledge gap: Q12 shows partial knowledge of the
   same material, so she had something to write and did not write it. 7 marks is the difference
   between this grade 6 and a comfortable one.

**Secure — do not reteach:** data types (Q4), DIV/MOD (Q5), string handling and concatenation
(Q13a/b), test data classification (Q10b), random numbers (Q21), flowchart construction (Q16),
efficiency reasoning (Q25), trace-table technique (Q7a, spoiled only by arithmetic).

**Pace flag:** the blanks are at Q11 and Q20, in the middle of the paper, not at the end. This is
not a timing problem — she reached Q25 and answered it correctly. It is question selection.

*Provisional marks (Q16 = 4 marks) are read from a scanned drawing; confirm on the physical
script. If it is wrong, the total drops accordingly and the grade falls below the line.*

**Logged to the tracker:** attempt #— (fill in the returned id/URL), question by question with
spec refs, so marks lost per topic are computed there. Amend the tracker too if the provisional
mark above changes.
