#!/bin/bash
#
# Package the skills.
#
#   bash deploy/build-skills.sh              # validate, then build into dist/
#   bash deploy/build-skills.sh --check      # validate only, build nothing
#   OUT=/tmp/x bash deploy/build-skills.sh   # build somewhere else
#
# Produces two things from one source of truth, `skills/`:
#
#   dist/skills/<name>.zip   one zip per skill, each with the skill folder at
#                            the archive root — the shape claude.ai wants when
#                            you upload a single skill by hand.
#   dist/gcse-tracker.zip    the whole plugin: the manifest and every skill,
#                            for anyone who would rather install the lot than
#                            add the marketplace.
#
# The zips are reproducible: entries are sorted and every timestamp is pinned,
# so an unchanged skill produces a byte-identical zip and a release diff shows
# only what really moved.
set -uo pipefail

cd "$(dirname "$0")/.."

OUT="${OUT:-dist}"
CHECK_ONLY=0
[ "${1:-}" = "--check" ] && CHECK_ONLY=1

FAILURES=0
pass() { printf '  ok    %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; FAILURES=$((FAILURES + 1)); }

# Pin every entry's mtime so a rebuild of unchanged input is byte-identical.
# Prefer the commit date, so a checkout of a tag always rebuilds the same zip;
# fall back to a fixed date outside a git tree.
SOURCE_DATE="$(git log -1 --format=%cd --date=format:'%Y-%m-%d %H:%M:%S' 2>/dev/null || true)"
[ -n "$SOURCE_DATE" ] || SOURCE_DATE='2026-01-01 00:00:00'

echo "== validating =="

# The manifests and every skill, warnings treated as errors. This is the same
# check CI runs, so a green local build means a green CI build.
for target in .claude-plugin/plugin.json .claude-plugin/marketplace.json skills; do
    if out="$(claude plugin validate "$target" --strict 2>&1)"; then
        pass "$target"
    else
        fail "$target"
        printf '%s\n' "$out" | sed 's/^/        /'
    fi
done

# Things `claude plugin validate` does not check, and that break an upload to
# claude.ai rather than a plugin install:
#
#   - the directory name must equal the frontmatter `name`, because the zip is
#     named from the directory and claude.ai matches the two;
#   - only six frontmatter keys are accepted on upload, and any other key is a
#     hard error there while being perfectly legal in a plugin.
ALLOWED_KEYS="name description license compatibility allowed-tools metadata"

skill_name() {
    awk '/^---$/{n++; next} n==1 && /^name:[[:space:]]/{sub(/^name:[[:space:]]*/, ""); print; exit}' "$1"
}

SKILLS=()
for dir in skills/*/; do
    name="${dir%/}"
    name="${name##*/}"
    [ -f "$dir/SKILL.md" ] || continue
    SKILLS+=("$name")

    declared="$(skill_name "$dir/SKILL.md")"
    if [ "$declared" != "$name" ]; then
        fail "$name — frontmatter name is '$declared'; it must equal the directory name"
    fi

    # Keys of the first frontmatter block only.
    bad=""
    while read -r key; do
        [ -n "$key" ] || continue
        case " $ALLOWED_KEYS " in
            *" $key "*) ;;
            *) bad="$bad $key" ;;
        esac
    done < <(awk '/^---$/{n++; next} n==1 && /^[a-zA-Z_-]+:/{sub(/:.*/, ""); print}' "$dir/SKILL.md")
    if [ -n "$bad" ]; then
        fail "$name — frontmatter keys claude.ai will reject:$bad"
    fi
done

if [ "${#SKILLS[@]}" -eq 0 ]; then
    fail "no skills found under skills/"
fi

if [ "$FAILURES" -ne 0 ]; then
    echo
    echo "VALIDATION FAILED: $FAILURES problem(s). Nothing was built."
    exit 1
fi
pass "${#SKILLS[@]} skills, names and frontmatter"

if [ "$CHECK_ONLY" = 1 ]; then
    echo
    echo "CHECK PASSED — ${#SKILLS[@]} skills."
    exit 0
fi

echo
echo "== building into $OUT =="

rm -rf "$OUT"
mkdir -p "$OUT/skills"

# One zip per skill, folder at the archive root. Built from inside skills/ so
# the stored paths start at the skill's own directory and nothing above it
# leaks into the archive.
for name in "${SKILLS[@]}"; do
    find "skills/$name" -exec touch -d "$SOURCE_DATE" {} +
    ( cd skills && zip -rqX "../$OUT/skills/$name.zip" "$name" -x '.*' '*/.*' )
    size="$(wc -c < "$OUT/skills/$name.zip" | tr -d ' ')"
    printf '  %-32s %8s bytes\n' "$name.zip" "$size"
done

# The whole plugin: the manifest plus every skill, rooted at the plugin name so
# it unpacks into one directory rather than over the current one.
PLUGIN_NAME="$(sed -n 's/.*"name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' .claude-plugin/plugin.json | head -1)"
STAGE="$OUT/.stage/$PLUGIN_NAME"
mkdir -p "$STAGE"
cp -r .claude-plugin "$STAGE/"
cp -r skills "$STAGE/"
[ -f skills/README.md ] && cp skills/README.md "$STAGE/README.md"
find "$STAGE" -exec touch -d "$SOURCE_DATE" {} +
( cd "$OUT/.stage" && zip -rqX "../$PLUGIN_NAME.zip" "$PLUGIN_NAME" -x '.*' '*/.*' )
rm -rf "$OUT/.stage"
printf '  %-32s %8s bytes\n' "$PLUGIN_NAME.zip" "$(wc -c < "$OUT/$PLUGIN_NAME.zip" | tr -d ' ')"

# A manifest of what was built, so a release body can be generated from it and
# a human can see at a glance what a tag contains.
{
    echo "# ${PLUGIN_NAME} — built artifacts"
    echo
    echo "| Skill | Zip | Bytes |"
    echo "|---|---|---|"
    for name in "${SKILLS[@]}"; do
        echo "| \`$name\` | \`skills/$name.zip\` | $(wc -c < "$OUT/skills/$name.zip" | tr -d ' ') |"
    done
    echo
    echo "Whole plugin: \`${PLUGIN_NAME}.zip\`."
} > "$OUT/CONTENTS.md"

echo
echo "BUILD OK — ${#SKILLS[@]} skill zips and one plugin zip in $OUT/"
