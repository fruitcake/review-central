#!/bin/sh
# Writes the report a reviewer returned as structured output to ai-review.json, for
# run-claude.sh and run-codex.sh alike.
#
#   printf '%s' "$OUTPUT" | sh write-report.sh <agent name>
#
# Nulls are dropped first: Codex answers in the strict form of the schema, where a
# field the review leaves out comes back as null (strict-schema.jq). A review the
# reviewer was told to stop (SKILL.md) is written as {"skipped": true, "summary":
# why}, which report.sh publishes nothing for. With no output
# at all, a file the reviewer wrote itself is kept. Either way, check-findings.sh then
# checks each new finding's place against the checkout. Always exits 0: whether there
# is a report is for report.sh to say.
set -u

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPORT="${REVIEW_REPORT_FILE:-ai-review.json}"
AGENT=${1:-The reviewer}

OUTPUT=$(cat)
if [ -n "$OUTPUT" ] && ! OUTPUT=$(printf '%s' "$OUTPUT" | jq -c -L "$HERE" 'include "strict-schema"; strip_nulls' 2> /dev/null); then
  echo "$AGENT returned a report that is not JSON."
  OUTPUT=""
fi

if [ -z "$OUTPUT" ]; then
  if [ -s "$REPORT" ]; then
    echo "$AGENT returned no report as structured output; using the $REPORT it wrote."
  else
    echo "$AGENT returned no report."
  fi
elif [ "$(printf '%s' "$OUTPUT" | jq -r '.skipped // false')" = "true" ]; then
  printf '%s' "$OUTPUT" | jq '{skipped: true, summary: (.summary // "No reason given."), result: "PASSED", findings: []}' > "$REPORT"
  echo "$AGENT skipped the review: $(jq -r '.summary' "$REPORT")"
else
  printf '%s' "$OUTPUT" | jq 'del(.skipped)' > "$REPORT"
  echo "Wrote $REPORT from the structured output: $(jq '.findings | length' "$REPORT") finding(s)."
fi

# Every new finding checked against the checkout: moved to the line its anchor is
# on, or kept from blocking when it points at nothing (check-findings.sh).
if [ -s "$REPORT" ] && [ "$(jq -r '.skipped // false' "$REPORT" 2> /dev/null)" != "true" ]; then
  sh "$HERE/check-findings.sh" "$REPORT"
fi
exit 0
