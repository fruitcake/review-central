#!/bin/sh
# Writes the review's instructions to the directory SKILL.md reads them from:
#
#   sh instructions.sh [review-context.json] [.review-instructions]
#
# The review's guidance and the stack guides are what the review looks for
# and how it judges it. The review server keeps the current version and sends it with the
# context, as `instructions.files`, each with its body; this writes those files and
# leaves the bodies out of the context, which the review reads whole and would
# otherwise read them in twice.
#
# A review server that sends none - one that predates them, one that has none
# yet, a set without the method, or no server
# at all, as on a developer's machine - leaves the review to the copy that ships
# with this skill in references/: the review's guidance, and the guide for each
# stack in settings.stack.
#
# Either way `instructions` in the context says what was written and where it came
# from, and SKILL.md reads the files in that order. Exit 1 when nothing could be
# written; the review then stops, since it has no method to review by.
set -u

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
CONTEXT="${1:-review-context.json}"
DIR="${2:-.review-instructions}"

command -v jq > /dev/null 2>&1 || { echo "jq is missing, so the review instructions cannot be written."; exit 1; }
[ -s "$CONTEXT" ] || { echo "There is no $CONTEXT to write the review instructions for."; exit 1; }

rm -rf "$DIR"
mkdir -p "$DIR" || { echo "Could not create $DIR."; exit 1; }

tmp=$(mktemp)
trap 'rm -f "$tmp"' EXIT INT TERM

# Only a plain file name, so a key can never write outside the directory.
SAFE='^[a-z0-9][a-z0-9._-]*\.md$'

if [ "$(jq -r --arg safe "$SAFE" '
     ((.instructions // {}).files // []) as $files
     | if ($files | length) > 0 and ($files | all((.file // "") | test($safe)) and all((.body | type) == "string") and any(.file == "method.md"))
       then "server" else "bundled" end' "$CONTEXT" 2>/dev/null)" = "server" ]; then
  for file in $(jq -r '.instructions.files[].file' "$CONTEXT"); do
    jq -r --arg file "$file" '.instructions.files[] | select(.file == $file) | .body' "$CONTEXT" > "$DIR/$file" \
      || { echo "Could not write $DIR/$file."; exit 1; }
  done

  # The context has to say what is on disk, or the review reads one thing and is
  # told another.
  jq -c '.instructions |= (.source = "server" | .files |= map(del(.body)))' "$CONTEXT" > "$tmp" && cat "$tmp" > "$CONTEXT" \
    || { echo "Could not record the review server's instructions in $CONTEXT."; exit 1; }

  echo "Review instructions: version $(jq -r '.instructions.version // "?"' "$CONTEXT") from the review server ($(jq -r '[.instructions.files[].file] | join(", ")' "$CONTEXT"))."
  exit 0
fi

# Said, not swallowed: the review server's version is what the run records, so a review that
# reads another one has to say so in the log.
case "$(jq -r '((.instructions // {}).files // []) as $f
               | if ($f | length) == 0 then "none"
                 elif ($f | all(has("body") | not)) then "stripped"
                 else "refused" end' "$CONTEXT" 2>/dev/null)" in
  stripped) echo "Warning: the review server's instructions ($(jq -r '.instructions.version // "?"' "$CONTEXT")) did not arrive with the context; reviewing with the copy that ships with this skill instead." ;;
  refused) echo "Warning: the review server sent instructions ($(jq -r '.instructions.version // "?"' "$CONTEXT")) without method.md or with a file name this skill does not read ($(jq -r '[.instructions.files[].file // "?"] | join(", ")' "$CONTEXT")); reviewing with the copy that ships with this skill instead." ;;
esac

# The copy that ships with the skill: the review's guidance, then a guide for each
# stack, as the review server orders them.
files=""
for name in method $(jq -r '(.settings.stack // [])[]' "$CONTEXT" 2>/dev/null); do
  case "$name" in
    method) source="$HERE/references/$name.md"; file="$name.md"; stack="" ;;
    *) printf '%s' "$name" | grep -qE '^[a-z0-9-]+$' || continue
       source="$HERE/references/stack-$name.md"; file="stack-$name.md"; stack="$name" ;;
  esac
  [ -f "$source" ] || continue
  cp "$source" "$DIR/$file"
  title=$(sed -n 's/^# //p' "$source" | head -n 1)
  files="$files$(jq -cn --arg file "$file" --arg title "$title" --arg stack "$stack" \
    '{key: ($file | sub("\\.md$"; "")), title: $title, stack: (if $stack == "" then null else $stack end), file: $file}')
"
done

if [ ! -f "$DIR/method.md" ]; then
  echo "The review skill has no references/method.md, so there is no method to review by."
  exit 1
fi

list=$(printf '%s' "$files" | jq -cs .) \
  && jq -c --argjson files "$list" '.instructions = {source: "bundled", version: null, files: $files}' "$CONTEXT" > "$tmp" \
  && cat "$tmp" > "$CONTEXT" \
  || { echo "Could not record the instructions in $CONTEXT."; exit 1; }

echo "Review instructions: the copy that ships with this skill ($(jq -r '[(.instructions.files // [])[].file] | join(", ")' "$CONTEXT"))."
exit 0
