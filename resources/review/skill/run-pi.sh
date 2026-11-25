#!/bin/sh
# The Pi review: the Pi coding agent (pi.dev), run headless with `pi --mode json`.
# pi-prompt.md as the prompt, and the same ai-review.json at the end.
#
# Pi is the small one of the agents: a handful of tools, a short system prompt and
# no subagents, one Node process. It talks to either vendor's API with the key the
# other reviewers already use, so one reviewer serves a tenant with an Anthropic key
# and one with an OpenAI key.
#
# The report comes back as the arguments of a tool, `submit_review`, which
# pi-report.ts gives Pi: Pi holds a tool's arguments to a schema, where it cannot
# hold an answer to one. write-report.sh then checks each finding's place against
# the checkout, as for the others.
#
# It reviews with whichever provider the tenant has a key for: Anthropic, OpenAI,
# Google Gemini, Mistral, Groq, DeepSeek, xAI or OpenRouter. The provider is the one
# PI_REVIEW_PROVIDER names, else the one the model names (`google/gemini-…`, or a
# name that says it, such as `claude-…`), else the first of those there is a key for.
#
# What it lacks, and this script does not make up for: a second pass. There is no
# `/code-review` or `codex exec review` to take candidates from, and no second
# check on a finding that would block.
#
# Run after prepare-review.sh has been sourced. Exits non-zero when Pi could not
# review, so the step fails.
set -u

echo "##[phase] cli"
HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
. "$HERE/reviewer-env.sh"
# The pull request's own settings for the CLI are not the review's: see reviewer-env.sh.
[ "${REVIEW_LOCAL:-false}" = "true" ] || reviewer_drop_project_settings
TRANSCRIPT="${REVIEW_TRANSCRIPT_FILE:-pi-review.jsonl}"
REPORT="${REVIEW_REPORT_FILE:-ai-review.json}"
rm -f "$REPORT" "$TRANSCRIPT"

# --- Which provider, model and effort ------------------------------------------
#
# A trial's choice, or PI_REVIEW_MODEL and PI_REVIEW_EFFORT. "default" is what a
# trial's form starts with, and means "whatever this would have been without me".
MODEL="${PI_REVIEW_MODEL:-}"
EFFORT="${PI_REVIEW_EFFORT:-}"
if [ "${REVIEW_CUSTOM:-false}" = "true" ]; then
  case "${REVIEW_CUSTOM_MODEL:-}" in ''|default) ;; *) MODEL=$REVIEW_CUSTOM_MODEL ;; esac
  case "${REVIEW_CUSTOM_EFFORT:-}" in ''|default) ;; *) EFFORT=$REVIEW_CUSTOM_EFFORT ;; esac
fi

# Each provider as Pi names it, with the variable its key is in, in the order they
# are tried when nothing says which.
PROVIDERS="anthropic:ANTHROPIC_API_KEY openai:OPENAI_API_KEY google:GEMINI_API_KEY mistral:MISTRAL_API_KEY groq:GROQ_API_KEY deepseek:DEEPSEEK_API_KEY xai:XAI_API_KEY openrouter:OPENROUTER_API_KEY"

# CODEX_API_KEY is an OpenAI API key under the name Codex reads.
export OPENAI_API_KEY="${OPENAI_API_KEY:-${CODEX_API_KEY:-}}"

key_variable() {
  for _pair in $PROVIDERS; do
    [ "${_pair%%:*}" = "$1" ] && { echo "${_pair#*:}"; return 0; }
  done
  return 1
}

# A model written as provider/model names its provider: `google/gemini-3-pro`.
# Only for a provider known here, so an OpenRouter model with a slash of its own
# (`anthropic/claude-…` there) is left whole unless OpenRouter is not the provider.
PROVIDER="${PI_REVIEW_PROVIDER:-}"
if [ -z "$PROVIDER" ]; then
  case "$MODEL" in
    */*)
      if key_variable "${MODEL%%/*}" > /dev/null && eval "[ -n \"\${$(key_variable "${MODEL%%/*}"):-}\" ]"; then
        PROVIDER="${MODEL%%/*}"
        MODEL="${MODEL#*/}"
      fi
      ;;
  esac
fi

# Then what the model's name says, and then the keys there are, Anthropic first.
if [ -z "$PROVIDER" ]; then
  case "$MODEL" in
    claude*) PROVIDER=anthropic ;;
    gpt*|o[0-9]*|codex*) PROVIDER=openai ;;
    gemini*) PROVIDER=google ;;
    mistral*|codestral*|devstral*|magistral*) PROVIDER=mistral ;;
    deepseek*) PROVIDER=deepseek ;;
    grok*) PROVIDER=xai ;;
    *)
      for _pair in $PROVIDERS; do
        if eval "[ -n \"\${${_pair#*:}:-}\" ]"; then
          PROVIDER="${_pair%%:*}"
          break
        fi
      done
      ;;
  esac
fi

OWN_KEY=$(key_variable "${PROVIDER:-none}") || {
  echo "Pi has no provider to review with: none of ANTHROPIC_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY, MISTRAL_API_KEY, GROQ_API_KEY, DEEPSEEK_API_KEY, XAI_API_KEY or OPENROUTER_API_KEY is set${PROVIDER:+, and '$PROVIDER' is not one of them}."
  exit 1
}

# The model, when the review names none: the one the tenant set with the
# provider's connection, or the Laravel AI SDK's pick for the provider.
if [ -z "$MODEL" ]; then
  MODEL=$(eval "printf '%s' \"\${PI_REVIEW_MODEL_$(printf '%s' "$PROVIDER" | tr 'a-z' 'A-Z'):-}\"")
fi
if [ -z "$MODEL" ]; then
  # The Laravel AI SDK's pick, as the review server sends it; the names after them
  # for a step run without the server.
  case "$PROVIDER" in
    anthropic) MODEL=${REVIEW_DEFAULT_MODEL_ANTHROPIC:-claude-opus-5-5} ;;
    openai) MODEL=${REVIEW_DEFAULT_MODEL_OPENAI:-gpt-6.1-sol} ;;
  esac
fi

# Pi's thinking levels carry the names the review's efforts have.
case "$EFFORT" in
  ''|minimal|low|medium|high|xhigh) ;;
  max) EFFORT=xhigh ;;
  *) echo "Pi effort '$EFFORT' is not one of minimal, low, medium, high or xhigh; using Pi's default." >&2; EFFORT="" ;;
esac

# --- Credentials ---------------------------------------------------------------
#
# Pi reads a provider's key from that provider's own variable. A Claude
# subscription token is not one: it is for Claude Code, and Anthropic does not
# permit using it from another client.
if ! eval "[ -n \"\${$OWN_KEY:-}\" ]"; then
  if [ "$PROVIDER" = anthropic ] && [ -n "${CLAUDE_CODE_OAUTH_TOKEN:-}" ]; then
    echo "Only CLAUDE_CODE_OAUTH_TOKEN is set. That token is for Claude Code; Pi calls the API directly and needs ANTHROPIC_API_KEY."
  else
    echo "Pi is set to review with $PROVIDER, but $OWN_KEY is not set."
  fi
  exit 1
fi

if [ -z "$MODEL" ]; then
  echo "No model for Pi's $PROVIDER provider: give its connection a model, or the trial one."
  exit 1
fi

# In the cached tools directory when the image carries none. Pinned to a release
# series, as OCR is: the event stream this script reads is that series'.
PI_SERIES=1
export PATH="$HOME/.tools/node_modules/.bin:$PATH"
if ! command -v pi > /dev/null 2>&1; then
  npm install --prefix "$HOME/.tools" "@earendil-works/pi-coding-agent@^${PI_SERIES}" \
    || { echo "Pi is not installed and could not be; there is nothing to review with."; exit 1; }
fi
command -v pi > /dev/null 2>&1 || { echo "Pi is not installed; there is nothing to review with."; exit 1; }
command -v jq > /dev/null 2>&1 || { echo "jq is missing; the review cannot be read."; exit 1; }

echo "Pi $(pi --version 2> /dev/null | head -1)"
echo "Pi: provider=$PROVIDER model=$MODEL effort=${EFFORT:-Pi's default}"

# The build log gets the reviewer's text and one line per tool call; the whole
# event stream goes to the transcript, which collect-usage-pi.sh reads.
show() {
  tee -a "$TRANSCRIPT" | jq -R -r --unbuffered '
    fromjson?
    | if .type == "message_end" and .message.role == "assistant" then
        ([.message.content[]? | select(.type == "text") | .text] | join("") | select(. != "") | "\n" + . + "\n")
      elif .type == "tool_execution_start" then
        "    -> \(.toolName) \((.args.command // .args.path // .args.pattern // "") | tostring | gsub("\\s+"; " ") | .[0:160])"
      elif .type == "auto_retry_start" then
        "    (retrying: \(.errorMessage // "no reason given" | tostring | .[0:200]))"
      else empty end
  ' 2>/dev/null
}

# The review's duration starts here, after the install.
touch "${REVIEW_USAGE_SINCE:-.review-started}"

# What Pi is started with, and why:
#
# --no-approve     nothing under the checkout's .pi/ is loaded. Extensions there
#                  are code that runs inside the reviewer, and the checkout is the
#                  pull request's.
# --no-extensions  no extension but the one named after it, the built-in MCP
#                  support among those left out: an extension runs with
#                  everything the reviewer has.
# -e pi-report.ts  this application's own, which gives Pi the tool the report
#                  comes back through.
# --no-session     nothing is kept: the transcript above is the record.
# --tools          reading, searching, a shell, and submit_review for the report.
#                  No edit and no write: a review changes no file.
#
# The repository's AGENTS.md and CLAUDE.md are still read, as Claude Code and
# Codex read them: they are its conventions.
#
# There is no sandbox of Pi's own. The container is the boundary, as for Codex, and
# the reviewer starts without the step's secrets (reviewer-env.sh) except its own
# provider's key, which Pi needs in its environment and a shell it starts can read.
echo "##[phase] reviewer"
printf '\n-------------------- Reviewing --------------------\n'
reviewer_env_report "$OWN_KEY|PI_[A-Z0-9_]+"
{
  # shellcheck disable=SC2046
  env $(reviewer_env "$OWN_KEY|PI_[A-Z0-9_]+") PI_SKIP_VERSION_CHECK=1 PI_TELEMETRY=0 \
    pi --mode json \
      --provider "$PROVIDER" \
      --model "$MODEL" \
      ${EFFORT:+--thinking "$EFFORT"} \
      --no-session \
      --no-approve \
      --no-extensions \
      -e "$HERE/pi-report.ts" \
      --no-skills \
      --no-prompt-templates \
      --no-themes \
      --tools read,grep,find,ls,bash,submit_review \
      -- "$(cat "$HERE/pi-prompt.md"; reviewer_skill_parts pi; reviewer_module_notes)" \
      < /dev/null
  echo $? > .pi-exit
} | show

rc=$(cat .pi-exit 2>/dev/null || echo 1)
rm -f .pi-exit

# The report is written by submit_review, which ends the run on that tool call.
# Without one, the last assistant message says how the run ended: JSON mode exits 0
# for an answer that failed.
if [ -s "$REPORT" ] && jq -e 'type == "object" and (.findings | type == "array")' "$REPORT" > /dev/null 2>&1; then
  rc=0
else
  LAST=$(jq -R -c 'fromjson? | select(.type == "message_end" and .message.role == "assistant") | .message | {stopReason, errorMessage}' "$TRANSCRIPT" 2>/dev/null | tail -1)
  STOP=$(printf '%s' "$LAST" | jq -r '.stopReason // ""' 2>/dev/null) || STOP=""
  case "$STOP" in
    '') echo "Pi gave no answer (exit $rc)." ;;
    stop|toolUse) echo "Pi ended the review without handing in a report through submit_review." ;;
    *) echo "Pi's review ended early ($STOP): $(printf '%s' "$LAST" | jq -r '.errorMessage // "no reason given"' 2>/dev/null | head -c 500)" ;;
  esac
  rm -f "$REPORT"
  [ "$rc" = 0 ] && rc=1
  exit "$rc"
fi

# Each new finding's place is checked against the checkout, as for the others:
# write-report.sh keeps the file that is there when it is handed nothing.
sh "$HERE/write-report.sh" Pi < /dev/null

exit 0
