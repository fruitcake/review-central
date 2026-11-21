#!/bin/sh
# The model and effort the review server sets for this repository's reviewers, the
# Claude review's budget (CLAUDE_REVIEW_MAX_BUDGET), whether the second check
# runs (REVIEW_VERIFY), and which parts of the review run: the reviewer's own pass
# (REVIEW_EXTRA_PASSES), OCR (REVIEW_OCR), the scan (REVIEW_SCAN) and the floor
# guard (REVIEW_FLOOR_GUARD). Applied wherever the step's environment sets no variable of
# its own:
#
#   {"CLAUDE_REVIEW_MODEL": "...", "CLAUDE_REVIEW_EFFORT": "high", ...}
#
# The review server keeps a default for every repository and a repository's own
# values over it, and picks the reviewer among those the tenant has a key for, by
# share, from the pull request's branch, so every push of a pull request gets the
# same reviewer.
#
# Sourced, not run, so what it exports reaches the script that sources it. The
# precheck, which the worker does itself, writes the settings into
# review-context.json as settings.server_variables; prepare-review.sh applies them in
# the review step with review_settings_apply.
#
# Only the names below are taken, so nothing else can be set in the step. The
# reviewer itself is picked by the precheck, which checks the tenant has that
# reviewer's key first.

# REVIEW_DEFAULT_MODEL_ANTHROPIC and _OPENAI are the Laravel AI SDK's picks, for a
# reviewer that has no default model of its own (OpenCodeReview, Pi).
REVIEW_SETTINGS_NAMES="CLAUDE_REVIEW_MODEL CLAUDE_REVIEW_EFFORT CLAUDE_REVIEW_ADVISOR CLAUDE_REVIEW_MAX_BUDGET CODEX_REVIEW_MODEL CODEX_REVIEW_EFFORT OCR_REVIEW_MODEL OCR_REVIEW_EFFORT PI_REVIEW_EFFORT REVIEW_VERIFY REVIEW_EXTRA_PASSES REVIEW_OCR REVIEW_SCAN REVIEW_FLOOR_GUARD REVIEW_DEFAULT_MODEL_ANTHROPIC REVIEW_DEFAULT_MODEL_OPENAI"

# Exports each setting in $1, a JSON object of variables, whose variable is unset or
# empty, and says which it took. Where a variable is set and the review server has another
# value, it says so: the review server shows its setting, and without this line nothing in
# the build says why the review ran on something else. A value with anything but
# the characters a model name or an effort uses is refused: it goes into a config
# file and the log. A number, such as a budget, is taken as its text.
review_settings_apply() {
  for _name in $REVIEW_SETTINGS_NAMES; do
    eval "_current=\${$_name:-}"
    _value=$(printf '%s' "$1" | jq -r --arg name "$_name" '.[$name] // empty | if type == "number" then tostring else . end | strings' 2> /dev/null) || _value=""

    if [ -n "$_current" ]; then
      [ -n "$_value" ] && [ "$_value" != "$_current" ] \
        && echo "$_name=$_current from the step's environment, not the review server's $_value. Unset it there to use the review server's."
      continue
    fi

    [ -n "$_value" ] || continue

    case "$_value" in
      *[!]A-Za-z0-9._:/@[-]*)
        echo "The review server's $_name is not a model or effort name; ignoring it."
        continue
        ;;
    esac

    eval "$_name=\$_value"
    export "$_name"
    echo "$_name=$_value, from the review server."
  done
}
