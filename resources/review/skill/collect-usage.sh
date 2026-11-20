#!/bin/sh
# Adds up what the review actually used, from the agent's own session transcript,
# and writes it to ai-usage.json to send to the review server with the result.
#
#   {"usage": {"claude-sonnet-5": {"input": 12000, "cache_read": 900000, ...}},
#    "cost": 0.4231}
#
# The shape of that file is the same for every agent; how it is arrived at is not,
# so each agent has its own collector and this one handles Claude Code.
#
# Why the transcript and not the OTLP exporter: this needs nothing to reach the
# agent's environment and makes no call of its own, so the numbers travel with the
# result in one request instead of a stream of pushes during the run. The cost of
# that is a file format Anthropic does not document — so every step here is
# best-effort, and an empty or missing file simply means no usage was recorded.
#
# Always exits 0. Usage is bookkeeping; it must never fail a review that worked.
echo "##[phase] usage"
set -u

# Claude Code's transcripts are read here; every other reviewer has a collector of
# its own.
case "${REVIEW_AGENT:-claude-code}" in
  claude-code) : ;;
  codex-cli) exec sh "$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)/collect-usage-codex.sh" ;;
  ocr) exec sh "$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)/collect-usage-ocr.sh" ;;
  pi) exec sh "$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)/collect-usage-pi.sh" ;;
  *)
    echo "No usage collector for ${REVIEW_AGENT}; recording the run without tokens."
    exit 0
    ;;
esac

# The cost comes from Claude Code's own `cost-state` entries, which are cumulative,
# so the last one in a transcript is that session's total. It is only recorded when
# Claude Code says it knew every model's price: hasUnknownModelCost means the figure
# is an undercount, and an undercount presented as a cost is worse than no cost.

OUT="${REVIEW_USAGE_FILE:-ai-usage.json}"
SINCE="${REVIEW_USAGE_SINCE:-.review-started}"
rm -f "$OUT"

command -v jq > /dev/null 2>&1 || { echo "No jq; skipping usage collection."; exit 0; }

# Where Claude Code keeps its sessions: CLAUDE_CONFIG_DIR when it is set, otherwise
# ~/.claude. run-claude.sh runs the CLI under the step's own HOME, so those are the
# only places to look. Nothing wider: on a Mac, /home is an automount, and even
# expanding /home/* there hangs.
ROOTS=""
for dir in "${CLAUDE_CONFIG_DIR:-/nonexistent}/projects" "$HOME/.claude/projects"; do
  [ -d "$dir" ] || continue

  # By real path: CLAUDE_CONFIG_DIR may be ~/.claude itself, and counting a
  # transcript twice would double every token and every cost derived from them.
  real=$(cd "$dir" 2>/dev/null && pwd -P) || continue

  case " $ROOTS " in
    *" $real "*) continue ;;
  esac

  ROOTS="$ROOTS $real"
done

if [ -z "$ROOTS" ]; then
  echo "No Claude Code session directory in ${CLAUDE_CONFIG_DIR:-<CLAUDE_CONFIG_DIR unset>}/projects or $HOME/.claude/projects. No usage recorded."
  exit 0
fi

# Only transcripts touched by this run: an image with a warm cache may carry older
# sessions, and counting those would bill this pull request for someone else's work.
# shellcheck disable=SC2086
if [ -f "$SINCE" ]; then
  FILES=$(find $ROOTS -name '*.jsonl' -newer "$SINCE" 2>/dev/null)
else
  FILES=$(find $ROOTS -name '*.jsonl' -mmin -120 2>/dev/null)
fi

if [ -z "$FILES" ]; then
  # shellcheck disable=SC2086
  echo "Session directory found ($ROOTS) but no transcript from this run: $(find $ROOTS -name '*.jsonl' 2>/dev/null | wc -l | tr -d ' ') transcript(s) there, none newer than $SINCE. No usage recorded."
  exit 0
fi

# shellcheck disable=SC2086
echo "Reading usage from $(printf '%s\n' $FILES | wc -l | tr -d ' ') transcript(s)."

# Each assistant message carries its own usage and the model that produced it, so
# the totals are per model, and the cache counters stay separate from plain input
# because they are priced separately.
#
# Once per message, not once per line. Claude Code writes an assistant message to the
# transcript again for each content block it gains - a message with three tool calls
# is three lines - and every copy repeats the same `usage`. Adding them up counted the
# same tokens two or three times over: measured across two real transcripts the naive
# total came to 2.05x and 1.96x the cost Claude Code itself recorded, one message id
# appeared eight times, and only a quarter of messages appeared once. Deduplicating
# lands within a few percent of the recorded figure, the rest being cache writes at
# the one-hour rate rather than the five-minute one.
#
# `.uuid` as the fallback key because it is unique per line: an entry with no
# message.id is kept rather than collapsed into one with every other such entry.
#
# A review that resumed the session of the one before it (run-claude.sh) leaves the
# messages of that review out: they were counted then, and the list of them is in
# COUNTED.
COUNTED="$HOME/.review-counted"
[ -s "$COUNTED" ] || COUNTED=/dev/null
# shellcheck disable=SC2086
cat $FILES 2>/dev/null \
  | jq -R 'fromjson? // empty' 2>/dev/null \
  | jq -s --rawfile counted "$COUNTED" '($counted | split("\n") | map({key: ., value: true}) | from_entries) as $seen
    | [ .[]
      | select(.message.usage != null)
      | {id: (.message.id // .uuid), model: (.message.model // "unknown"), u: .message.usage}
      | select($seen[.id | tostring] | not) ]
    | unique_by(.id)
    | group_by(.model)
    | map({
        key: .[0].model,
        value: {
          input:          ([.[].u.input_tokens                // 0] | add),
          output:         ([.[].u.output_tokens               // 0] | add),
          cache_read:     ([.[].u.cache_read_input_tokens     // 0] | add),
          cache_creation: ([.[].u.cache_creation_input_tokens // 0] | add)
        }
      })
    # Claude Code books some entries against a placeholder model, and they carry no
    # tokens. Recording a model that used nothing would read as though it had run.
    | map(select(.key != "<synthetic>" and ([.value[]] | add) > 0))
    | from_entries' > "$OUT.usage" 2>/dev/null || rm -f "$OUT.usage"

if [ ! -s "$OUT.usage" ] || [ "$(jq -r 'length' "$OUT.usage" 2>/dev/null || echo 0)" = "0" ]; then
  rm -f "$OUT.usage"
  echo "The transcript held no usage; none recorded."
  exit 0
fi

# A resumed session's cost is cumulative over the reviews before it as well, and how
# much of it is this one's cannot be told from the transcript. The tokens are this
# review's own, so they go without a cost and the server prices them.
if [ "$COUNTED" != /dev/null ]; then
  echo "This review resumed a session; recording its own tokens without a cost."
  jq -n --slurpfile usage "$OUT.usage" '{usage: $usage[0], cost: null}' > "$OUT"
  rm -f "$OUT.usage"
  exit 0
fi

# Cumulative per session, so the last entry of each transcript is its total; several
# transcripts mean several sessions, which add up.
COST=0
COMPLETE=true
for file in $FILES; do
  SESSION=$(jq -R 'fromjson? // empty' "$file" 2>/dev/null \
    | jq -s '[.[] | select(.type == "cost-state")] | last // {}' 2>/dev/null) || SESSION='{}'

  [ "$(printf '%s' "$SESSION" | jq -r '.hasUnknownModelCost // false')" = "true" ] && COMPLETE=false
  THIS=$(printf '%s' "$SESSION" | jq -r '.totalCostUSD // 0')
  COST=$(awk -v a="$COST" -v b="$THIS" 'BEGIN { printf "%.6f", a + b }')
done

# The CLI run by run-claude.sh does report its cost, in the result event of the
# stream it wrote, so where the transcripts have none that one is used. The last
# one: with subagents in the background the session ends and resumes as each one
# reports back, and every result event carries the total so far.
RESULT_FILE="${REVIEW_TRANSCRIPT_FILE:-claude-review.jsonl}"
if [ "$COMPLETE" = "true" ] && [ "$COST" = "0.000000" ] && [ -s "$RESULT_FILE" ]; then
  RESULT_COST=$(jq -R 'fromjson? // empty' "$RESULT_FILE" 2>/dev/null \
    | jq -s -r '[.[] | select(.type == "result") | .total_cost_usd // 0] | last // 0' 2>/dev/null) || RESULT_COST=0
  COST=$(awk -v a="${RESULT_COST:-0}" 'BEGIN { printf "%.6f", a }')
fi

if [ "$COMPLETE" != "true" ] || [ "$COST" = "0.000000" ]; then
  if [ "$COMPLETE" != "true" ]; then
    echo "Claude Code could not price every model in this run; recording the tokens without a cost."
  else
    echo "Neither the transcript nor the CLI's result records a cost; recording the tokens only."
  fi
  jq -n --slurpfile usage "$OUT.usage" '{usage: $usage[0], cost: null}' > "$OUT"
else
  jq -n --slurpfile usage "$OUT.usage" --argjson cost "$COST" '{usage: $usage[0], cost: $cost}' > "$OUT"
fi

rm -f "$OUT.usage"

echo "Usage collected for $(jq -r '.usage | keys | join(", ")' "$OUT"): $(jq -r '[.usage[] | to_entries[].value] | add' "$OUT") tokens, cost $(jq -r '.cost // "unknown"' "$OUT")."
exit 0
