#!/bin/sh
# The Codex review: a current Codex CLI, installed in the step and run headless
# with `codex exec`. codex-prompt.md as the prompt, codex-config.toml as the settings, and the same
# ai-review.json at the end.
#
# Two things it adds, as run-claude.sh does for Claude. Codex's own review
# (`codex exec review`) runs first, and its findings go to the main review as
# candidates, as Claude's /code-review does. And the report comes back as structured
# output against ai-review.schema.json, the one schema both reviewers use, in the
# strict form OpenAI's API wants (strict-schema.jq); write-report.sh writes the file.
#
# Run after prepare-review.sh has been sourced, so the model and effort are already
# written into the config. Exits
# with the CLI's own exit code, so a review that did not run fails the step as the
# `agent:` directive would.
#
# REVIEW_LOCAL=true is the same review on a developer's machine, outside the
# review server: the codex on the PATH as it is, already signed in
# as the developer, and in Codex's read-only sandbox rather than none.
set -u

echo "##[phase] cli"
HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
. "$HERE/reviewer-env.sh"
# The pull request's own settings for the CLI are not the review's: see reviewer-env.sh.
[ "${REVIEW_LOCAL:-false}" = "true" ] || reviewer_drop_project_settings
CONFIG="${REVIEW_AGENT_CONFIG:-$HERE/codex-config.toml}"
TRANSCRIPT="${REVIEW_TRANSCRIPT_FILE:-codex-review.jsonl}"

REPORT="${REVIEW_REPORT_FILE:-ai-review.json}"
CANDIDATES=codex-review-candidates.md
rm -f "$REPORT" "$CANDIDATES"
# Not part of the change under review: the floor guard lists untracked files as new.
EXCLUDE=$(git rev-parse --git-path info/exclude 2> /dev/null) \
  && mkdir -p "$(dirname "$EXCLUDE")" \
  && { grep -qx "/$CANDIDATES" "$EXCLUDE" 2> /dev/null || echo "/$CANDIDATES" >> "$EXCLUDE"; }

if [ "${REVIEW_LOCAL:-false}" = "true" ]; then
  command -v codex > /dev/null 2>&1 || { echo "No codex on the PATH; install Codex first."; exit 1; }
  echo "Codex $(codex --version)"

  # A home of its own, so the settings are exactly codex-config.toml and the
  # developer's own config.toml is left alone, with their sign-in linked into it.
  # Signed in by a key in the environment instead, there is no file to link, and
  # the key is kept below.
  USER_CODEX_HOME="${CODEX_HOME:-$HOME/.codex}"
  CODEX_HOME=$(mktemp -d)
  export CODEX_HOME
  # On EXIT only, and INT and TERM exit: a handler that does not would let Ctrl-C in
  # the first run delete this home and the main review start without it.
  trap 'rm -rf "$CODEX_HOME"' EXIT
  trap 'exit 130' INT TERM
  cp "$CONFIG" "$CODEX_HOME/config.toml"
  [ -f "$USER_CODEX_HOME/auth.json" ] && ln -s "$USER_CODEX_HOME/auth.json" "$CODEX_HOME/auth.json"
  # Signed in by a key in the environment instead, the key goes with it.
  OWN_KEYS='|OPENAI_API_KEY|CODEX_API_KEY'

  # The developer's own working tree, so a read-only sandbox: the reviewer reads and
  # runs git, and writes nothing - the report comes back as its answer. A sandbox
  # that let it write could check out, reset or clean the very work under review.
  SANDBOX="-c sandbox_mode=read-only"
else
  # In the cached tools directory, next to OCR. The point of this step is a current
  # CLI, so a cached copy is replaced when npm has a newer one, and used as it is when
  # npm cannot be asked.
  export PATH="$HOME/.tools/node_modules/.bin:$PATH"
  CODEX_INSTALLED=$(codex --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
  # A Cloudflare sandbox is told the version its snapshot was made with.
  CODEX_LATEST=${REVIEW_CODEX_VERSION:-$(npm view @openai/codex version 2>/dev/null)} || CODEX_LATEST=""
  if [ -n "$CODEX_LATEST" ] && [ "$CODEX_INSTALLED" != "$CODEX_LATEST" ]; then
    npm install --prefix "$HOME/.tools" "@openai/codex@${CODEX_LATEST}" \
      || echo "Could not update Codex; reviewing with the cached ${CODEX_INSTALLED:-copy, if there is one}."
  elif [ -z "$CODEX_INSTALLED" ]; then
    npm install --prefix "$HOME/.tools" @openai/codex
  fi

  if ! command -v codex > /dev/null 2>&1; then
    echo "Codex is not installed; there is nothing to review with."
    exit 1
  fi

  echo "Codex $(codex --version)"

  # Its own home, not the step's, so the settings are exactly codex-config.toml.
  # ~/.codex because collect-usage-codex.sh already looks for sessions there.
  export CODEX_HOME="${CODEX_HOME:-$HOME/.codex}"
  mkdir -p "$CODEX_HOME"
  cp "$CONFIG" "$CODEX_HOME/config.toml"

  # Logged in from the key rather than handed it in the environment, so the CLI can
  # start without it below. CODEX_API_KEY first, as the agent runtime reads them.
  KEY="${CODEX_API_KEY:-${OPENAI_API_KEY:-}}"
  if [ -z "$KEY" ]; then
    echo "Neither CODEX_API_KEY nor OPENAI_API_KEY is set; Codex cannot authenticate."
    exit 1
  fi
  printf '%s' "$KEY" | codex login --with-api-key > /dev/null || { echo "codex login failed."; exit 1; }
  unset KEY

  # No sandbox and no approvals; see the comment above the review below. The keys
  # go: the login above holds the credential.
  SANDBOX=--dangerously-bypass-approvals-and-sandbox
  OWN_KEYS=""
fi

CODEX_MODEL=$(grep -m1 -E '^[[:space:]]*model[[:space:]]*=' "$CODEX_HOME/config.toml" | sed -E 's/^[^=]*=[[:space:]]*"?([^"]*)"?.*/\1/')
CODEX_EFFORT=$(grep -m1 -E '^[[:space:]]*model_reasoning_effort[[:space:]]*=' "$CODEX_HOME/config.toml" | sed -E 's/^[^=]*=[[:space:]]*"?([^"]*)"?.*/\1/')
echo "Model: ${CODEX_MODEL:-not set, so Codex's default (CODEX_REVIEW_MODEL or --model sets it)}"
echo "Effort: ${CODEX_EFFORT:-not set, so Codex's default (CODEX_REVIEW_EFFORT or --effort sets it)}"

# In the pipeline, no sandbox and no approvals. Codex's sandbox needs kernel features a pipeline
# container does not reliably offer, and a sandbox that fails leaves the reviewer
# without a shell - "The terminal is unavailable here", as the agent step found.
# The container is disposable and the review is told to change nothing but
# ai-review.json; nobody is there to approve anything.
#
# That leaves the reviewer a shell with network access, and it reads text from the
# pull request, so the secrets are not given to it: the CLI starts with the step's
# environment minus every variable named like a secret (reviewer-env.sh), the
# OpenAI keys among them, which it has
# from the login above. That login is a file in CODEX_HOME the reviewer can still
# read; it is the CLI's own credential, as ANTHROPIC_API_KEY is for run-claude.sh.
# Processes started before it still show the step's environment in /proc, which a
# shell without a sandbox can read; that is the limit of what this can do.
#
# The full event stream goes to the transcript file, both runs in turn. The build
# log gets the reviewer's text and one line per command or tool call.
rm -f "$TRANSCRIPT"
show() {
  tee -a "$TRANSCRIPT" | jq -R -r --unbuffered '
    fromjson? | select(.type == "item.completed") | .item
    # Text set apart by a blank line, commands indented under it.
    | if .type == "agent_message" then "\n" + .text + "\n"
      elif .type == "command_execution" then "    -> \(.command // "" | tostring | gsub("\\s+"; " ") | .[0:160])"
      elif .type == "mcp_tool_call" then "    -> \(.server // "mcp").\(.tool // "")"
      else empty end
  ' 2>/dev/null
}

# The review's duration starts here, after the CLI update and the login, and only
# sessions written from here on are counted (collect-usage-codex.sh). Codex's own
# review below is part of the review, as Claude's /code-review is part of Claude's,
# and is marked as such where it runs: "reviewer (candidates)", so it shows by itself
# and the CLI's step is the CLI alone.
touch "${REVIEW_USAGE_SINCE:-.review-started}"

# Codex's own review, as Claude's /code-review is run in its review: a second,
# independent hunt whose findings are candidates for the main review, never findings
# of their own. When it fails, the review goes ahead without it.
#
# Over the same range as the main review. A first review takes the whole pull
# request (--base). A follow-up takes what changed since the commit the last review
# reached, the range SKILL.md gives Claude's /code-review; `codex exec review` has no
# flag for a range, so it is named in a custom prompt, which replaces the target the
# flags would set. After a rebase that commit is gone from the history, and the
# review is a full one again.
review_pass() {
  echo "##[phase] reviewer candidates"
  # shellcheck disable=SC2046
  env $(reviewer_env "CODEX_HOME|OPENAI_BASE_URL${OWN_KEYS}") \
    codex exec review \
      --json \
      $SANDBOX \
      --skip-git-repo-check \
      -o "$CANDIDATES" \
      "$@" \
      < /dev/null | show
}

DEST_BRANCH="${BITBUCKET_PR_DESTINATION_BRANCH:-}"
REVIEWED="${BITBUCKET_COMMIT:-HEAD}"
SINCE=$(jq -r '.handover.reviewed_up_to // .last_reviewed_commit // empty' "${REVIEW_CONTEXT_FILE:-review-context.json}" 2> /dev/null) || SINCE=""
# On unless the extra passes were turned off: Codex's pass costs far less than Claude's.
if [ "${REVIEW_EXTRA_PASSES:-true}" = "false" ]; then
  echo "Codex's own review pass is turned off for this review (REVIEW_EXTRA_PASSES=false)."
elif [ -n "$SINCE" ] && git merge-base --is-ancestor "$SINCE" "$REVIEWED" 2> /dev/null; then
  printf '\n-------------------- Codex review pass --------------------\n'
  echo "Codex's own review of $SINCE..$REVIEWED first, the follow-up's range, for candidates."
  review_pass "Review the code changes in $SINCE..$REVIEWED: what \`git diff $SINCE..$REVIEWED\` shows, read in the context of the files around them. Earlier commits were reviewed already; report only what these changes introduce. Provide prioritized, actionable findings."
elif [ -n "$DEST_BRANCH" ]; then
  printf '\n-------------------- Codex review pass --------------------\n'
  echo "Codex's own review against origin/$DEST_BRANCH first, for candidates."
  review_pass --base "origin/$DEST_BRANCH"
fi
if [ "${REVIEW_EXTRA_PASSES:-true}" != "false" ] && [ -n "$SINCE$DEST_BRANCH" ] && [ ! -s "$CANDIDATES" ]; then
  echo "Codex's own review returned nothing; the review goes ahead without it."
  rm -f "$CANDIDATES"
fi

# codex-prompt.md is the prompt, with the same note on structured output as
# run-claude.sh gives Claude, and where the candidates are.
STRICT=$(mktemp)
LAST=$(mktemp)
PROMPT=$(mktemp)
jq -L "$HERE" 'include "strict-schema"; strict_schema' "$HERE/ai-review.schema.json" > "$STRICT"
{
  cat "$HERE/codex-prompt.md"
  reviewer_skill_parts codex-cli
  reviewer_repository_rules
  reviewer_reach
  reviewer_module_notes
  printf '\n'
  cat "$HERE/structured-output.md"
  printf '\nThe schema is strict: every field is there. Give null for a field this skill says to leave out.\n'
  if [ -s "$CANDIDATES" ]; then
    printf '\nCODEX REVIEW:\n\nCodex'"'"'s own review of this pull request ran before you; what it found is in `%s`. Read it in START ANALYSIS step 2: every item is a candidate that goes through the same filtering and verification, never a finding of its own.\n' "$CANDIDATES"
  fi
} > "$PROMPT"

echo "##[phase] reviewer"
printf '\n-------------------- Reviewing --------------------\n'
reviewer_env_report "CODEX_HOME|OPENAI_BASE_URL${OWN_KEYS}"
{
  # shellcheck disable=SC2046
  env $(reviewer_env "CODEX_HOME|OPENAI_BASE_URL${OWN_KEYS}") \
    codex exec \
      --json \
      $SANDBOX \
      --skip-git-repo-check \
      --output-schema "$STRICT" \
      -o "$LAST" \
      - < "$PROMPT"
  echo $? > .codex-exit
} | show

rc=$(cat .codex-exit 2>/dev/null || echo 1)
rm -f .codex-exit

# The last message is the report, as JSON; write-report.sh writes it, or nothing
# when the review was skipped.
sh "$HERE/write-report.sh" Codex < "$LAST"

# The second check on each finding that would block, with REVIEW_VERIFY=true
# (verify-findings.sh): one short session per finding at low effort, on
# REVIEW_VERIFY_MODEL or the review's own model, in the review's home, so
# collect-usage-codex.sh counts it with the rest.
verify_one() {
  # shellcheck disable=SC2046
  env $(reviewer_env "CODEX_HOME|OPENAI_BASE_URL${OWN_KEYS}") \
    codex exec \
      --json \
      $SANDBOX \
      --skip-git-repo-check \
      ${REVIEW_VERIFY_MODEL:+-m "$REVIEW_VERIFY_MODEL"} \
      -c 'model_reasoning_effort="low"' \
      --output-schema "$HERE/verify-verdict.schema.json" \
      -o "$2" \
      - < "$1" > /dev/null 2>&1
}
if [ "$rc" = 0 ]; then
  . "$HERE/verify-findings.sh"
  verify_findings
fi
rm -f "$STRICT" "$LAST" "$PROMPT"

jq -R -r 'fromjson? | select(.type == "turn.failed" or .type == "error") | "Codex: \(.error.message // .message // tostring)"' \
  "$TRANSCRIPT" 2>/dev/null || true

exit "$rc"
