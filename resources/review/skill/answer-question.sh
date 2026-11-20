#!/bin/sh
# Answers one question someone asked the reviewer in a comment on a pull request.
#
#   REVIEW_QUESTION="How does the export pick its columns?" sh answer-question.sh
#
# Runs in a checkout of the pull request's branch, with a home that holds a copy of
# the session the last review of that branch ended in, where there is one: Claude
# Code resumes it and answers from what it read and found then. Without one it
# answers from the checkout alone.
#
# Writes review-answer.json, Claude Code's own result: the answer as `result`, what
# it cost as `total_cost_usd`. The worker posts the answer; this step has no
# credential for the repository's host. Exits 1 when there is no answer.
set -u

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
. "$HERE/reviewer-env.sh"
# The pull request's own settings for the CLI are not the review's: see reviewer-env.sh.
[ "${REVIEW_LOCAL:-false}" = "true" ] || reviewer_drop_project_settings

# The copy in the home, the one the image carries, or a fresh install.
CLAUDE="$HOME/.local/bin/claude"
[ -x "$CLAUDE" ] || CLAUDE=$(command -v claude 2> /dev/null) || CLAUDE=""
if [ -z "$CLAUDE" ]; then
  curl -fsSL https://claude.ai/install.sh | bash
  CLAUDE="$HOME/.local/bin/claude"
fi
if [ ! -x "$CLAUDE" ]; then
  echo "Claude Code is not installed; the question cannot be answered."
  exit 1
fi
echo "Claude Code $("$CLAUDE" --version)"

if [ -z "${REVIEW_QUESTION:-}" ]; then
  echo "No question was given."
  exit 1
fi

SESSION=""
[ -s "$HOME/.review-session" ] && SESSION=$(head -n 1 "$HOME/.review-session" | tr -cd 'A-Za-z0-9-')

# The question goes in as text after the instructions, never through a shell.
PROMPT=$(mktemp)
{
  cat "$HERE/question-prompt.md"
  printf '\nThe question:\n\n'
  printf '%s\n' "$REVIEW_QUESTION"
} > "$PROMPT"

# With the review's own permissions, less its plugins and subagents: the question
# is text anyone who can comment wrote, so it gets no more than it needs. It
# reads, and runs read-only git. Capped in dollars, so a question cannot cost
# what a review does.
SETTINGS="$HERE/claude-config.json"
if command -v jq > /dev/null 2>&1; then
  QUESTION_SETTINGS=$(mktemp)
  if jq '
       del(.enabledPlugins)
       | .permissions.allow |= map(select(. != "Task" and . != "Agent"))
       | .permissions.deny = (["Task", "Agent", "Workflow", "Skill"] + .permissions.deny | unique)
     ' "$SETTINGS" > "$QUESTION_SETTINGS"; then
    SETTINGS="$QUESTION_SETTINGS"
  fi
fi

ask() {
  # shellcheck disable=SC2046
  env $(reviewer_env 'ANTHROPIC_API_KEY|ANTHROPIC_BASE_URL|CLAUDE_CODE_[A-Z0-9_]+|CLAUDE_CONFIG_DIR') "$CLAUDE" -p \
    "$@" \
    --settings "$SETTINGS" \
    --permission-mode auto \
    --mcp-config '{"mcpServers":{}}' \
    --strict-mcp-config \
    --output-format json \
    --max-budget-usd "${REVIEW_QUESTION_BUDGET:-2}" \
    ${CLAUDE_REVIEW_MODEL:+--model "$CLAUDE_REVIEW_MODEL"} \
    < "$PROMPT" > review-answer.json
}

rm -f review-answer.json
reviewer_env_report 'ANTHROPIC_API_KEY|ANTHROPIC_BASE_URL|CLAUDE_CODE_[A-Z0-9_]+|CLAUDE_CONFIG_DIR'

if [ -n "$SESSION" ]; then
  echo "Answering from the session of the last review (${SESSION})."
  if ! ask --resume "$SESSION"; then
    # A session this CLI cannot resume is no reason to leave the question unanswered:
    # then there is no result at all. One that ran and ended in an error, such as
    # out of budget, has spent its share and is not asked a second time.
    if jq -e '.type == "result"' review-answer.json > /dev/null 2>&1; then
      echo "Claude Code ended the answer with an error."
    else
      echo "That session could not be resumed; answering from the checkout alone."
      rm -f "$HOME/.review-session"
      ask || true
    fi
  fi
else
  echo "No session of a review of this branch; answering from the checkout alone."
  ask || true
fi
rm -f "$PROMPT"

if ! jq -e '(.result | type) == "string" and (.result | length) > 0 and (.is_error != true)' review-answer.json > /dev/null 2>&1; then
  echo "Claude Code gave no answer."
  exit 1
fi

echo "Answered, for \$$(jq -r '.total_cost_usd // "an unknown amount"' review-answer.json)."
