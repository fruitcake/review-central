#!/bin/sh
# What a Pi review used, in the same shape collect-usage.sh writes for Claude:
#
#   {"usage": {"claude-opus-5-5": {"input": 12000, "cache_read": 900000, ...}},
#    "cost": null}
#
# run-pi.sh keeps Pi's event stream as pi-review.jsonl. Every assistant message in
# it ends with a `message_end` that carries that one request's usage, with the
# cached part counted apart from the input, so adding them up counts every token
# once. The model is the one the provider says answered, when it says.
#
# The cost is always null: Pi prices from its own catalogue, the review server
# from its own, and one price list is enough.
#
# Always exits 0. Usage is bookkeeping; it must never fail a review that worked.
set -u

OUT="${REVIEW_USAGE_FILE:-ai-usage.json}"
TRANSCRIPT="${REVIEW_TRANSCRIPT_FILE:-pi-review.jsonl}"
rm -f "$OUT"

command -v jq > /dev/null 2>&1 || { echo "No jq; skipping usage collection."; exit 0; }

if [ ! -s "$TRANSCRIPT" ]; then
  echo "No $TRANSCRIPT, so Pi's usage is unknown; recording the run without tokens."
  exit 0
fi

jq -R 'fromjson? // empty' "$TRANSCRIPT" 2>/dev/null \
  | jq -s '{
      usage: (reduce (.[] | select(.type == "message_end" and .message.role == "assistant") | .message) as $m ({};
          (($m.responseModel // $m.model // "unknown") | tostring) as $model
          | .[$model] = {
              input:          ((.[$model].input          // 0) + ($m.usage.input      // 0)),
              output:         ((.[$model].output         // 0) + ($m.usage.output     // 0)),
              cache_read:     ((.[$model].cache_read     // 0) + ($m.usage.cacheRead  // 0)),
              cache_creation: ((.[$model].cache_creation // 0) + ($m.usage.cacheWrite // 0))
            })
        | with_entries(select([.value[]] | add > 0))),
      cost: null
    }' > "$OUT" 2>/dev/null || rm -f "$OUT"

if [ ! -s "$OUT" ] || [ "$(jq -r '.usage | length' "$OUT" 2>/dev/null || echo 0)" = "0" ]; then
  rm -f "$OUT"
  echo "Pi's events held no token counts; recording the run without tokens."
  exit 0
fi

echo "Usage collected for $(jq -r '.usage | keys | join(", ")' "$OUT"): $(jq -r '[.usage[] | to_entries[].value] | add' "$OUT") tokens. The review server prices them."
exit 0
