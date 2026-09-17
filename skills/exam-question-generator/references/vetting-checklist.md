# Vetting checklist

Every draft goes through this before `tracker_exam_update_question(action: "vet")`. Each line is
a way a question has actually gone wrong. A draft that fails any line is edited or retired, not
vetted with a caveat.

## The question

1. **Answerable from the specification** at the tier she is entered for (maths Higher; the others
   untiered), on a topic the tracker holds at `developing` or better — unless the parent asked
   for a stretch question, and then say so in `source_note`.
2. **Unambiguous.** One reading. Read it as someone who wants to answer a different question:
   can they? Pronouns with one referent; every quantity defined; every unit stated.
3. **No diagram needed.** The portal renders text only. Either the figure is fully described in
   words, or the question is not written. No "the graph below", no grids, no constructions, no
   pie charts.
4. **Self-contained.** Everything needed is in `question_md` — extracts, poems, code, data. She
   has no book, no calculator unless `calculator: true`, no internet.
5. **In the paper's voice.** The command word, the phrasing and the instructions are that
   paper's. Read it beside the real question in `source_note` and ask whether it would look odd
   printed next to it.
6. **Length.** Under 4000 characters including any extract. An extract of 150–250 words is
   plenty.

## The marks

7. **The mark scheme's points add up to `marks`.** Count them. A 3-mark question with two mark
   points is the commonest fault and it makes the marking dishonest.
8. **The tariff matches the work.** One mark = one answer, no working credited. Two or more = the
   method is creditable and the question should say so where the real paper would.
9. **Every creditable route is named.** Not just the scheme's own method: `oe`, the follow-through
   where a later part depends on an earlier one, the special cases worth naming. On a
   level-of-response question, the descriptors *and* indicative content.
10. **A blank scores zero and nothing else depends on it.** No question's marks depend on another
    question's answer unless it is explicitly a follow-through and the scheme says so.
11. **The model answer scores full marks against the scheme you wrote.** Check it line by line.

## The metadata

12. **`topic_refs` come from `tracker_get_state`**, first ref = where the marks are attributed.
13. **`calculator`** set honestly; the maths section of the weekly paper is non-calculator by
    default and the instructions say so.
14. **`time_guide_seconds`** — maths about 60 s a mark; English by the real paper's advice
    (roughly 12 min for an 8-mark language question); computer science 60–90 s a mark, more for
    a trace table.
15. **`paper_style`** is a real paper code, and **`source_note`** names the question it is
    modelled on.
16. **`client_key`** is stable and descriptive: `<subject>-<ref>-<yyyymmdd>-<n>`.

## Across the bank

17. **Not a near-duplicate** of a vetted or already-sat question on the same ref. The same ref at
    a different tariff, weeks apart, is deliberate; the same question twice is not.
18. **No two questions in one paper on the same ref.**
