#!/bin/sh
# Plain checks over the change, run before the reviewer starts, so what a tool can
# find for certain does not depend on a model noticing it. Writes review-scan.md for
# the reviewer, which weighs every line in it as a candidate (SKILL.md, START
# ANALYSIS step 2).
#
#   sh scan-change.sh <destination> [<commit>] [review-scan.md]
#
# The change is what <commit> has that its merge base with <destination> (origin/main,
# say) does not. Worked out here from the commit under review, never from HEAD: a
# pull request pipeline checks out the destination merged into the branch, and the
# merge base of that is the destination's tip, which would show everything the
# destination did since the branch split off as this change undoing it.
#
# Two checks:
#
# - Secrets in added lines: credentials with a shape of their own (a private key, an
#   AWS, Anthropic, OpenAI, GitHub, GitLab, Slack, Stripe, Google or Atlassian key),
#   and a .env file the change adds. Shape only, so no generic "password =": that
#   is the reviewer's to judge. The value is never written out, only its first four
#   characters, because the file is a build artifact and the step log prints it.
# - composer audit, on composer.lock as the commit has it, with each advisory marked
#   as a package the change added or moved, or one that was already there. It asks
#   Packagist's advisory database, so it needs the network, and this runs before the
#   reviewer for that reason. Without composer, a lock file or an answer, the file
#   says so.
#
# Always exits 0: a scan that cannot run leaves the review as it was.
set -u

DESTINATION=${1:-}
COMMIT=${2:-${BITBUCKET_COMMIT:-HEAD}}
OUT=${3:-review-scan.md}

if [ -z "$DESTINATION" ] || ! BASE=$(git merge-base "$DESTINATION" "$COMMIT" 2> /dev/null); then
  echo "Scan: no merge base of ${DESTINATION:-<no destination>} and $COMMIT to compare with; skipped."
  rm -f "$OUT"
  exit 0
fi

SECRETS=$(mktemp)
AUDIT=$(mktemp)
trap 'rm -f "$SECRETS" "$AUDIT"' EXIT

# Added lines with their file and line number, from a diff without context.
git diff --no-color --no-ext-diff --unified=0 "$BASE" "$COMMIT" 2> /dev/null | awk '
  # Each rule: a name and an extended regular expression for the value.
  BEGIN {
    n = 0
    name[++n] = "a private key";           re[n] = "-----BEGIN ([A-Z]+ )?PRIVATE KEY-----"
    name[++n] = "an AWS access key";       re[n] = "(AKIA|ASIA)[0-9A-Z]{16}"
    name[++n] = "an Anthropic API key";    re[n] = "sk-ant-[A-Za-z0-9_-]{20,}"
    name[++n] = "an OpenAI API key";       re[n] = "sk-(proj|svcacct|admin)-[A-Za-z0-9_-]{20,}"
    name[++n] = "a GitHub token";          re[n] = "(gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{60,})"
    name[++n] = "a GitLab token";          re[n] = "glpat-[A-Za-z0-9_-]{20,}"
    name[++n] = "a Slack token";           re[n] = "xox[abprs]-[A-Za-z0-9-]{10,}"
    name[++n] = "a Stripe live key";       re[n] = "(sk|rk)_live_[A-Za-z0-9]{20,}"
    name[++n] = "a Google API key";        re[n] = "AIza[0-9A-Za-z_-]{35}"
    name[++n] = "an Atlassian API token";  re[n] = "(ATATT|ATBB|ATCTT)[A-Za-z0-9_=-]{20,}"
  }
  /^\+\+\+ / { file = substr($0, 7); next }
  /^@@ / {
    # @@ -a,b +c,d @@: the added side starts at c.
    split($3, plus, ",")
    line = substr(plus[1], 2) + 0
    next
  }
  /^\+/ {
    text = substr($0, 2)
    for (i = 1; i <= n; i++) {
      if (match(text, re[i])) {
        value = substr(text, RSTART, RLENGTH)
        printf "- `%s:%d`: %s (`%s…`)\n", file, line, name[i], substr(value, 1, 4)
      }
    }
    line++
  }
' > "$SECRETS"

# An environment file the change adds. The templates are meant to be committed.
git diff --no-color --name-only --diff-filter=A "$BASE" "$COMMIT" 2> /dev/null \
  | grep -E '(^|/)\.env(\.[A-Za-z0-9_-]+)?$' \
  | grep -vE '\.(example|dist|sample|template|testing|ci)$' \
  | sed 's/.*/- `&`: an environment file, added by this change/' >> "$SECRETS"

# composer audit, marked against the lock file at the base.
AUDIT_NOTE=""
if ! git cat-file -e "$COMMIT:composer.lock" 2> /dev/null; then
  AUDIT_NOTE="No composer.lock in this commit; nothing to audit."
elif ! command -v composer > /dev/null 2>&1; then
  AUDIT_NOTE="composer is not installed here; not audited."
else
  # The lock file as the commit has it, in a directory of its own: the audit reads
  # composer.json and composer.lock and nothing else of the project.
  AUDIT_DIR=$(mktemp -d)
  git show "$COMMIT:composer.lock" > "$AUDIT_DIR/composer.lock"
  git show "$COMMIT:composer.json" > "$AUDIT_DIR/composer.json" 2> /dev/null || echo '{}' > "$AUDIT_DIR/composer.json"
  TIMEOUT=""
  command -v timeout > /dev/null 2>&1 && TIMEOUT="timeout 90"
  # Exits non-zero when it finds something, so the JSON decides, not the code.
  $TIMEOUT composer audit --locked --format=json --no-interaction --abandoned=ignore --working-dir="$AUDIT_DIR" > "$AUDIT_DIR/audit.json" 2> /dev/null
  if jq -e '.advisories | type == "object" or type == "array"' "$AUDIT_DIR/audit.json" > /dev/null 2>&1; then
    BEFORE=$(git show "$BASE:composer.lock" 2> /dev/null \
      | jq -c '[(.packages // []) + (."packages-dev" // []) | .[] | {key: .name, value: .version}] | from_entries' 2> /dev/null) || BEFORE=""
    [ -n "$BEFORE" ] || BEFORE='{}'
    jq -r --argjson before "$BEFORE" --slurpfile lock "$AUDIT_DIR/composer.lock" '
        ([($lock[0].packages // []) + ($lock[0]."packages-dev" // []) | .[] | {key: .name, value: .version}] | from_entries) as $now
        | [.advisories | if type == "object" then .[] else empty end | .[]]
        | sort_by(.packageName)
        | .[]
        | ($now[.packageName] // "?") as $version
        | (if $before[.packageName] == null then "added by this change"
           elif $before[.packageName] != $version then "moved by this change from \($before[.packageName])"
           else "already there before this change" end) as $where
        | "- `\(.packageName)` \($version), \($where): \(.title // "an advisory")"
          + (if .cve then " (\(.cve))" else "" end)
          + (if .severity then ", severity \(.severity)" else "" end)
          + (if .link then ", \(.link)" else "" end)
      ' "$AUDIT_DIR/audit.json" > "$AUDIT" 2> /dev/null || AUDIT_NOTE="composer audit answered in a form this does not read; not audited."
  else
    AUDIT_NOTE="composer audit did not answer (no network, or it timed out); not audited."
  fi
  rm -rf "$AUDIT_DIR"
fi

{
  echo "# Scan of the change"
  echo
  echo "Plain checks over $(git rev-parse --short "$BASE")..$(git rev-parse --short "$COMMIT" 2> /dev/null || echo "$COMMIT"), run before the review. Each line is a candidate, not a finding: read the line it names before you report it."
  echo
  echo "## Secrets in added lines"
  echo
  if [ -s "$SECRETS" ]; then cat "$SECRETS"; else echo "None found."; fi
  echo
  echo "## Known vulnerabilities in Composer packages"
  echo
  if [ -n "$AUDIT_NOTE" ]; then echo "$AUDIT_NOTE"
  elif [ -s "$AUDIT" ]; then cat "$AUDIT"
  else echo "None found."; fi
} > "$OUT"

echo "Scan: $(grep -c . "$SECRETS" | tr -d ' ') possible secret(s) in added lines; $(if [ -n "$AUDIT_NOTE" ]; then echo "$AUDIT_NOTE"; else echo "$(grep -c . "$AUDIT" | tr -d ' ') Composer advisory(ies)."; fi) Written to $OUT."
exit 0
