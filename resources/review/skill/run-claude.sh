#!/bin/sh
# The Claude review: a current Claude Code CLI, installed in the step and run
# headless with `claude -p`. SKILL.md as the prompt, claude-config.json as the settings and the same
# ai-review.json at the end.
#
# One thing differs in how that file arrives. The CLI takes a JSON Schema
# (ai-review.schema.json) and returns the reviewer's report as structured output,
# checked against it, and this script writes the file from that. The reviewer
# writes nothing itself.
#
# Run after prepare-review.sh has been sourced, so the model and effort variables
# are already written into the config. Exits with the
# CLI's own exit code, so a review that did not run fails the step as the `agent:`
# directive would.
#
# REVIEW_LOCAL=true is the same review on a developer's machine, outside the
# review server: the CLI on the PATH as it is, and only the
# project's settings, so a personal hook or permission does not change what the
# review does.
set -u

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
. "$HERE/reviewer-env.sh"
CONFIG="${REVIEW_AGENT_CONFIG:-$HERE/claude-config.json}"
TRANSCRIPT="${REVIEW_TRANSCRIPT_FILE:-claude-review.jsonl}"
CLAUDE="$HOME/.local/bin/claude"
SETTING_SOURCES=""

if [ "${REVIEW_LOCAL:-false}" = "true" ]; then
  CLAUDE=$(command -v claude) || { echo "No claude on the PATH; install Claude Code first."; exit 1; }
  SETTING_SOURCES=project
  echo "Claude Code $("$CLAUDE" --version)"
else
  # The point of this step is a current CLI, so a cached one is updated rather than
  # trusted: the claude-cli cache survives every release.
  #
  # An image can carry one too, as the container runner's does, where this step
  # can neither update it nor keep a copy of its own. It is used while it is the
  # release npm names as the latest, and when npm cannot be asked. An image that
  # fell behind costs the install below, not the current CLI.
  IMAGE_CLAUDE=""
  [ -x "$CLAUDE" ] || IMAGE_CLAUDE=$(command -v claude 2>/dev/null) || IMAGE_CLAUDE=""
  if [ -n "$IMAGE_CLAUDE" ]; then
    IMAGE_VERSION=$("$IMAGE_CLAUDE" --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1) || IMAGE_VERSION=""
    LATEST_VERSION=$(npm view @anthropic-ai/claude-code version 2>/dev/null) || LATEST_VERSION=""
    if [ -z "$IMAGE_VERSION" ]; then
      echo "The image's Claude Code does not start; installing it."
    elif [ -n "$LATEST_VERSION" ] && [ "$IMAGE_VERSION" != "$LATEST_VERSION" ]; then
      echo "The image has Claude Code $IMAGE_VERSION and $LATEST_VERSION is out; installing that. Building the image again saves this."
    else
      CLAUDE="$IMAGE_CLAUDE"
    fi
  fi

  if [ "$CLAUDE" = "$IMAGE_CLAUDE" ]; then
    :
  elif [ -x "$CLAUDE" ]; then
    "$CLAUDE" update || echo "Could not update Claude Code; reviewing with the cached $("$CLAUDE" --version)."
  else
    curl -fsSL https://claude.ai/install.sh | bash
  fi

  if [ ! -x "$CLAUDE" ]; then
    echo "Claude Code is not installed at $CLAUDE; there is nothing to review with."
    exit 1
  fi

  echo "Claude Code $("$CLAUDE" --version)"
fi

# No MCP server at all. --strict-mcp-config keeps out the repository's own
# .mcp.json, which names servers for a developer's machine, and a server started
# from the checkout would run the pull request's code beside the reviewer.
MCP_CONFIG='{"mcpServers":{}}'

# What was asked for; the model Claude Code actually picked follows from its init
# event once it starts, since with nothing set that is its own default.
echo "Model: $(jq -r '.model // "not set, so Claude Code'"'"'s default (CLAUDE_REVIEW_MODEL or --model sets it)"' "$CONFIG")"
echo "Effort: $(jq -r '.effortLevel // "not set, so Claude Code'"'"'s default (CLAUDE_REVIEW_EFFORT or --effort sets it)"' "$CONFIG")"
echo "Advisor: $(jq -r '.advisorModel // "none"' "$CONFIG")"

REPORT="${REVIEW_REPORT_FILE:-ai-review.json}"
SCHEMA="$HERE/ai-review.schema.json"
rm -f "$REPORT"

# SKILL.md is the prompt, as the agent's "!file" prompt hands it over, with a note
# after it on how the report comes back.
#
# The report as structured output rather than a file the reviewer writes: the
# schema is checked before the run ends, so a report with "NEW" for new or a
# finding without a severity is sent back to the reviewer instead of reaching
# the worker's report step, and a write the permissions refuse can no longer lose the review.
PROMPT=$(mktemp)
{
  # The platform's own structure where the precheck wrote one, else the one that ships.
  if [ -s .review-instructions/skill.md ] && [ ! -L .review-instructions/skill.md ]; then
    cat .review-instructions/skill.md
  else
    cat "$HERE/SKILL.md"
  fi
  printf '\n'
  cat "$HERE/structured-output.md"
  # The pipeline config refuses subagents (prepare-review.sh). Said up front, so the
  # reviewer does not spend turns on calls that are refused.
  if [ "${REVIEW_LOCAL:-false}" != "true" ] && [ "${REVIEW_EXTRA_PASSES:-false}" != "true" ]; then
    printf '\n%s\n' "This run starts no subagents: the Task and Workflow tools, the pr-review-toolkit agents and the code-review skill are not available here, and that is expected. Do the review in this one context, and verify each candidate yourself as START ANALYSIS step 4 says. Do not list them as unavailable in the summary."
  fi
} > "$PROMPT"

# Every session runs in the foreground. `claude -p` already waits for its subagents,
# but a background task would end the session in several result events, one each
# time it resumes; this keeps it to one.
FOREGROUND=CLAUDE_CODE_DISABLE_BACKGROUND_TASKS=1
MAX_BUDGET=${CLAUDE_REVIEW_MAX_BUDGET:-10}
echo "Budget: $([ "$MAX_BUDGET" = none ] && echo "none (CLAUDE_REVIEW_MAX_BUDGET=none)" || echo "\$$MAX_BUDGET")"

# Auto mode: the settings file's allow and deny rules decide first, and anything
# they do not cover goes to Claude Code's classifier instead of being refused
# outright - which is what a reviewer's `cat x; echo; ls`, a `$(git merge-base ...)`
# or an `echo "$VAR"` kept running into. A call the classifier blocks is refused
# and the run carries on, as before.
#
# That gives the reviewer more room than the allow list alone did, and it reads
# text from the pull request, so what it could do with that room matters. The
# allow list holds read-only git, ocr and the floor guard; the
# deny list refuses network commands, anything that changes the checkout, and the
# ways to run code or read the environment (php -r, composer run).
# A deny rule matches how a command is written, not what it does, and the
# classifier is a judgement, not a guarantee. So the secrets are not given to it at
# all: the CLI starts with the step's environment minus every variable named like
# a secret (reviewer-env.sh), keeping its own credential, ANTHROPIC_API_KEY or
# CLAUDE_CODE_OAUTH_TOKEN. What it cannot be kept
# from is /proc, where processes started before it - this script among them - still
# show the step's environment; the config refuses the obvious ways to read it.
#
# Where auto mode is unavailable - a CLI too old, or Anthropic turning it off - the
# session starts in the default mode, which in print mode is the allow list alone:
# exactly the behaviour before this.
#
# The full stream goes to the transcript file for collect-usage.sh, which reads the
# cost from its result event. The build log gets the reviewer's text and one line per
# tool call: the tool and what it was called on, not its whole input.
# A follow-up review of the same pull request, in the checkout and the home of the
# review before it (REVIEW_CONTINUE): the session that review ended in is resumed,
# so this one starts from what that one read and found. By its id rather than
# "the latest", which would be a session that verified one finding. Without a
# session on record the review starts fresh, as any other.
SESSION_FILE="$HOME/.review-session"
RESUME_SESSION=""
if [ "${REVIEW_CONTINUE:-false}" = "true" ] && [ -s "$SESSION_FILE" ]; then
  RESUME_SESSION=$(head -n 1 "$SESSION_FILE" | tr -cd 'A-Za-z0-9-')
  echo "Carrying on from the session of the review before this one (${RESUME_SESSION})."
fi

# What the session held before this review, so collect-usage.sh counts this
# review's messages only: a resumed session's transcript carries on from the old one.
COUNTED="$HOME/.review-counted"
rm -f "$COUNTED"
if [ -n "$RESUME_SESSION" ]; then
  find "${CLAUDE_CONFIG_DIR:-$HOME/.claude}/projects" "$HOME/.claude/projects" -name '*.jsonl' -exec cat {} + 2>/dev/null \
    | jq -R -r 'fromjson? // empty | select(.message.usage != null) | (.message.id // .uuid)' 2>/dev/null \
    | sort -u > "$COUNTED"
fi

printf '\n-------------------- Reviewing --------------------\n'
# The review's duration starts here, after the CLI update,
# and only transcripts written from here on are counted (collect-usage.sh).
touch "${REVIEW_USAGE_SINCE:-.review-started}"
reviewer_env_report 'ANTHROPIC_API_KEY|ANTHROPIC_BASE_URL|CLAUDE_CODE_[A-Z0-9_]+|CLAUDE_CONFIG_DIR'
{
  # CLAUDE_REVIEW_MAX_BUDGET caps the review in dollars, 10 by default, so a review
  # that runs away stops there. One that runs out returns no report and fails the
  # step, like any review that did not finish. `none` lifts the cap.
  # shellcheck disable=SC2046
  env $(reviewer_env 'ANTHROPIC_API_KEY|ANTHROPIC_BASE_URL|CLAUDE_CODE_[A-Z0-9_]+|CLAUDE_CONFIG_DIR') "$FOREGROUND" "$CLAUDE" -p \
    ${RESUME_SESSION:+--resume "$RESUME_SESSION"} \
    --settings "$CONFIG" \
    --permission-mode auto \
    --mcp-config "$MCP_CONFIG" \
    --strict-mcp-config \
    --output-format stream-json \
    --verbose \
    $([ "$MAX_BUDGET" = none ] || echo --max-budget-usd "$MAX_BUDGET") \
    --json-schema "$(cat "$SCHEMA")" \
    ${SETTING_SOURCES:+--setting-sources "$SETTING_SOURCES"} \
    < "$PROMPT"
  echo $? > .claude-exit
} | tee "$TRANSCRIPT" | jq -r --unbuffered '
    # What the session was given, so a summary saying a skill or a server was
    # not available can be checked against what the reviewer actually had.
    if .type == "system" and .subtype == "init" then
      "Claude Code \(.claude_code_version // "?") started on \(.model), permission mode \(.permissionMode // "?").",
      "MCP servers: \([.mcp_servers[]? | "\(.name) (\(.status))"] | if length == 0 then "none" else join(", ") end)",
      "Skills: \(.skills // [] | join(", "))"
    elif .type == "assistant" then
      # A subagent message carries the tool call that started it. Its tool calls are
      # marked and its text left out, so the main reasoning stays readable.
      (.parent_tool_use_id != null) as $sub
      | .message.content[]?
      # Text set apart by a blank line, tool calls indented under it, so the reasoning
      # does not drown in a column of commands.
      | if .type == "text" then (if $sub then empty else "\n" + .text + "\n" end)
        elif .type == "tool_use" then
          (if $sub then "        (subagent) -> " else "    -> " end)
          + "\(.name) "
          + (if .input.subagent_type then "[\(.input.subagent_type)] " else "" end)
          + (.input.command // .input.file_path // .input.pattern // .input.description // .input.query // .input.skill // "" | tostring | gsub("\\s+"; " ") | .[0:160])
        else empty end
    else empty end
  ' 2>/dev/null

rc=$(cat .claude-exit 2>/dev/null || echo 1)
rm -f .claude-exit "$PROMPT"

# The report, from the result event: write-report.sh writes it, or nothing when the
# review was skipped.
jq -c 'select(.type == "result") | .structured_output // empty' "$TRANSCRIPT" 2>/dev/null | tail -n 1 \
  | sh "$HERE/write-report.sh" Claude

# The second check on each finding that would block, with REVIEW_VERIFY=true
# (verify-findings.sh): one short session per finding, on REVIEW_VERIFY_MODEL or
# Sonnet, with the review's permissions and no advisor. Its session files are the
# review's, so collect-usage.sh counts it.
verify_one() {
  _vconfig=$(mktemp)
  jq --arg model "${REVIEW_VERIFY_MODEL:-sonnet}" '.model = $model | del(.advisorModel, .effortLevel)' "$CONFIG" > "$_vconfig"
  # shellcheck disable=SC2046
  env $(reviewer_env 'ANTHROPIC_API_KEY|ANTHROPIC_BASE_URL|CLAUDE_CODE_[A-Z0-9_]+|CLAUDE_CONFIG_DIR') "$FOREGROUND" "$CLAUDE" -p \
    --settings "$_vconfig" \
    --permission-mode auto \
    --mcp-config '{"mcpServers":{}}' \
    --strict-mcp-config \
    --output-format json \
    --max-turns 40 \
    --json-schema "$(cat "$HERE/verify-verdict.schema.json")" \
    ${SETTING_SOURCES:+--setting-sources "$SETTING_SOURCES"} \
    < "$1" 2> /dev/null \
    | jq -c '.structured_output // empty' > "$2" 2> /dev/null
  rm -f "$_vconfig"
}
# The session this review ran in, for a follow-up to resume. The last one named:
# a resumed session may go on under a new id.
jq -r 'select(.type == "result" or (.type == "system" and .subtype == "init")) | .session_id // empty' "$TRANSCRIPT" 2>/dev/null \
  | tail -n 1 > "$SESSION_FILE.tmp" && [ -s "$SESSION_FILE.tmp" ] && mv "$SESSION_FILE.tmp" "$SESSION_FILE" || rm -f "$SESSION_FILE.tmp"

if [ "$rc" = 0 ]; then
  . "$HERE/verify-findings.sh"
  verify_findings
fi

# The last result event: with subagents in the background there is one each time the
# session resumes, and the last carries the total.
jq -s -r '[.[] | select(.type == "result")] | last // empty | "Claude finished: \(.subtype), \(.duration_ms / 1000 | floor)s for the last part, $\(.total_cost_usd) in all"' \
  "$TRANSCRIPT" 2>/dev/null || true

# How the review went, beside what it cost: which skills it loaded, which agents it
# started and how many tool calls of each kind it made. SKILL.md asks for the stack
# skills, and a run that skipped them otherwise only shows as a review that found
# less. It also starts no subagents, and the settings refuse the tool: one that
# shows up here got past both.
jq -s -r '
    [.[] | select(.type == "assistant" and .parent_tool_use_id == null) | .message.content[]? | select(.type == "tool_use")] as $calls
    | ([.[] | select(.type == "result")] | last // {}) as $result
    | "Turns: \($result.num_turns // "?"), thinking tokens: \($result.usage.output_tokens_details.thinking_tokens // "?")",
      "Skills loaded: \([$calls[] | select(.name == "Skill") | .input.skill] | if length == 0 then "none" else join(", ") end)",
      "Subagents started: \([$calls[] | select(.name == "Task" or .name == "Agent") | .input.subagent_type // "general-purpose"] | if length == 0 then "none" else group_by(.) | map("\(.[0]) x\(length)") | join(", ") end)"
        + ($result.subagent_stats | if . then " (\(.completed // 0) completed, \(.failed // 0) failed)" else "" end),
      "Tool calls: \($calls | group_by(.name) | map("\(.[0].name) \(length)") | join(", "))"
  ' "$TRANSCRIPT" 2>/dev/null || true

# A refused tool call is otherwise silent: the reviewer works round it, and a
# refused write of ai-review.json only shows as a report that never arrives.
jq -r 'select(.type == "result") | .permission_denials[]? | "Refused: \(.tool_name) \(.tool_input.command // .tool_input.file_path // "" | .[0:160])"' \
  "$TRANSCRIPT" 2>/dev/null || true

exit "$rc"
