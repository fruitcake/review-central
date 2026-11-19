# ai-review.schema.json in the form OpenAI's strict structured outputs accept, for
# `codex exec --output-schema`. One schema for both reviewers; this derives Codex's.
#
#   jq -L <this folder> 'include "strict-schema"; strict_schema' ai-review.schema.json
#
# Strict mode wants every object closed (additionalProperties: false) and every
# property required. A property the schema leaves optional becomes required but
# nullable, and the reviewer answers null where it would have left the field out.
# Codex's answer goes through `strip_nulls` below before it is written, so the report
# reads as though the field had been left out, which is what every reader expects.
def nullable:
  if has("const") then {type: [(.const | type), "null"], enum: [.const, null]}
  elif has("enum") then .enum += [null] | .type = ([.type // "string"] | flatten + ["null"] | unique)
  elif (.type | type) == "string" then .type = [.type, "null"]
  else . end;

def strict:
  if type != "object" then .
  elif .type == "object" then
    (.required // []) as $required
    | .properties |= with_entries(
        .key as $key
        | (.value |= strict)
        | if ($required | index($key)) then . else .value |= nullable end)
    | .required = (.properties | keys)
    | .additionalProperties = false
  elif .type == "array" then .items |= strict
  else . end;

def strip_nulls:
  if type == "object" then with_entries(select(.value != null) | .value |= strip_nulls)
  elif type == "array" then map(strip_nulls)
  else . end;

def strict_schema: del(.["$schema"], .title, .description) | strict;
