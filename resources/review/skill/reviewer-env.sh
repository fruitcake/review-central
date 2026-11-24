# The environment a reviewer starts with: the step's own, without its secrets.
#
# Sourced by run-claude.sh, run-codex.sh, run-ocr.sh and run-pi.sh:
#
#   . "$HERE/reviewer-env.sh"
#   reviewer_env_report 'ANTHROPIC_API_KEY|CLAUDE_CODE_[A-Z0-9_]+'
#   env $(reviewer_env 'ANTHROPIC_API_KEY|CLAUDE_CODE_[A-Z0-9_]+') claude -p ...
#
# reviewer_env prints `-u NAME` for every variable that looks like a secret, so
# `env` starts the reviewer without them. The argument names what the caller keeps
# anyway: its own CLI's credential and settings.
#
# A denylist by pattern rather than an allowlist. The allowlist this replaced kept
# the reviewer away from a secret of any name, but it also took away something the
# pipeline image sets and Claude Code's shell relies on: every Bash call ran, and
# its output went to the build log instead of back to the reviewer. Matching names
# instead leaves the runtime what it had before. What it misses is a secret named
# unlike one, an SMTP_URL with a password in it, say. The patterns below catch the
# usual names, and reviewer_env_report lists what was dropped, so the log shows
# what the reviewer did not get.
#
# Names only ever come from the environment's own keys and are matched against a
# fixed pattern, so the output is safe to split on spaces.

# Anything with one of these in its name, wherever it appears.
#
# GIT_CONFIG_ goes as a whole. Git reads GIT_CONFIG_COUNT pairs of GIT_CONFIG_KEY_n
# and GIT_CONFIG_VALUE_n, and KEY alone would take the keys and leave the count:
# git then refuses every command ("missing config key GIT_CONFIG_KEY_0"), and Codex
# reported the checkout as not a git repository. A value can carry a credential,
# such as an http.extraHeader, so none of the three is kept.
REVIEWER_ENV_SECRET='KEY|TOKEN|SECRET|PASS|CREDENTIAL|PRIVATE|AUTH|DSN|COOKIE|JWT|DATABASE_URL|REDIS_URL|MAIL_URL|WEBHOOK|GIT_CONFIG_'

# Names the patterns catch that are not secrets: git's author, and the short code
# of the Bitbucket project.
REVIEWER_ENV_SAFE='GIT_AUTHOR_NAME|GIT_AUTHOR_EMAIL|GIT_AUTHOR_DATE|BITBUCKET_PROJECT_KEY'

reviewer_env_dropped() {
  env | sed -n 's/^\([A-Za-z_][A-Za-z0-9_]*\)=.*/\1/p' | sort -u \
    | grep -E "(${REVIEWER_ENV_SECRET})" \
    | grep -Ev "^(${REVIEWER_ENV_SAFE}${1:+|$1})\$"
}

reviewer_env() {
  reviewer_env_dropped "${1:-}" | sed 's/^/-u /' | tr '\n' ' '
}

# One line for the build log: the names the reviewer starts without, never values.
reviewer_env_report() {
  dropped=$(reviewer_env_dropped "${1:-}" | tr '\n' ' ' | sed 's/ $//; s/ /, /g')
  echo "Reviewer environment: the step's, without ${dropped:-anything}"
}

# The pull request's own settings for the reviewer's CLI, out of the checkout
# before the CLI starts in it. They are the pull request's to write, and a CLI
# reads them as the project's: a hook in .claude/settings.json runs a command as
# the reviewer, and an env entry such as ANTHROPIC_BASE_URL sends the key to
# whoever it names. Codex reads .codex/config.toml, and Pi loads .pi/ with its
# extensions, the same way. CLAUDE.md stays: it is text the reviewer reads. The
# skills, agents and commands go to reviewer_hide_claude_skills, for Claude.
#
# Run after the vendor cache is unpacked, so a file an archive brought goes too.
# Marked skip-worktree where the repository tracks it, so the removal is no change
# in `git status` or `git diff`; where git is locked, it stays a removal.
reviewer_drop_project_settings() {
  for path in .claude/settings.json .claude/settings.local.json .codex .pi; do
    if [ -e "$path" ] || [ -L "$path" ]; then
      rm -rf -- "$path"
      git ls-files -z -- "$path" 2> /dev/null | xargs -0 -r git update-index --skip-worktree -- 2> /dev/null || true
      echo "Left out the pull request's $path: the reviewer runs with the review's own settings."
    fi
  done
}

# The pull request's skills, agents and commands, out of where Claude Code
# loads them, into .review-repo-skills/ under the same path, where the reviewer
# reads them as text. A skill Claude loads is more than text: its frontmatter can
# let a command run without asking (`allowed-tools`), set how an agent asks, or
# start hooks and MCP servers, and YAML has more ways to write that than a filter
# can follow. Claude finds them under every .claude/ in the checkout, also one
# nested in a package, so all of those go, and those in the reviewer's own home,
# which the pull request's code may have written in an earlier step. Moved as
# they are: a link stays a link, and nothing is read or written through it.
#
# For Claude only: Codex and Pi read skills as text already, and Codex reaches the
# repository's through links prepare-review.sh made to .claude/skills/.
REVIEWER_REPO_SKILLS=.review-repo-skills

reviewer_hide_claude_skills() {
  hidden=$(
    find . \( -path ./.git -o -path "./$REVIEWER_REPO_SKILLS" \) -prune -o \
      \( -path '*/.claude/skills' -o -path '*/.claude/agents' -o -path '*/.claude/commands' \) -print -prune 2> /dev/null \
      | while IFS= read -r path; do
          path=${path#./}
          git ls-files -z -- "$path" 2> /dev/null | xargs -0 -r git update-index --skip-worktree -- 2> /dev/null || true
          mkdir -p "$REVIEWER_REPO_SKILLS/$(dirname "$path")" && mv -- "$path" "$REVIEWER_REPO_SKILLS/$path" && echo "$path"
        done | wc -l | tr -d ' '
  )
  EXCLUDE=$(git rev-parse --git-path info/exclude 2> /dev/null) && mkdir -p "$(dirname "$EXCLUDE")" \
    && { grep -qxF "/$REVIEWER_REPO_SKILLS/" "$EXCLUDE" 2> /dev/null || echo "/$REVIEWER_REPO_SKILLS/" >> "$EXCLUDE"; }
  [ "$hidden" -eq 0 ] || echo "Moved the pull request's Claude skills, agents and commands ($hidden folders) to $REVIEWER_REPO_SKILLS/: the reviewer reads them there, as text."

  for home in "${CLAUDE_CONFIG_DIR:-}" "$HOME/.claude"; do
    [ -n "$home" ] || continue
    for path in "$home/skills" "$home/agents" "$home/commands"; do
      if [ -e "$path" ] || [ -L "$path" ]; then
        rm -rf -- "$path"
        echo "Removed $path: the review brings no skills of its own there, so these came from a pull request's code."
      fi
    done
  done
}

# The parts of the review the team turned off (REVIEW_OCR, REVIEW_FLOOR_GUARD,
# REVIEW_SCAN), said to the reviewer after the instructions. The instructions say it
# too, through settings in review-context.json, but a platform's saved copy of them
# may be from before those settings, and OCR is in the image whatever is set. A
# runner appends what this prints to its prompt; nothing is printed when all are on.
reviewer_module_notes() {
  _off=""
  [ "${REVIEW_OCR:-true}" = "false" ] && _off="$_off
- OpenCodeReview is not part of this review. Do not run \`ocr\` in any form. Review every file the range changed, against the repository's rules and the stack guides, with each file's diff from \`git diff\`."
  [ "${REVIEW_FLOOR_GUARD:-true}" = "false" ] && _off="$_off
- The floor guard is not part of this review. Do not run floor-guard.sh; look for loosened checks in the diff as you read it."
  [ "${REVIEW_SCAN:-true}" = "false" ] && _off="$_off
- The scan did not run, by the team's choice: there is no review-scan.md to read."
  [ -n "$_off" ] || return 0
  printf '\nTHIS RUN:\n\nThe team turned these parts of the review off. This holds over anything the instructions above say about them, and their absence is not mentioned in the summary.%s\n' "$_off"
}
