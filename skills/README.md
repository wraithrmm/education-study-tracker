# Skills

The skills that teach, mark and record the week. They live here, beside the service they call,
so a change to a tool and the change to the skill that uses it land in one commit and one review.

This directory is the source of truth. What runs in Claude is built from it.

## Installing

**As a plugin — the whole pack, one command.** In Claude Code:

```
/plugin marketplace add wraithrmm/education-study-tracker
/plugin install gcse-tracker@rmmann-education
```

The repository is both the marketplace (`.claude-plugin/marketplace.json`) and the plugin
(`.claude-plugin/plugin.json`), so no subdirectory has to be named. Updating is
`/plugin marketplace update rmmann-education`.

**As single skills — one at a time, by hand.** Every CI run attaches a `skills-<sha>` artifact
holding one zip per skill, and every `skills-v*` tag publishes the same zips on a GitHub
Release. Download the one you want and add it in claude.ai under Settings → Capabilities →
Skills. Each zip has the skill folder at its root, which is the shape the uploader expects.

## Building

```bash
bash deploy/build-skills.sh            # validate, then build into dist/
bash deploy/build-skills.sh --check    # validate only
```

The build validates before it writes anything, and writes nothing if a check fails:

- `deploy/validate-manifests.php` — both manifests parse, carry the fields the plugin loader
  reads, agree about the plugin's name, and name only sources that are really in the tree; every
  skill has a frontmatter block with a name and a description long enough to route on. It is
  plain PHP, so it runs anywhere the service does, CI included;
- each skill's frontmatter `name` must equal its directory name, because the zip is named from
  the directory and claude.ai matches the two;
- frontmatter may use only the six keys claude.ai accepts on upload — `name`, `description`,
  `license`, `compatibility`, `allowed-tools`, `metadata`. **The plugin validator does not catch
  this**: a seventh key installs perfectly well as a plugin and is a hard error on upload, which
  is exactly the failure that would otherwise be found by a person at the worst moment.

Where the `claude` CLI is on the machine, `claude plugin validate --strict` runs over both
manifests and every skill as a further layer, because it knows about plugin fields this
repository does not use. It is not on a CI runner and the build never depends on it: if it is
missing the build says so and carries on, and nothing above is skipped.

Zips are reproducible. Entries are sorted and timestamps pinned to the last commit date, so an
unchanged skill rebuilds byte-identically and a release diff shows only what really moved.

`dist/` is git-ignored: the zips are build output, not source.

## Releasing

Push a tag:

```bash
git tag skills-v1.0.0 && git push origin skills-v1.0.0
```

That builds the zips and publishes them on a Release with install instructions. The tag prefix
keeps skill releases apart from anything the service might tag later.

## What is here

| Skill | Whose | What it does |
|---|---|---|
| `maths-tutor-session` | student | Runs a maths session from the tracker's queue and timetable |
| `english-tutor-session` | student | The same for English Language and Literature |
| `cs-tutor-session` | student | The same for computer science, Python 3 |
| `spanish-maintenance-session` | student | The short Spanish slot, logged as practice |
| `retrieval-block-session` | student | Any retrieval block: item selection, interleaving, the blanks script |
| `exam-practice-session` | student | The weekly timed paper: sitting it, then marking it |
| `gcse-maths-marker` | parent | Marks an AQA 8300 paper against its scheme |
| `gcse-english-marker` | parent | Marks 8700 and 8702 against level descriptors |
| `gcse-cs-marker` | parent | Marks 8525, both components |
| `gcse-spanish-marker` | parent | Marks 8692, all four components |
| `exam-question-generator` | parent | Writes, vets and schedules the weekly paper's questions |
| `gcse-progress-tracker` | either | Topic state, promotion bars, grade projection; holds the shared references |
| `gcse-tracker-dashboard` | parent | Creates and extends subjects, configures the dashboard |
| `lesson-review` | either | The written half of one session; the nightly audit |
| `weekly-synthesis` | parent | The Saturday reading of how she learns, written back as plans |
| `parent-weekly-review` | parent | The Friday adherence review |
| `term-planner` | parent | Phases, checkpoints, entry deadlines, timetable changes |
| `language-learning-platform` | parent | Building and extending the vocabulary app |

The shared references every skill cites — the study principles, the timetable contract, the block
kinds, the common grading facts — live in `gcse-progress-tracker/references/` and are not
duplicated anywhere.

Two skills also carry a `PROJECT.md`: the custom instructions and knowledge-base layout for the
Claude project that skill belongs to. It is documentation for setting the project up, not part
of the skill, and Claude never loads it.

## The service contract

`docs/exam-skills.md` §7 is what the two exam skills are written to, and is the place to change
first if the exam tools change. The tracker's own tool descriptions carry their `USE WHEN` lines,
so a skill should cite a tool rather than restate when to call it.
