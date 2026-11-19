# A second check, in a fresh context, on each new finding that would block the merge.
# Sourced by run-claude.sh and run-codex.sh once write-report.sh has written the
# report, and only does anything with REVIEW_VERIFY=true.
#
#   verify_one() { ... }        # the runner's own: verify_one <prompt> <answer file>
#   . "$HERE/verify-findings.sh"
#   verify_findings
#
# The reviewer already verifies each candidate (SKILL.md, START ANALYSIS step 4), but
# in the context that came up with it, where a claim it has argued itself into reads
# as settled. So each finding that blocks gets a reader that has seen none of that:
# verify-prompt.md, the finding and the range, read-only tools, and one answer in
# verify-verdict.schema.json's shape. The runner runs it on a cheaper setting than
# the review (REVIEW_VERIFY_MODEL, or Sonnet for Claude and low effort for Codex).
#
# What the answer does:
#
# - refuted: the checker quoted the line that makes the finding false. Its confidence
#   goes to 7, so it is reported as unconfirmed and blocks nothing, with the reason
#   at the top of its evidence. It is not dropped: the checker can be wrong too, and
#   a person reading both is the one who decides.
# - already_there: the defect was there before the change. Marked `introduced: false`,
#   which the report shows as pre-existing and which never blocks.
# - excluded: true, but one of the exclusions in method.md's FALSE POSITIVE FILTERING
#   names it, which the checker reads from the same method the reviewer had. Like
#   refuted: confidence 7, the reason ahead of its evidence, not dropped.
# - holds, unclear or no answer: the finding is left as the reviewer wrote it.
#
# Only new findings, only those that block under REVIEW_FAIL_ON, HIGH first, and at
# most five: the point is the few that hold a merge, at a fraction of the review's
# cost. Its usage is in the reviewer's session files, which collect-usage.sh reads.
# Never fails the step; a check that cannot run leaves the report as it was.

VERIFY_LIMIT=5

verify_findings() {
  [ "${REVIEW_VERIFY:-false}" = "true" ] || return 0
  _report="${REVIEW_REPORT_FILE:-ai-review.json}"
  [ -s "$_report" ] || return 0
  [ "$(jq -r '.skipped // false' "$_report" 2> /dev/null)" = "true" ] && return 0

  _commit="${BITBUCKET_COMMIT:-HEAD}"
  _dest="${BITBUCKET_PR_DESTINATION_BRANCH:-}"
  if [ -z "$_dest" ] || ! _base=$(git merge-base "origin/$_dest" "$_commit" 2> /dev/null); then
    echo "Second check: no merge base with origin/${_dest:-<no destination>}; skipped."
    return 0
  fi

  _fail_on=$(jq -rn -L "$HERE" --arg f "${REVIEW_FAIL_ON:-}" 'include "blocking"; $f | fail_on_spec')
  _indices=$(jq -r -L "$HERE" --arg f "$_fail_on" '
      include "blocking";
      (.findings // []) | to_entries
      | map(select(((.value.status // "new") | ascii_downcase) == "new" and (.value | blocks($f))))
      | sort_by(if ((.value.severity // "") | ascii_upcase) == "HIGH" then 0 else 1 end)
      | .[].key
    ' "$_report" 2> /dev/null) || _indices=""
  if [ -z "$_indices" ]; then
    echo "Second check: no new finding blocks the merge; nothing to check."
    return 0
  fi

  # FALSE POSITIVE FILTERING from the method the reviewer read: the review server's when it
  # sent one with that section, else the copy beside this skill. Without it an
  # `excluded` would name an exclusion from memory, so it counts as unclear.
  _filtering=""
  for _method in .review-instructions/method.md "$HERE/references/method.md"; do
    [ -s "$_method" ] || continue
    _filtering=$(awk '/^FALSE POSITIVE FILTERING:/ { on = 1; next } on && /^[A-Z][A-Z ]+:$/ { exit } on' "$_method" 2> /dev/null) || _filtering=""
    [ -n "$_filtering" ] && break
  done
  [ -n "$_filtering" ] || echo "Second check: no FALSE POSITIVE FILTERING in the method; an excluded answer counts as unclear."

  printf '\n-------------------- Second check --------------------\n'
  _n=0
  _changes=$(mktemp)
  for _i in $_indices; do
    _n=$((_n + 1))
    _id=$(jq -r ".findings[$_i].id // \"?\"" "$_report")
    if [ "$_n" -gt "$VERIFY_LIMIT" ]; then
      echo "$_id: not checked, past the first $VERIFY_LIMIT."
      continue
    fi

    _prompt=$(mktemp)
    _answer=$(mktemp)
    {
      sed -e "s|{{RANGE}}|$_base..$_commit|g" -e "s|{{BASE}}|$_base|g" "$HERE/verify-prompt.md"
      if [ -n "$_filtering" ]; then
        printf '\nThe review'"'"'s exclusions:\n\n%s\n' "$_filtering"
      fi
      printf '\nThe finding:\n\n```json\n'
      jq "
          .findings[$_i]
          | {id, title, severity, category, path, line, end_line, anchor, existing_code, details, evidence}
          | with_entries(select(.value != null))
        " "$_report"
      printf '```\n'
    } > "$_prompt"

    verify_one "$_prompt" "$_answer"

    _verdict=$(jq -r '.verdict // empty' "$_answer" 2> /dev/null) || _verdict=""
    _reason=$(jq -r '.reason // ""' "$_answer" 2> /dev/null) || _reason=""
    [ "$_verdict" = "excluded" ] && [ -z "$_filtering" ] && _verdict=unclear
    case "$_verdict" in
      refuted|already_there|excluded)
        jq -cn --argjson i "$_i" --arg v "$_verdict" --arg r "$_reason" '{index: $i, verdict: $v, reason: $r}' >> "$_changes"
        echo "$_id: $_verdict. $_reason"
        ;;
      holds)
        echo "$_id: holds. $_reason"
        ;;
      unclear)
        echo "$_id: unclear, left as it is. $_reason"
        ;;
      *)
        echo "$_id: no answer from the second check; left as it is."
        ;;
    esac
    rm -f "$_prompt" "$_answer"
  done

  if [ -s "$_changes" ]; then
    if jq --slurpfile changes "$_changes" '
        reduce $changes[] as $c (.;
          .findings[$c.index] |= (
            (if $c.verdict == "refuted" or $c.verdict == "excluded" then
               .confidence = ([(.confidence // 10 | if type == "number" then . else (tonumber? // 10) end), 7] | min)
             else .introduced = false end)
            | .evidence = ((if $c.verdict == "refuted"
                              then "A second check, reading the code on its own, found this does not hold: "
                            elif $c.verdict == "excluded"
                              then "A second check found this is something the review does not report: "
                              else "A second check found this was already there before the change: " end)
                           + $c.reason
                           + (if (.evidence // "") == "" then "" else "\n\n" + .evidence end))))
      ' "$_report" > "$_report.tmp"; then
      mv "$_report.tmp" "$_report"
    else
      rm -f "$_report.tmp"
      echo "Could not apply the second check; the report is left as the reviewer wrote it."
    fi
  fi
  rm -f "$_changes"
  return 0
}
