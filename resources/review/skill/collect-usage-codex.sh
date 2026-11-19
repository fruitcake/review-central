#!/bin/sh
# What a Codex review used, in the same shape collect-usage.sh writes for Claude:
#
#   {"usage": {"gpt-6-sol": {"input": 12000, "cache_read": 900000, ...}},
#    "cost": null}
#
# Codex records a rollout per session, as JSONL, with a `token_count` event whose
# `info.total_token_usage` is cumulative — so the last one in a file is that
# session's total, and several files are several sessions that add up.
#
# The cost is always null. Codex reports tokens, not money; the review server prices them
# from its own catalogue.
#
# Always exits 0. Usage is bookkeeping; it must never fail a review that worked.
set -u

OUT="${REVIEW_USAGE_FILE:-ai-usage.json}"
SINCE="${REVIEW_USAGE_SINCE:-.review-started}"
rm -f "$OUT"

command -v jq > /dev/null 2>&1 || { echo "No jq; skipping usage collection."; exit 0; }

# Codex keeps sessions under $CODEX_HOME (~/.codex by default). A run outside the
# review server, such as a CI agent, may keep them in its own generated directory,
# so that is looked at first and searched when none of the usual places has them.
AGENT_HOME="${BITBUCKET_CLONE_DIR:-.}/.bitbucket/pipelines/generated/pipeline/agent"

ROOTS=""
for dir in "$AGENT_HOME/config/sessions" "$AGENT_HOME/.codex/sessions" "${CODEX_HOME:-/nonexistent}/sessions" "$HOME/.codex/sessions" /root/.codex/sessions; do
  [ -d "$dir" ] || continue

  # By real path: HOME is /root in the pipeline, so two of these are regularly the
  # same directory, and counting a rollout twice would double every token.
  real=$(cd "$dir" 2>/dev/null && pwd -P) || continue
  case " $ROOTS " in
    *" $real "*) continue ;;
  esac
  ROOTS="$ROOTS $real"
done

if [ -z "$ROOTS" ]; then
  ROOTS=$(find "$AGENT_HOME" "${BITBUCKET_CLONE_DIR:-.}/.bitbucket" -maxdepth 6 -type d -name sessions 2>/dev/null | tr '\n' ' ')
fi

if [ -z "$ROOTS" ]; then
  echo "No Codex session directory: looked under $AGENT_HOME, ${CODEX_HOME:-<unset>}/sessions, $HOME/.codex/sessions and /root/.codex/sessions, then searched ${BITBUCKET_CLONE_DIR:-.}/.bitbucket."
  echo "No usage recorded."
  exit 0
fi

# Only rollouts from this run: a warm image may carry older sessions, and counting
# those would bill this pull request for someone else's work.
# shellcheck disable=SC2086
if [ -f "$SINCE" ]; then
  FILES=$(find $ROOTS -name '*.jsonl' -newer "$SINCE" 2>/dev/null)
else
  FILES=$(find $ROOTS -name '*.jsonl' -mmin -120 2>/dev/null)
fi

if [ -z "$FILES" ]; then
  # shellcheck disable=SC2086
  echo "Session directory found ($ROOTS) but no rollout from this run: $(find $ROOTS -name '*.jsonl' 2>/dev/null | wc -l | tr -d ' ') file(s) there, none newer than $SINCE. No usage recorded."
  exit 0
fi

# shellcheck disable=SC2086
echo "Reading usage from $(printf '%s\n' $FILES | wc -l | tr -d ' ') rollout(s)."

# The model the config asked for, as the fallback name. A rollout usually says which
# model it used; when it does not, the alternative is filing the tokens under
# "unknown", which prices nothing.
#
# Which of the two it was gets recorded and printed, because otherwise the answer to
# "did it really run gpt-6-sol?" is the same either way: the rollout saying so, and
# this script repeating the question back. They are not the same claim.
CONFIGURED_MODEL=""
if [ -n "${REVIEW_AGENT_CONFIG:-}" ] && [ -f "${REVIEW_AGENT_CONFIG}" ]; then
  CONFIGURED_MODEL=$(grep -m1 -E '^ *model *=' "$REVIEW_AGENT_CONFIG" 2>/dev/null | sed -E 's/^[^=]*= *"?([^"]*)"?.*/\1/') || CONFIGURED_MODEL=""
fi

# Per file, because total_token_usage is cumulative within a session: taking the
# last entry of each and adding those up counts every token exactly once.
: > "$OUT.parts"
for file in $FILES; do
  jq -R 'fromjson? // empty' "$file" 2>/dev/null \
    | jq -s --arg fallback "${CONFIGURED_MODEL:-unknown}" '
        # The model is announced in the turn context rather than on the usage event.
        # Null when the rollout named none, which is the thing worth recording:
        # after aggregating, a rollout that really said gpt-6-sol and a fallback
        # that assumed it are the same string, so provenance has to be captured
        # here, while the evidence still exists.
        (([.[] | (.payload.model? // .payload.turn_context?.model? // .model?)
                 | select(type == "string" and . != "")] | last) // null) as $named
        | ([.[] | (.payload.info?.total_token_usage? // .info?.total_token_usage?)
                 | select(type == "object")] | last) as $u
        | if $u == null then empty else
            {
              source: (if $named == null then "configured" else "rollout" end),
              model: ($named // $fallback),
              usage: {
                # input_tokens counts the cached ones too, and they are priced
                # differently, so the cached half is taken back out here rather than
                # being paid for twice.
                input:          ((($u.input_tokens // 0) - ($u.cached_input_tokens // 0)) | if . < 0 then 0 else . end),
                output:         ($u.output_tokens // 0),
                cache_read:     ($u.cached_input_tokens // 0),
                # OpenAI caches automatically and bills nothing to write one.
                cache_creation: 0
              }
            }
          end' >> "$OUT.parts" 2>/dev/null || true
done

# Tokens summed per model, and the provenance of every rollout kept alongside them.
# "mixed" is a real answer: several sessions in one step, some of which named their
# model and some of which did not.
jq -s '{
         usage: (reduce .[] as $part ({};
           .[$part.model] = {
             input:          ((.[$part.model].input          // 0) + $part.usage.input),
             output:         ((.[$part.model].output         // 0) + $part.usage.output),
             cache_read:     ((.[$part.model].cache_read     // 0) + $part.usage.cache_read),
             cache_creation: ((.[$part.model].cache_creation // 0) + $part.usage.cache_creation)
           })
           | with_entries(select([.value[]] | add > 0))),
         sources: ([.[].source] | unique)
       }' "$OUT.parts" > "$OUT.combined" 2>/dev/null \
  || rm -f "$OUT.combined"

if [ -s "$OUT.combined" ]; then
  jq '.usage' "$OUT.combined" > "$OUT.usage" 2>/dev/null || rm -f "$OUT.usage"
fi

rm -f "$OUT.parts"

if [ ! -s "$OUT.usage" ] || [ "$(jq -r 'length' "$OUT.usage" 2>/dev/null || echo 0)" = "0" ]; then
  rm -f "$OUT.usage" "$OUT.combined"
  echo "The rollout held no token counts; none recorded. First bytes of one, so the shape can be checked:"
  printf '%s\n' $FILES | head -1 | while read -r f; do head -c 400 "$f"; echo; done
  exit 0
fi

# Recorded while reading, not inferred afterwards: "rollout" when every session
# named its model, "configured" when none did and the name is our own config read
# back, "mixed" when some did and some did not.
SOURCE=$(jq -r '
  .sources as $s
  | if ($s | length) == 0 then "configured"
    elif $s == ["rollout"] then "rollout"
    elif $s == ["configured"] then "configured"
    else "mixed" end' "$OUT.combined" 2>/dev/null) || SOURCE=configured
[ -z "$SOURCE" ] && SOURCE=configured
rm -f "$OUT.combined"

jq -n --slurpfile usage "$OUT.usage" --arg source "$SOURCE" \
  '{usage: $usage[0], cost: null, model_source: $source}' > "$OUT"
rm -f "$OUT.usage"

echo "Usage collected for $(jq -r '.usage | keys | join(", ")' "$OUT"): $(jq -r '[.usage[] | to_entries[].value] | add' "$OUT") tokens. The review server prices them."

if [ "$SOURCE" = "rollout" ]; then
  echo "The rollout named the model itself, so the attribution is the run's own."
elif [ "$SOURCE" = "mixed" ]; then
  echo "Note: some sessions named their model and some did not; the ones that did not are filed under ${CONFIGURED_MODEL:-unknown} because that is what codex-config.toml asked for."
else
  # Worth saying out loud: the tokens are real, the model name is our own config
  # read back. It is almost certainly right - Codex warned about that exact id, so
  # it received it, and a model it refused would have failed the run rather than
  # produced findings - but this run did not confirm it.
  echo "Note: the rollout named no model, so these tokens are filed under ${CONFIGURED_MODEL:-unknown} because that is what codex-config.toml asked for, not because the run said so."
fi

exit 0
