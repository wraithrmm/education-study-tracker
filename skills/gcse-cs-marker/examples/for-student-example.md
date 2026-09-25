> **Illustrative example output** from this skill — an invented script in the real format, not a
> reproduction of a live AQA paper. Use as the exact format template; swap in the paper being
> marked. Check before sending: no row below gives away an answer.

# For Student — Your Results: GCSE Computer Science Paper 1 (Programming, Python)

| | |
|---|---|
| **Your mark** | **54 / 90** |
| **Percentage** | **60%** |
| **Grade** | **About a grade 6** — right on the line rather than safely over it |
| **Note** | Computer Science has no tiers, so every grade from 1 to 9 is available from this paper. This is Paper 1 only, so it says nothing yet about the Paper 2 topics. |

Real strengths here. Your trace table technique is good, your string handling was almost perfect,
you classified test data correctly for full marks, and you got the efficiency question at the very
end right — which tells me you weren't rushing at the finish. The answers aren't written below on
purpose, so you can go back and work them out yourself.

## Your marks question by question

| Q | Mark | Q | Mark | Q | Mark |
|---|---|---|---|---|---|
| 1 | 1/1 | 10a | 3/5 | 18 | 2/3 |
| 2 | 1/1 | 10b | 3/3 | 19 | 1/4 |
| 3 | 0/1 | 11 | **0/3** | 20 | **0/4** |
| 4 | 2/2 | 12 | 1/4 | 21 | 2/2 |
| 5 | 2/2 | 13a | 3/4 | 22 | 3/4 |
| 6 | 1/2 | 13b | 2/2 | 23 | 2/4 |
| 7a | 3/4 | 14 | 4/6 | 24 | 1/3 |
| 7b | 0/1 | 15 | 4/6 | 25 | 2/2 |
| 8 | 2/3 | 16 | 4/5 |  |  |
| 9 | 2/4 | 17 | 3/5 |  |  |

## Where you lost marks — what to review

| Q | Concept | Try this |
|---|---|---|
| 3 | Integer division vs remainder | Work out by hand what each of DIV and MOD gives you for 11 and 2, and write down which is which. |
| 6 | Boolean expressions | Brackets change what NOT applies to. Try your version and the bracketed version on a truth table and see where they differ. |
| 7a | Trace tables | Your method was right — one row's arithmetic was wrong. Re-run the trace slowly and check each `total` value against the one before it. |
| 7b | Purpose of an algorithm | Look at what your own completed trace produced and describe in one sentence what the algorithm is for. |
| 8 | Binary search | There is one thing a list *must* be before binary search can work at all. Find it. |
| 9 | Merge sort | Your splitting was fine. Practise the merging half on its own: take two already-sorted short lists and combine them step by step. |
| 10a | Validation routines | An `if` checks once. If the user should be asked again until the input is valid, what structure do you need instead? Also: what happens if they type nothing at all? |
| 11 | Subroutines — why use them | Blank. Aim for three separate reasons, each with a "which means…" after it. |
| 12 | Parameters and local variables | Two different things here. For the local variable: when does it come into existence, when does it disappear, and who can see it? |
| 13a | String handling | You did three of the four operations. Which one did the question ask for that you didn't use? |
| 14 | 2-D arrays | Check which variable your inner loop is counting through. Trace it on a 3×3 grid and write down which cells actually get visited. |
| 15 | Authentication | You compare correctly. What should happen when the comparison fails — and what should the user see? |
| 16 | Flowcharts | Every branch out of a decision needs its own label. Check yours. |
| 17 | Loops that build a total | Your loop and your condition are both right. Look at what happens to `total` between the start and the `print` — nothing. Fix that one line. |
| 18 | Types of error | Your definitions were fine. Recheck the line you named the second time and see whether anything is actually wrong with it. |
| 19 | Refining a program | "Add more tests" is too general to earn marks. Name one specific test, say what it would reveal, and say what you'd change as a result. |
| 20 | The structured approach | Blank. Describe how a big program gets broken into parts, how the parts talk to each other, and why that's better. |
| 22 | Nested loops | Your structure and indentation were correct — check the inner loop's end value carefully. |
| 23 | Records | You listed the fields. The definition needs one more thing beside each field name. |
| 24 | Abstraction and decomposition | Two different ideas — you defined one. Then apply both to the actual scenario in the question rather than defining them in general. |

## Focus your revision on these five

1. **Subroutines, parameters and local variables** (Q11, Q12, Q20) — 11 marks on this paper alone,
   the biggest single thing you could fix.
2. **Building a total inside a loop** (Q17, Q10a) — the "start it before, change it inside" habit.
3. **Nested loops and 2-D arrays** (Q14, Q22) — specifically the inner counter.
4. **Merge sort** (Q9) — just the merging half.
5. **Adding the required detail** (Q6, Q16, Q23) — three marks lost on things you already
   understood but didn't finish writing.

## The one habit worth changing

**Q11 and Q20 were blank. That's 7 marks, and it's the difference between a grade 6 on the line
and a comfortable one.** Both were "explain" questions — and Q12 proves you knew something about
that topic, because you wrote about parameters there. You weren't out of time either: you reached
Q25 and got it right.

So next time, on any question asking you to explain or describe: write one sentence, even a rough
one. Examiners give marks for correct points wherever they appear, and a blank is guaranteed
nothing. If you're stuck, write what you *do* know about the topic and move on — you can come back.
