# Skills

The two skills the exam-practice feature needs. They are **not** loaded from this repository:
the household's skill pack lives in the Claude skills plugin, and these are kept here so the
feature and the skills written against it change together and can be reviewed in one diff.
Upload each folder to the plugin by hand (`docs/exam-skills-rollout.md` §4).

| Folder | Whose project | What it does |
|---|---|---|
| `exam-question-generator/` | Dad's | Writes exam-style questions from the past papers in the project's knowledge base, vets them, and schedules the week's timed paper. |
| `exam-practice-session/` | Paige's | Points her at the waiting paper without showing a question; afterwards marks it against the stored schemes and logs the sessions. |

Each folder holds a `SKILL.md` in the pack's usual format, a `references/` directory, and a
`PROJECT.md` — the custom instructions and the knowledge-base layout for the project it belongs
to, which are not part of the skill itself.

The service contract both are written to is `docs/exam-skills.md` §7. Amendments the feature
asks of the skills that live outside this repository — `gcse-progress-tracker`, `term-planner`,
`parent-weekly-review` — are listed there too, one line each.
