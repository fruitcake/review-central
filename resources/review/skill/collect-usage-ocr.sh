#!/bin/sh
# What an OpenCodeReview review used, in the same shape collect-usage.sh writes for
# Claude:
#
#   {"usage": {"claude-opus-5-5": {"input": 12000, "output": 900, ...}},
#    "cost": null}
#
# OCR adds its totals to its own JSON output, so there is no transcript to hunt for:
# run-ocr.sh keeps that output as ocr-review.json, and this reads its summary. The
# cost is null, as for Codex; the review server prices the tokens from its catalogue.
#
# Always exits 0. Usage is bookkeeping; it must never fail a review that worked.
set -u

OUT="${REVIEW_USAGE_FILE:-ai-usage.json}"
RAW="${OCR_OUTPUT_FILE:-ocr-review.json}"
rm -f "$OUT"

command -v jq > /dev/null 2>&1 || { echo "No jq; skipping usage collection."; exit 0; }

if [ ! -s "$RAW" ]; then
  echo "No $RAW, so OCR's usage is unknown; recording the run without tokens."
  exit 0
fi

# OCR passes the provider's own counts through, and the two vendors count input
# differently: Anthropic's input_tokens leaves the cache out, OpenAI's includes the
# cached part (OCR's usage_resolver.go says as much). Input here is the uncached
# part, as in the other collectors, so for OpenAI the cache read is taken off it -
# otherwise those tokens would be priced twice.
if jq '
     (.summary // {}) as $s
     | (if ((.llm.provider // "") | startswith("openai")) then ($s.cache_read_tokens // 0) else 0 end) as $cached
     | {
         usage: {
           ((.llm.model // "unknown") | tostring): ({
             input: ([($s.input_tokens // 0) - $cached, 0] | max),
             output: ($s.output_tokens // 0),
             cache_read: ($s.cache_read_tokens // 0),
             cache_creation: ($s.cache_write_tokens // 0)
           } | with_entries(select(.value > 0)))
         },
         cost: null
       }
     | if (.usage | to_entries[0].value | length) == 0 then .usage = {} else . end
   ' "$RAW" > "$OUT" 2>/dev/null; then
  echo "Usage collected for $(jq -r '.usage | keys | join(", ")' "$OUT"): $(jq -r '[.usage[] | to_entries[].value] | add // 0' "$OUT") tokens. The review server prices them."
else
  rm -f "$OUT"
  echo "Could not read OCR's usage from $RAW; recording the run without tokens."
fi
exit 0
