#!/bin/sh
# A security review of a branch as it stands: no pull request, no diff, the whole
# checkout in scope. Claude Code, headless, with security-prompt.md as the prompt
# and the same ai-review.json at the end as a pull request's review writes.
#
#   REVIEW_COMMIT=<sha> sh .review/skill/run-security.sh
#
# One prompt file is the whole of what the reviewer is told, with the stack guide
# the worker wrote beside it where there is one. Nothing is posted anywhere: the
# worker files the report, and it is read on the review's page.
#
# Exits non-zero when there is no report.
set -u

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
. "$HERE/reviewer-env.sh"
TRANSCRIPT="${REVIEW_TRANSCRIPT_FILE:-claude-review.jsonl}"
REPORT="${REVIEW_REPORT_FILE:-ai-review.json}"
rm -f "$REPORT" "$TRANSCRIPT"

# Not part of the code under review: nothing here is the repository's.
EXCLUDE=$(git rev-parse --git-path info/exclude 2> /dev/null) && mkdir -p "$(dirname "$EXCLUDE")" \
  && for file in /.review/ /.review-instructions/ /ai-review.json /ai-usage.json /claude-review.jsonl /.review-started /.claude-exit; do
       grep -qxF "$file" "$EXCLUDE" 2> /dev/null || echo "$file" >> "$EXCLUDE"
     done

# The copy in the home, the one the image carries, or a fresh install.
CLAUDE="$HOME/.local/bin/claude"
[ -x "$CLAUDE" ] || CLAUDE=$(command -v claude 2> /dev/null) || CLAUDE=""
if [ -z "$CLAUDE" ]; then
  curl -fsSL https://claude.ai/install.sh | bash
  CLAUDE="$HOME/.local/bin/claude"
fi
if [ ! -x "$CLAUDE" ]; then
  echo "Claude Code is not installed; there is nothing to review with."
  exit 1
fi
echo "Claude Code $("$CLAUDE" --version)"
echo "Security review of $(git rev-parse --abbrev-ref HEAD 2> /dev/null || echo "the checkout") at ${REVIEW_COMMIT:-$(git rev-parse HEAD 2> /dev/null)}."

# The review's own permissions, without subagents: it reads, searches and runs
# read-only git. A second reader per candidate costs what the review does.
CONFIG=$(mktemp)
jq 'del(.enabledPlugins)
    | .permissions.allow |= map(select(. != "Task" and . != "Agent" and . != "Skill"))
    | .permissions.deny = (["Task", "Agent", "Workflow", "Skill"] + .permissions.deny | unique)' "$HERE/claude-config.json" > "$CONFIG" \
  || cp "$HERE/claude-config.json" "$CONFIG"

MODEL="${SECURITY_REVIEW_MODEL:-${CLAUDE_REVIEW_MODEL:-}}"
EFFORT="${SECURITY_REVIEW_EFFORT:-}"
if [ -n "$MODEL$EFFORT" ]; then
  jq --arg model "$MODEL" --arg effort "$EFFORT" \
    '(if $model == "" then . else .model = $model end) | (if $effort == "" then . else .effortLevel = $effort end)' "$CONFIG" > "$CONFIG.tmp" \
    && mv "$CONFIG.tmp" "$CONFIG"
fi

PROMPT=$(mktemp)
{
  # The platform's own prompt where the worker wrote one, else the one that ships.
  if [ -s .review-instructions/security-prompt.md ] && [ ! -L .review-instructions/security-prompt.md ]; then
    cat .review-instructions/security-prompt.md
  else
    cat "$HERE/security-prompt.md"
  fi
  printf '\n'
  cat "$HERE/structured-output.md"
  printf '\nThe commit under review is %s.\n' "${REVIEW_COMMIT:-$(git rev-parse HEAD 2> /dev/null)}"
} > "$PROMPT"

# A whole repository takes more than a pull request does; still capped, so a review
# that runs away stops there.
MAX_BUDGET=${SECURITY_REVIEW_MAX_BUDGET:-20}
echo "Budget: \$$MAX_BUDGET"

echo "##[phase] reviewer"
printf '\n-------------------- Reviewing --------------------\n'
touch "${REVIEW_USAGE_SINCE:-.review-started}"
reviewer_env_report 'ANTHROPIC_API_KEY|ANTHROPIC_BASE_URL|CLAUDE_CODE_[A-Z0-9_]+|CLAUDE_CONFIG_DIR'
{
  # shellcheck disable=SC2046
  env $(reviewer_env 'ANTHROPIC_API_KEY|ANTHROPIC_BASE_URL|CLAUDE_CODE_[A-Z0-9_]+|CLAUDE_CONFIG_DIR') CLAUDE_CODE_DISABLE_BACKGROUND_TASKS=1 "$CLAUDE" -p \
    --settings "$CONFIG" \
    --permission-mode auto \
    --mcp-config '{"mcpServers":{}}' \
    --strict-mcp-config \
    --output-format stream-json \
    --verbose \
    --max-budget-usd "$MAX_BUDGET" \
    --json-schema "$(cat "$HERE/ai-review.schema.json")" \
    < "$PROMPT"
  echo $? > .claude-exit
} | tee "$TRANSCRIPT" | jq -r --unbuffered '
    if .type == "system" and .subtype == "init" then
      "Claude Code \(.claude_code_version // "?") started on \(.model)."
    elif .type == "assistant" then
      .message.content[]?
      | if .type == "text" then "\n" + .text + "\n"
        elif .type == "tool_use" then "    -> \(.name) \((.input.command // .input.file_path // .input.pattern // .input.path // "") | tostring | gsub("\\s+"; " ") | .[0:160])"
        else empty end
    else empty end
  ' 2> /dev/null

rc=$(cat .claude-exit 2> /dev/null || echo 1)
rm -f .claude-exit "$CONFIG" "$PROMPT"

# The report, from the result event, as for a pull request's review.
jq -c 'select(.type == "result") | .structured_output // empty' "$TRANSCRIPT" 2> /dev/null | tail -n 1 \
  | sh "$HERE/write-report.sh" Claude

if [ ! -s "$REPORT" ]; then
  jq -r 'select(.type == "result") | "Claude Code ended: \(.subtype // "?")\(if .is_error then " (error)" else "" end)"' "$TRANSCRIPT" 2> /dev/null | tail -n 1
  [ "$rc" = 0 ] && rc=1
fi

exit "$rc"
