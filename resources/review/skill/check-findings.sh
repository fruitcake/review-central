#!/bin/sh
# Checks that every new finding points at code that is there, before anything is
# published. write-report.sh runs it on the report a reviewer returned.
#
#   sh check-findings.sh [ai-review.json]
#
# A reviewer writes `path`, `line` and `anchor` (the exact source line the finding
# rests on) from memory of what it read, and the review server comments on `path:line` as
# given. When they disagree with the checkout, the comment lands on the wrong line
# or nowhere. So, for each `new` finding with a path, read from the working tree,
# which is the commit under review in the pipeline and the reviewed snapshot in a
# local run:
#
# - the anchor is on another line of that file than the finding says: the finding
#   moves by the difference, `line` and `end_line` together. Where the anchor sits
#   is `line` plus its place among the lines of `existing_code`, since `line` is
#   the first of those and the anchor can be any of them. Without `existing_code`
#   to say, an anchor anywhere from `line` to `end_line` is where it should be;
# - the file is not there, or the anchor is in no line of it, or a finding without
#   an anchor names a line past the end of the file: the finding cannot be placed,
#   so it stops blocking. Its confidence goes to 7, below the gate, which the report step
#   shows as "Unconfirmed", and its `evidence` says why.
#
# Except where the change removed what the finding points at: the file, or the
# anchor's line, is there at the merge base. A finding about a deleted guard can
# rest on the line that is gone, and that one keeps blocking, left as it was written.
#
# Nothing is dropped: a real defect with a wrong line is still worth reading. A
# finding without a path is about the pull request as a whole and is left alone, and
# so is every finding the review carried forward (open, fixed and the rest), whose
# place the review server already tracks.
#
# Whitespace at either end of a line does not count, so a re-indented anchor still
# matches. Always exits 0: a report this cannot read is the worker's report step's to refuse.
set -u

REPORT="${1:-${REVIEW_REPORT_FILE:-ai-review.json}}"
[ -s "$REPORT" ] || exit 0
command -v jq > /dev/null 2>&1 || { echo "No jq; the findings' places are not checked."; exit 0; }
jq -e '(.findings // []) | type == "array"' "$REPORT" > /dev/null 2>&1 || exit 0

# One line per new finding with a path: index, id, path, line, end_line, the
# anchor's place among the lines of `existing_code` (empty when it is not one of
# them), and the anchor: the first non-blank line of the `anchor`, or else of
# `existing_code`. A reviewer sometimes quotes two lines as the anchor; joined,
# they match no line of the file. Separated by the
# unit separator, not a tab: `read` folds runs of a whitespace separator into one,
# and a finding without a line would shift the anchor into its place.
ROWS=$(jq -r '
    (.findings // []) | to_entries[]
    | select(((.value.status // "new") | ascii_downcase) == "new" and ((.value.path // "") != ""))
    | [ .key,
        (.value.id // "?"),
        .value.path,
        (.value.line // "" | tostring),
        (.value.end_line // "" | tostring),
        ((.value.existing_code // "") | split("\n")) as $code
        | (((.value.anchor // "") | split("\n") | map(select(test("\\S"))) | first) // ($code | map(select(test("\\S"))) | first) // "") as $anchor
        | def trim: sub("^[ \t\r]+"; "") | sub("[ \t\r]+$"; "");
          ([$code | to_entries[] | select((.value | trim) == ($anchor | trim) and ($anchor | trim) != "") | .key] | first // "" | tostring),
          ($anchor | gsub("[\r\n\u001f]"; " ")) ]
    | join("\u001f")
  ' "$REPORT" 2> /dev/null) || exit 0
[ -n "$ROWS" ] || exit 0

# The merge base, to tell a line the change removed from one that was never there.
# None when the destination is unknown, and then nothing counts as removed.
BASE=""
if [ -n "${BITBUCKET_PR_DESTINATION_BRANCH:-}" ]; then
  BASE=$(git merge-base "origin/$BITBUCKET_PR_DESTINATION_BRANCH" "${BITBUCKET_COMMIT:-HEAD}" 2> /dev/null) || BASE=""
fi

# Whether the file at the base has a line whose trimmed text is the trimmed anchor.
in_base() {
  [ -n "$BASE" ] || return 1
  git show "$BASE:$1" 2> /dev/null | ANCHOR="$2" awk '
      function trim(s) { sub(/^[ \t\r]+/, "", s); sub(/[ \t\r]+$/, "", s); return s }
      BEGIN { a = trim(ENVIRON["ANCHOR"]); found = 0 }
      trim($0) == a { found = 1 }
      END { exit !found }
    '
}

# Each change as a JSON object, applied in one jq pass at the end.
CHANGES=$(mktemp)
trap 'rm -f "$CHANGES"' EXIT
US=$(printf '\037')

printf '%s\n' "$ROWS" | while IFS="$US" read -r index id path line end_line offset anchor; do
  case "$path" in /*|../*|*/../*) path_ok=false ;; *) path_ok=true ;; esac

  if [ "$path_ok" = true ] && [ ! -f "$path" ] && [ -n "$BASE" ] && git cat-file -e "$BASE:$path" 2> /dev/null; then
    echo "  $id at $path: a file the change deletes; left as it is."
    continue
  fi
  if [ "$path_ok" = false ] || [ ! -f "$path" ]; then
    jq -cn --argjson i "$index" --arg path "$path" \
      '{index: $i, unplaced: "The file it names, \($path), is not in the commit under review."}' >> "$CHANGES"
    echo "  $id at $path: the file is not there; the finding no longer blocks."
    continue
  fi

  if [ -n "$anchor" ]; then
    # Where the anchor should be: `line` plus its place in `existing_code`.
    want=$(( ${line:-0} + ${offset:-0} ))
    # Every line whose trimmed text is the trimmed anchor; the one nearest to where
    # it should be, and whether any is within the finding's own lines. ENVIRON,
    # not -v: awk -v reads escapes in the value.
    set -- $(ANCHOR="$anchor" WANT="$want" FROM="${line:-0}" TO="${end_line:-${line:-0}}" awk '
        function trim(s) { sub(/^[ \t\r]+/, "", s); sub(/[ \t\r]+$/, "", s); return s }
        BEGIN { a = trim(ENVIRON["ANCHOR"]); want = ENVIRON["WANT"] + 0; best = 0; inside = 0
                from = ENVIRON["FROM"] + 0; to = ENVIRON["TO"] + 0 }
        trim($0) == a {
          d = NR - want; if (d < 0) d = -d
          if (best == 0 || d < bestd) { best = NR; bestd = d }
          if (NR >= from && NR <= to) inside = 1
        }
        END { print best, inside }
      ' "$path")
    MATCH=$1
    INSIDE=$2
    if [ "$MATCH" = 0 ] && in_base "$path" "$anchor"; then
      echo "  $id at $path:${line:-?}: its anchor is a line the change removed; left as it is."
    elif [ "$MATCH" = 0 ]; then
      jq -cn --argjson i "$index" --arg path "$path" \
        '{index: $i, unplaced: "The line it rests on is not in \($path) as it stands in the commit under review."}' >> "$CHANGES"
      echo "  $id at $path:${line:-?}: its anchor is in no line of the file; the finding no longer blocks."
    elif [ -z "$line" ]; then
      jq -cn --argjson i "$index" --argjson to "$((MATCH - ${offset:-0}))" '{index: $i, line: $to}' >> "$CHANGES"
      echo "  $id at $path: no line given; placed at line $((MATCH - ${offset:-0})) from its anchor."
    elif [ -z "$offset" ] && [ "$INSIDE" = 1 ]; then
      : # The anchor is within the finding's lines, which is all that can be said.
    elif [ "$MATCH" != "$want" ]; then
      jq -cn --argjson i "$index" --argjson by "$((MATCH - want))" '{index: $i, by: $by}' >> "$CHANGES"
      echo "  $id at $path:$line: its anchor is on line $MATCH, not $want; moved by $((MATCH - want))."
    fi
  elif [ -n "$line" ]; then
    LENGTH=$(awk 'END { print NR }' "$path")
    if [ "$line" -gt "$LENGTH" ] 2> /dev/null; then
      jq -cn --argjson i "$index" --arg path "$path" --argjson n "$LENGTH" \
        '{index: $i, unplaced: "\($path) has \($n) lines, fewer than the line it names."}' >> "$CHANGES"
      echo "  $id at $path:$line: past the end of the file; the finding no longer blocks."
    fi
  fi
done

[ -s "$CHANGES" ] || exit 0

if jq --slurpfile changes "$CHANGES" '
    reduce $changes[] as $c (.;
      if $c.by then
        .findings[$c.index] |= (
          .line = .line + $c.by
          | if (.end_line | type) == "number" then .end_line = .end_line + $c.by else . end)
      elif $c.line then
        .findings[$c.index].line = $c.line
      else
        .findings[$c.index] |= (
          .confidence = ([(.confidence // 10 | if type == "number" then . else (tonumber? // 10) end), 7] | min)
          | .evidence = ("Could not be placed: \($c.unplaced) It is reported but does not block."
                         + (if (.evidence // "") == "" then "" else "\n\n" + .evidence end)))
      end)
  ' "$REPORT" > "$REPORT.tmp"; then
  mv "$REPORT.tmp" "$REPORT"
  echo "Checked the findings' places: $(wc -l < "$CHANGES" | tr -d ' ') changed."
else
  rm -f "$REPORT.tmp"
  echo "Could not apply the place checks; the report is left as the reviewer wrote it."
fi
exit 0
