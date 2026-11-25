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
# loads them: each .claude/skills, .claude/agents and .claude/commands is renamed
# where it is, to skills.as-text and so on, where the reviewer reads it as text. A
# skill Claude loads is more than text: its frontmatter can let a command run
# without asking (`allowed-tools`), set how an agent asks, or start hooks and MCP
# servers, and YAML has more ways to write that than a filter can follow. Claude
# finds them under every .claude/ in the checkout, also one nested in a package,
# and through a .claude that is a link, which is renamed to .claude.as-text. A
# rename in the same folder keeps every relative link in it pointing where it
# did, and moves a link as a link, never through it; find hands over each path
# whole, whatever its name holds. Whatever already has a new name is removed
# first, so it is not moved into.
#
# Then those in the reviewer's own home go, which the pull request's code may
# have written in an earlier step: the review puts none there. When one is left
# where Claude loads it, this fails, and the caller stops before Claude starts.
#
# For Claude only: Codex and Pi read skills as text already, and Codex reaches the
# repository's through links prepare-review.sh made to .claude/skills/.
reviewer_claude_skill_paths() {
  find . -path ./.git -prune -o \
    \( -path '*/.claude/skills' -o -path '*/.claude/agents' -o -path '*/.claude/commands' -o \( -name .claude -type l \) \) -prune -print 2> /dev/null
}

reviewer_hide_claude_skills() {
  moved=$(
    find . -path ./.git -prune -o \
      \( -path '*/.claude/skills' -o -path '*/.claude/agents' -o -path '*/.claude/commands' -o \( -name .claude -type l \) \) -prune \
      -exec sh -c '
        for path do
          git ls-files -z -- "$path" "$path.as-text" 2> /dev/null | xargs -0 -r git update-index --skip-worktree -- 2> /dev/null || true
          rm -rf -- "$path.as-text" && mv -- "$path" "$path.as-text" && echo moved || exit 1
        done
      ' sh {} + 2> /dev/null
  ) || true
  moved=$(printf '%s' "$moved" | grep -c moved || true)
  # Not new files to git: the renamed ones are the same, kept out of `git status`.
  EXCLUDE=$(git rev-parse --git-path info/exclude 2> /dev/null) && mkdir -p "$(dirname "$EXCLUDE")" \
    && for pattern in '**/.claude/skills.as-text/' '**/.claude/agents.as-text/' '**/.claude/commands.as-text/' '**/.claude.as-text'; do
         grep -qxF "$pattern" "$EXCLUDE" 2> /dev/null || echo "$pattern" >> "$EXCLUDE"
       done

  for home in "${CLAUDE_CONFIG_DIR:-}" "$HOME/.claude"; do
    [ -n "$home" ] || continue
    for path in "$home/skills" "$home/agents" "$home/commands"; do
      if [ -e "$path" ] || [ -L "$path" ]; then
        rm -rf -- "$path" && echo "Removed $path: the review brings no skills of its own there, so these came from a pull request's code."
      fi
    done
  done

  if [ -n "$(reviewer_claude_skill_paths | head -n 1)" ]; then
    echo "The pull request's Claude skills, agents or commands could not all be moved out of where Claude loads them; not reviewing with them in place."
    return 1
  fi
  for home in "${CLAUDE_CONFIG_DIR:-}" "$HOME/.claude"; do
    if [ -n "$home" ] && { [ -e "$home/skills" ] || [ -e "$home/agents" ] || [ -e "$home/commands" ]; }; then
      echo "The skills, agents or commands in $home could not be removed; not reviewing with them in place."
      return 1
    fi
  done
  [ "$moved" -eq 0 ] || echo "Renamed the pull request's Claude skills, agents and commands ($moved folders) to skills.as-text, agents.as-text and commands.as-text: the reviewer reads them there, as text."
}

# The parts of the review the team turned off (REVIEW_OCR, REVIEW_FLOOR_GUARD,
# REVIEW_SCAN), said to the reviewer after the instructions. The instructions say it
# too, through settings in review-context.json, but a platform's saved copy of them
# may be from before those settings, and OCR is in the image whatever is set. A
# runner appends what this prints to its prompt; nothing is printed when all are on.
# The steps of SKILL.md that only some runs have, added to the prompt after it
# only on a run they apply to: every line of the prompt is read again on every
# turn of the review. Each holds for its run alone, whatever an older copy of
# the instructions says. Run in the review's working directory; $1 is the
# reviewer, $2 the Claude settings file (for its advisor).
reviewer_prompt_parts() {
  _parts="$HERE/parts"
  _context="review-context.json"

  # A run that reviews as though nobody had is a first review, whatever is on the pull request.
  if [ -s "$_context" ] && [ "$(jq -r 'if .ignored_existing != null then "first"
       elif .last_reviewed_commit != null or .handover != null or ((.findings // []) | length) > 0 then "follow-up"
       else "first" end' "$_context" 2>/dev/null)" = "follow-up" ]; then
    cat "$_parts/follow-up.md"
  fi

  if [ -s review-ticket.md ] && [ ! -L review-ticket.md ]; then
    cat "$_parts/ticket.md"
  fi

  if [ "${REVIEW_OCR:-true}" != "false" ]; then
    cat "$_parts/ocr.md"
  fi

  if [ -s review-scan.md ] && [ ! -L review-scan.md ]; then
    cat "$_parts/scan.md"
  fi

  # Claude's own subagents and skill; Codex runs its own pass before the review instead.
  if [ "${1:-}" = "claude-code" ] && [ "${REVIEW_EXTRA_PASSES:-false}" = "true" ]; then
    cat "$_parts/extra-passes.md"
  fi

  if [ "${1:-}" = "claude-code" ] && [ -n "${2:-}" ] && [ -s "$2" ] && [ "$(jq -r '.advisorModel // ""' "$2" 2>/dev/null)" != "" ]; then
    cat "$_parts/advisor.md"
  fi
}

# The same steps for a reviewer that reads SKILL.md as a file rather than being
# handed it: said to belong to it, and left out where the file it reads still
# holds them.
reviewer_skill_parts() {
  _skill=.review-instructions/skill.md
  { [ -s "$_skill" ] && [ ! -L "$_skill" ]; } || _skill="$HERE/SKILL.md"
  grep -q '^FOLLOW-UP ROUNDS:' "$_skill" && return 0
  _steps=$(reviewer_prompt_parts "$1")
  [ -n "$_steps" ] || return 0
  printf '\nThese steps of SKILL.md apply to this run, and are part of it:\n%s\n' "$_steps"
}

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
