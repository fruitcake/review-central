# The reviewers the AI Review step runs, and what each one needs. Sourced by
# prepare-review.sh and run-review.sh. The precheck, which the worker does itself
# (App\Runners\Container\Precheck), keeps its own copy of the list.
#
#   . "$HERE/reviewer.sh"
#   reviewer_known claude-code          exits 0 for a reviewer this skill runs
#   reviewer_config claude-code         the config file that describes it
#   reviewer_runner claude-code         the script that runs it
#   reviewers_available                 the ones this repository has a key for
#
# OCR and Pi have no config file of their own: run-ocr.sh and run-pi.sh decide
# their review, so the script stands in for one.

REVIEWERS="claude-code codex-cli ocr pi"

reviewer_known() {
  case " $REVIEWERS " in *" $1 "*) return 0 ;; esac
  return 1
}

reviewer_config() {
  case "$1" in
    claude-code) echo "$HERE/claude-config.json" ;;
    codex-cli)   echo "$HERE/codex-config.toml" ;;
    *)           reviewer_runner "$1" ;;
  esac
}

# The reviewers this skill runs and this repository has a credential for, comma
# separated, for the review server to choose from in the order it keeps. Only whether a key is
# set is read, never its value. Claude Code takes a subscription token too; OCR and
# Pi call the API themselves and need an API key of either vendor.
reviewers_available() {
  _available=""
  for _reviewer in $REVIEWERS; do
    case "$_reviewer" in
      claude-code) [ -n "${ANTHROPIC_API_KEY:-}${CLAUDE_CODE_OAUTH_TOKEN:-}" ] || continue ;;
      codex-cli)   [ -n "${OPENAI_API_KEY:-}${CODEX_API_KEY:-}" ] || continue ;;
      *)           [ -n "${ANTHROPIC_API_KEY:-}${OPENAI_API_KEY:-}${CODEX_API_KEY:-}" ] || continue ;;
    esac
    _available="${_available:+$_available,}$_reviewer"
  done
  echo "$_available"
}

reviewer_runner() {
  case "$1" in
    claude-code) echo "$HERE/run-claude.sh" ;;
    codex-cli)   echo "$HERE/run-codex.sh" ;;
    *)           echo "$HERE/run-$1.sh" ;;
  esac
}
