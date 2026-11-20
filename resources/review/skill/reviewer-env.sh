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
# extensions, the same way. The skills, agents and CLAUDE.md stay: those are text
# the reviewer reads, not settings it runs.
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
