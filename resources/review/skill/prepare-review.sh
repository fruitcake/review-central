#!/bin/sh
# Everything the review step does before the agent starts, for any agent.
#
# Sourced, not run: `. prepare-review.sh`. It exports the settings the agent
# reads, and a subshell would take those with it when it exited. For the
# same reason nothing in here calls `exit` or sets -e — either would end the step.
#
# It is reviewer-agnostic on purpose: every reviewer runs in the same step, on the
# same repository prepared the same way, and differs only in what run-review.sh
# starts.

# Each part marks where it starts ("##[phase] <name>"), so how long it took is
# measured: review-central turns the line into a step of the run.
echo "##[phase] prepare"

# The config file for the reviewer the precheck chose.
if [ -z "${REVIEW_AGENT_CONFIG:-}" ] && [ -n "${REVIEW_AGENT:-}" ]; then
  HERE=.review/skill
  . "$HERE/reviewer.sh"
  REVIEW_AGENT_CONFIG=$(reviewer_config "$REVIEW_AGENT")
  export REVIEW_AGENT_CONFIG
fi

# Where the scripts are mounted read-only (the container runner), the config is
# copied to where the model, effort and permissions below can be written into it.
case "${REVIEW_AGENT_CONFIG:-}" in *.json|*.toml) REVIEW_AGENT_CONFIG_IS_SETTINGS=true ;; *) REVIEW_AGENT_CONFIG_IS_SETTINGS=false ;; esac
if [ "$REVIEW_AGENT_CONFIG_IS_SETTINGS" = "true" ] && [ -f "$REVIEW_AGENT_CONFIG" ] && [ ! -w "$REVIEW_AGENT_CONFIG" ]; then
  REVIEW_AGENT_CONFIG_COPY="${TMPDIR:-/tmp}/review-agent-config.${REVIEW_AGENT_CONFIG##*.}"
  if cp "$REVIEW_AGENT_CONFIG" "$REVIEW_AGENT_CONFIG_COPY" 2> /dev/null && chmod u+w "$REVIEW_AGENT_CONFIG_COPY"; then
    REVIEW_AGENT_CONFIG=$REVIEW_AGENT_CONFIG_COPY
    export REVIEW_AGENT_CONFIG
  else
    echo "The reviewer's config is read-only and could not be copied; the model and effort it ships with apply."
  fi
fi

echo "AI review (${REVIEW_AGENT:-claude-code})"

# The model, effort and advisor the review server set in the precheck, where this repository
# sets no variable of its own (review-settings.sh). Applied before anything below
# reads them, so the review runs on what the precheck recorded.
if [ -s review-context.json ]; then
  . .review/skill/review-settings.sh
  review_settings_apply "$(jq -c '.settings.server_variables // {}' review-context.json 2> /dev/null || echo '{}')"
fi
echo "Settings, where the review server sets none the reviewer's own defaults:"
echo "  REVIEW_MODE=${REVIEW_MODE:-both} (both | report | comment)"
echo "  REVIEW_CREATE_TASKS=${REVIEW_CREATE_TASKS:-true} (a PR task per HIGH finding)"
echo "  REVIEW_DRAFTS=${REVIEW_DRAFTS:-false} (review draft pull requests)"
echo "  REVIEW_FAIL_ON=${REVIEW_FAIL_ON:-default} (never | any | medium | high, then per category: medium,security:any,maintainability:never) - blocks the build AND marks the report FAILED"
echo "  REVIEW_PHP_VERSION=${REVIEW_PHP_VERSION:-from composer.json} (PHP the step installs)"
echo "  CODEX_REVIEW_MODEL=${CODEX_REVIEW_MODEL:-from the agent config} CODEX_REVIEW_EFFORT=${CODEX_REVIEW_EFFORT:-from the agent config}"
echo "  PI_REVIEW_MODEL=${PI_REVIEW_MODEL:-by provider} PI_REVIEW_EFFORT=${PI_REVIEW_EFFORT:-Pi's default} PI_REVIEW_PROVIDER=${PI_REVIEW_PROVIDER:-from the model or the keys}"
echo "  CLAUDE_REVIEW_MODEL=${CLAUDE_REVIEW_MODEL:-the runtime default} CLAUDE_REVIEW_EFFORT=${CLAUDE_REVIEW_EFFORT:-the runtime default} CLAUDE_REVIEW_ADVISOR=${CLAUDE_REVIEW_ADVISOR:-none} CLAUDE_REVIEW_MAX_BUDGET=${CLAUDE_REVIEW_MAX_BUDGET:-10}"
echo "  OCR_REVIEW_MODEL=${OCR_REVIEW_MODEL:-by provider} OCR_REVIEW_EFFORT=${OCR_REVIEW_EFFORT:-medium} OCR_REVIEW_PROVIDER=${OCR_REVIEW_PROVIDER:-from the model or the keys}"
echo "  REVIEW_STACK=${REVIEW_STACK:-auto} (auto | none | laravel, magento, hyva, drupal - which stack guides the review reads)"
echo "  REVIEW_VERIFY=${REVIEW_VERIFY:-false} REVIEW_VERIFY_MODEL=${REVIEW_VERIFY_MODEL:-sonnet for Claude, the review model for Codex} (a second check on each finding that would block)"
echo "  REVIEW_EXTRA_PASSES=${REVIEW_EXTRA_PASSES:-off for Claude, on for Codex} (the reviewer's own review first, for candidates)"
echo "  REVIEW_OCR=${REVIEW_OCR:-true} REVIEW_SCAN=${REVIEW_SCAN:-true} REVIEW_FLOOR_GUARD=${REVIEW_FLOOR_GUARD:-true} (OpenCodeReview picks the files, the scan before the review, the floor guard)"

# A trigger into a custom pipeline sets no PR variables; the precheck resolved them
# and passed them on, so fill them in here for anything that reads the usual names.
export BITBUCKET_PR_ID="${BITBUCKET_PR_ID:-${REVIEW_PR_ID:-}}"
export BITBUCKET_PR_DESTINATION_BRANCH="${BITBUCKET_PR_DESTINATION_BRANCH:-${REVIEW_DESTINATION_BRANCH:-}}"
echo "Pull request ${BITBUCKET_PR_ID:-unknown}, destination ${BITBUCKET_PR_DESTINATION_BRANCH:-unknown}"

# A custom run is one the review-custom pipeline started, the only place that sets
# REVIEW_CUSTOM_PROVIDER. Worked out here as the precheck does, not handed on as an
# output variable, so the pipeline file does not have to declare it.
REVIEW_CUSTOM=false
[ -n "${REVIEW_CUSTOM_PROVIDER:-}" ] && REVIEW_CUSTOM=true
export REVIEW_CUSTOM

# How the review behaves; a repository variable overrides each default.
export REVIEW_CREATE_TASKS="${REVIEW_CREATE_TASKS:-true}"
export REVIEW_MODE="${REVIEW_MODE:-both}"
export REVIEW_DRAFTS="${REVIEW_DRAFTS:-false}"
# Unset means the default threshold, which blocking.jq keeps.
export REVIEW_FAIL_ON="${REVIEW_FAIL_ON:-}"

# Xdebug ships enabled in this image, in mode develop, and slows every php call the
# agent makes.
export XDEBUG_MODE=off

# The precheck step decided, gathered and locked; its files arrive here as
# artifacts, so this step makes no call to the review server at all.
if [ -s review-context.json ]; then
  echo "Reviewing pull request $(jq -r '.pull_request.id // "?"' review-context.json) from the precheck's context."
else
  echo "No review-context.json; the precheck should have failed the build, so the review has nothing to read the pull request from."
fi

# The stacks the review reads a guide for (stack-<name>.md). The precheck
# settled them into the context, and the review reads them there; they are taken
# from it here so the log and run-ocr.sh agree with what the review was told.
# Detected again only when the context has none, from a precheck older than this.
REVIEW_STACKS=$(jq -r 'if (.settings.stack | type) == "array" then .settings.stack | join(" ") else error("no stack in the context") end' review-context.json 2>/dev/null) \
  || REVIEW_STACKS=$(sh .review/skill/detect-stack.sh 2>/dev/null) \
  || REVIEW_STACKS=""
export REVIEW_STACKS
echo "Stack: ${REVIEW_STACKS:-none}"

# The review's guidance and the stack guides the precheck wrote beside the context.
# Only those: anything else in the directory came with the checkout, from the pull
# request under review, and is not the review's to read. Without a context there is
# nothing to review anyway; the review stops on that.
if [ -s review-context.json ]; then
  LISTED=$(jq -r '(.instructions.files // [])[].file' review-context.json 2>/dev/null) || LISTED=""
  for path in .review-instructions/* .review-instructions/.[!.]*; do
    [ -e "$path" ] || continue
    printf '%s\n' "$LISTED" | grep -qxF "${path#.review-instructions/}" && continue
    echo "Removing $path: the context does not list it."
    rm -rf "$path"
  done
  # Written again from the copy the skill ships with when they did not arrive - a
  # precheck older than them, or an artifact that was not kept - so the review never
  # starts without its method.
  if [ ! -f .review-instructions/method.md ]; then
    if ! sh .review/skill/instructions.sh review-context.json .review-instructions; then
      echo "No review instructions, and the review cannot run without them."
      exit 1
    fi
  fi
fi
echo "Instructions: $(jq -r '.instructions | if . == null then "none" elif .source == "server" then "version \(.version) from the review server" else "the copy that ships with the skill" end' review-context.json 2>/dev/null) ($(ls .review-instructions 2>/dev/null | tr '\n' ' '))"

# Skills a stack's own maintainers publish, fetched for the stack that needs them.
# The stack guide tells the reviewer to read them from .review-skills/<name>/.
#
# Pinned to a commit, not a branch: the file is part of what the reviewer is told,
# so it changes when this line changes and not when someone pushes upstream. Cached under the
# tools directory by that commit. Each one was read before it was pinned; read the
# new version before moving a pin, because it goes straight into the reviewer's
# context. Only reference material: a skill that tells an agent to edit files, run
# commands or ask the user does not belong in a review.
#
#   stack  name                    source (raw URL at the pinned commit)
EXTERNAL_SKILLS='
hyva hyva-alpine-component https://raw.githubusercontent.com/hyva-themes/hyva-ai-tools/a28a3333bcfae05dcba07e88f38a5ff9579903c7/skills/hyva-alpine-component/SKILL.md
'
printf '%s\n' "$EXTERNAL_SKILLS" | while read -r skill_stack skill_name skill_url; do
  [ -n "$skill_url" ] || continue
  case " $REVIEW_STACKS " in *" $skill_stack "*) ;; *) continue ;; esac
  skill_cache="$HOME/.tools/review-skills/$(printf '%s' "$skill_url" | cksum | cut -d' ' -f1)/SKILL.md"
  if [ ! -s "$skill_cache" ]; then
    mkdir -p "$(dirname "$skill_cache")"
    curl -fsSL -m 30 -o "$skill_cache.tmp" "$skill_url" && mv "$skill_cache.tmp" "$skill_cache" || rm -f "$skill_cache.tmp"
  fi
  if [ -s "$skill_cache" ]; then
    mkdir -p ".review-skills/$skill_name"
    cp "$skill_cache" ".review-skills/$skill_name/SKILL.md"
    echo "  Stack skill: .review-skills/$skill_name (for $skill_stack)"
  else
    echo "  Could not fetch the $skill_name skill; the review goes ahead with the stack guide alone."
  fi
done
# Not part of the change under review: the floor guard lists untracked files as new
# ones, and the review's own input and output are neither. Without this a ticket's
# "todo" or a transcript's "suppression" came back as a loosened check.
EXCLUDE=$(git rev-parse --git-path info/exclude 2> /dev/null) && mkdir -p "$(dirname "$EXCLUDE")" \
  && for file in /.review/ /.review-variables /.review-skills/ /.review-instructions/ /review-context.json /review-ticket.md /ai-review.json /ai-usage.json \
                 /claude-review.jsonl /codex-review.jsonl /codex-review-candidates.md /ocr-review.json /pi-review.jsonl \
                 /ocr-background.md /ocr-rules.json /review-scan.md /.review-started /.review-verdict /.claude-exit /.codex-exit /.pi-exit; do
       grep -qxF "$file" "$EXCLUDE" 2> /dev/null || echo "$file" >> "$EXCLUDE"
     done

# Codex finds a repository's skills in .agents/skills/, not in .claude/skills/,
# and follows a symlinked skill folder. codex-prompt.md already sends it to the
# review's own files by path; linking every skill there as well lets Codex load
# the stack's skills - laravel-best-practices, hyva-alpine-component - the way
# Claude does, rather than only when the prompt names them. Done here, in a clone
# that is thrown away, and not by the installers: a project keeps one copy, in
# .claude/skills/. A skill the repository already has in .agents/skills/ is left
# as it is.
case "${REVIEW_AGENT:-claude-code}" in
  codex-cli)
    for skill_dir in .claude/skills/*/ .review-skills/*/; do
      [ -f "${skill_dir}SKILL.md" ] || continue
      skill_name=$(basename "$skill_dir")
      [ -e ".agents/skills/$skill_name" ] && continue
      mkdir -p .agents/skills
      ln -s "../../${skill_dir%/}" ".agents/skills/$skill_name"
      [ -d .git/info ] && echo "/.agents/skills/$skill_name" >> .git/info/exclude
    done
    [ -d .agents/skills ] && echo "  Codex skills: $(ls .agents/skills | wc -l | tr -d ' ') in .agents/skills"
    ;;
esac

# A trigger into a custom pipeline sets no PR variables, so the destination branch
# comes from the context the precheck wrote. Without it there is no merge base and
# the agent has nothing to diff against.
DEST_BRANCH="${BITBUCKET_PR_DESTINATION_BRANCH:-}"
if [ -z "$DEST_BRANCH" ] && [ -s review-context.json ]; then
  DEST_BRANCH=$(jq -r '.pull_request.destination_branch // empty' review-context.json 2>/dev/null) || DEST_BRANCH=""
fi
if [ -n "$DEST_BRANCH" ]; then
  echo "Destination branch: $DEST_BRANCH"
  DEST="origin/$DEST_BRANCH"
  git rev-parse --verify "$DEST" > /dev/null 2>&1 || git fetch origin "+refs/heads/${DEST_BRANCH}:refs/remotes/${DEST}"
  # --unshallow errors on an already-complete clone (a repo with under 100
  # commits), which would otherwise kill the step.
  git merge-base "$DEST" HEAD > /dev/null 2>&1 \
    || git fetch --unshallow origin "+refs/heads/${DEST_BRANCH}:refs/remotes/${DEST}" \
    || echo "Could not deepen the clone; the review works from what it has."
else
  echo "No destination branch known; the agent resolves it itself."
fi

# Model and reasoning effort as ordinary repository or workspace variables, one
# pair per agent: CODEX_REVIEW_MODEL/EFFORT and CLAUDE_REVIEW_MODEL/EFFORT. A
# workspace variable sets it for every repository at once; a repository variable
# beats that for one repository.
#
# Bitbucket substitutes nothing inside an agent's config block, so these cannot
# reach the agent the way REVIEW_MODE and the rest do. What they can do is rewrite
# the file the agent is about to be configured from, which runs before the
# `agent:` directive and is exactly what this does.
case "${REVIEW_AGENT:-claude-code}" in
  codex-cli)
    AGENT_MODEL="${CODEX_REVIEW_MODEL:-}"
    AGENT_EFFORT="${CODEX_REVIEW_EFFORT:-}"
    ;;
  claude-code)
    AGENT_MODEL="${CLAUDE_REVIEW_MODEL:-}"
    AGENT_EFFORT="${CLAUDE_REVIEW_EFFORT:-}"
    ;;
  *)
    AGENT_MODEL=""
    AGENT_EFFORT=""
    ;;
esac
# Claude only: a stronger model Claude consults at the moments that decide a review,
# such as whether a candidate holds and the check before the report. Claude Code's
# advisorModel setting; see https://code.claude.com/docs/en/advisor.
AGENT_ADVISOR=""
case "${REVIEW_AGENT:-claude-code}" in
  claude-code) AGENT_ADVISOR="${CLAUDE_REVIEW_ADVISOR:-}" ;;
esac

# What the test pipeline asked for wins, in a custom run only, as in the precheck.
# run-ocr.sh reads the same variables itself, since OCR has no config to write.
if [ "${REVIEW_CUSTOM:-false}" = "true" ]; then
  case "${REVIEW_CUSTOM_MODEL:-}" in ''|default) ;; *) AGENT_MODEL=$REVIEW_CUSTOM_MODEL ;; esac
  case "${REVIEW_CUSTOM_EFFORT:-}" in ''|default) ;; *) AGENT_EFFORT=$REVIEW_CUSTOM_EFFORT ;; esac
  case "${REVIEW_CUSTOM_VERIFY:-}" in true|false) export REVIEW_VERIFY=$REVIEW_CUSTOM_VERIFY ;; esac
  echo "Custom run: model=${AGENT_MODEL:-from the config} effort=${AGENT_EFFORT:-from the config} verify=${REVIEW_VERIFY:-false}, a fresh review of the whole pull request"
fi
# "none" means no advisor.
[ "$AGENT_ADVISOR" = none ] && AGENT_ADVISOR=""

# codex-cli is the same Codex, run as a CLI rather than as an agent, and reads
# the same config file.
case "${REVIEW_AGENT:-claude-code}" in
  codex-cli) IS_CODEX=true ;;
  *)                  IS_CODEX=false ;;
esac

# xhigh is the highest effort the review takes. Codex has no max, and Claude Code
# drops "max" from a settings file without a word and reviews at its default,
# medium, while the run is recorded as max. A max left in a repository variable is
# therefore read as xhigh, for every reviewer.
if [ "$AGENT_EFFORT" = "max" ]; then
  echo "Effort max is not one the review takes; using xhigh."
  AGENT_EFFORT=xhigh
fi

# Codex: written at the top of the file rather than appended - the config ends with
# an [mcp_servers.…] table, and a bare key after a table header belongs to that
# table. Any existing value is dropped first, so setting it twice is not an error.
# The model pattern cannot match model_reasoning_effort: `model` has to be followed
# by whitespace or `=`, not an underscore.
if [ "$IS_CODEX" = "true" ] && [ -f "${REVIEW_AGENT_CONFIG:-}" ] && [ -n "$AGENT_MODEL$AGENT_EFFORT" ]; then
  if { [ -z "$AGENT_MODEL" ] || printf 'model = "%s"\n' "$AGENT_MODEL"
       [ -z "$AGENT_EFFORT" ] || printf 'model_reasoning_effort = "%s"\n' "$AGENT_EFFORT"
       sed -E \
         ${AGENT_MODEL:+-e '/^[[:space:]]*model[[:space:]]*=/d'} \
         ${AGENT_EFFORT:+-e '/^[[:space:]]*model_reasoning_effort[[:space:]]*=/d'} \
         "$REVIEW_AGENT_CONFIG"
     } > "$REVIEW_AGENT_CONFIG.tmp"; then
    mv "$REVIEW_AGENT_CONFIG.tmp" "$REVIEW_AGENT_CONFIG"
    echo "Codex model=${AGENT_MODEL:-<unchanged>} effort=${AGENT_EFFORT:-<unchanged>} written into $REVIEW_AGENT_CONFIG."
  else
    rm -f "$REVIEW_AGENT_CONFIG.tmp"
    echo "Could not write the Codex model or effort; the config file's own values apply."
  fi
fi

# Claude: claude-config.json is Claude Code's settings file, where the keys are
# `model` and `effortLevel`. Both are left out by default, and deliberately: a
# pinned model can be retired or renamed under a running installation, so setting
# CLAUDE_REVIEW_MODEL is a choice to watch the next run for.
# claude-code reads the same file through `claude --settings`, and its CLI is
# current, so there a pinned model is simply the model.
case "${REVIEW_AGENT:-claude-code}" in
  claude-code) IS_CLAUDE=true ;;
  *)                    IS_CLAUDE=false ;;
esac
if [ "$IS_CLAUDE" = "true" ] && [ -f "${REVIEW_AGENT_CONFIG:-}" ] && [ -n "$AGENT_MODEL$AGENT_EFFORT$AGENT_ADVISOR" ]; then
  if jq --arg model "$AGENT_MODEL" --arg effort "$AGENT_EFFORT" --arg advisor "$AGENT_ADVISOR" '
       (if $model == "" then . else .model = $model end)
       | (if $effort == "" then . else .effortLevel = $effort end)
       | (if $advisor == "" then . else .advisorModel = $advisor end)
     ' "$REVIEW_AGENT_CONFIG" > "$REVIEW_AGENT_CONFIG.tmp"; then
    mv "$REVIEW_AGENT_CONFIG.tmp" "$REVIEW_AGENT_CONFIG"
    echo "Claude model=${AGENT_MODEL:-<unchanged>} effort=${AGENT_EFFORT:-<unchanged>} advisor=${AGENT_ADVISOR:-<unchanged>} written into $REVIEW_AGENT_CONFIG."
  else
    rm -f "$REVIEW_AGENT_CONFIG.tmp"
    echo "Could not write the Claude model, effort or advisor; the config file's own values apply."
  fi
fi

# No subagents on the review server. The pr-review-toolkit agents, /code-review
# and a subagent per candidate each read the change and the code around it again
# from scratch, which can cost many times what a review in one context does,
# without finding more. The reviewer checks each candidate itself instead, as SKILL.md
# step 4 allows. A developer's own run, outside the review server, does not
# come through here and keeps them. A team that wants them anyway, at that cost,
# turns on "Extra passes" (REVIEW_EXTRA_PASSES=true).
if [ "$IS_CLAUDE" = "true" ] && [ "${REVIEW_EXTRA_PASSES:-false}" = "true" ]; then
  echo "Extra passes are on: Claude may run the pr-review-toolkit agents and /code-review, where the image has them."
elif [ "$IS_CLAUDE" = "true" ] && [ -f "${REVIEW_AGENT_CONFIG:-}" ]; then
  if jq '
       del(.enabledPlugins)
       | .permissions.allow |= map(select(. != "Task" and . != "Agent"))
       | .permissions.deny = (["Task", "Agent", "Workflow", "Skill(code-review)"] + .permissions.deny | unique)
     ' "$REVIEW_AGENT_CONFIG" > "$REVIEW_AGENT_CONFIG.tmp"; then
    mv "$REVIEW_AGENT_CONFIG.tmp" "$REVIEW_AGENT_CONFIG"
    echo "Claude reviews without subagents: no pr-review-toolkit, no /code-review, no Task or Workflow."
  else
    rm -f "$REVIEW_AGENT_CONFIG.tmp"
    echo "Could not turn the subagents off; the config file's own permissions apply."
  fi
fi

# Then say what the agent is actually about to be configured with, read back out of
# the file `config.path` points at rather than from whatever this step believes it
# wrote. Without this the log says nothing at all when the effort comes from the
# committed config, which is the ordinary case - and "is it really running at medium?"
# is not a question a build log should leave to inference.
if [ "$IS_CODEX" = "true" ] && [ -f "${REVIEW_AGENT_CONFIG:-}" ]; then
  EFFECTIVE_EFFORT=$(grep -m1 -E '^[[:space:]]*model_reasoning_effort[[:space:]]*=' "$REVIEW_AGENT_CONFIG" \
    | sed -E 's/^[^=]*=[[:space:]]*"?([^"]*)"?.*/\1/') || EFFECTIVE_EFFORT=""
  EFFECTIVE_MODEL=$(grep -m1 -E '^[[:space:]]*model[[:space:]]*=' "$REVIEW_AGENT_CONFIG" \
    | sed -E 's/^[^=]*=[[:space:]]*"?([^"]*)"?.*/\1/') || EFFECTIVE_MODEL=""
  echo "Codex will run as: model=${EFFECTIVE_MODEL:-<the runtime default>} reasoning_effort=${EFFECTIVE_EFFORT:-<the runtime default>} (from $REVIEW_AGENT_CONFIG)"
fi

# The image carries PHP 5.6 to 8.5; pick the one this project uses, so the reviewer
# parses its syntax. REVIEW_PHP_VERSION wins, otherwise composer.json says it
# (config.platform.php, else the require entry).
REVIEW_PHP="${REVIEW_PHP_VERSION:-}"
if [ -z "$REVIEW_PHP" ] && [ -f composer.json ]; then
  REVIEW_PHP=$(jq -r '.config.platform.php // .require.php // ""' composer.json | grep -oE '[0-9]+\.[0-9]+' | head -1)
fi
if [ -n "$REVIEW_PHP" ] && [ -x "/usr/bin/php${REVIEW_PHP}" ]; then
  # The image's default is root's to change. A step that runs as another user, as
  # in the container runner, puts that PHP first on the PATH instead, where the
  # reviewer and Composer find it just the same.
  if [ "$(id -u)" = "0" ] && update-alternatives --set php "/usr/bin/php${REVIEW_PHP}"; then
    :
  elif mkdir -p "$HOME/.tools/bin" && ln -sf "/usr/bin/php${REVIEW_PHP}" "$HOME/.tools/bin/php"; then
    export PATH="$HOME/.tools/bin:$PATH"
  else
    echo "Could not select PHP $REVIEW_PHP; using the default."
  fi
elif [ -n "$REVIEW_PHP" ]; then
  echo "PHP $REVIEW_PHP is not in this image; using the default."
fi
php --version


# Composer is the only other tool the pipeline's image does not carry. Downloaded
# to a file, not piped: a failed curl would otherwise feed php an empty installer
# and exit 0. Only where it is missing: the container runner's image has it, and a
# developer's machine running the step locally keeps its own. Next to the other
# tools where /usr/local/bin is not this user's to write.
if ! command -v composer > /dev/null 2>&1; then
  COMPOSER_DIR=/usr/local/bin
  if [ ! -w "$COMPOSER_DIR" ]; then
    COMPOSER_DIR="$HOME/.tools/bin"
    mkdir -p "$COMPOSER_DIR"
    export PATH="$COMPOSER_DIR:$PATH"
  fi
  COMPOSER_SETUP=$(mktemp)
  # -f: an error page is a failure, never something php runs. Checked against
  # the signature Composer publishes, as sandbox-setup.sh does.
  curl -fsSL -o "$COMPOSER_SETUP" https://getcomposer.org/installer
  COMPOSER_SIG=$(curl -fsSL https://composer.github.io/installer.sig)
  if [ "$(php -r "echo hash_file('sha384', '$COMPOSER_SETUP');")" != "$COMPOSER_SIG" ]; then
    rm -f "$COMPOSER_SETUP"
    echo "The Composer installer does not match its signature."
    exit 1
  fi
  php "$COMPOSER_SETUP" --install-dir="$COMPOSER_DIR" --filename=composer
  rm -f "$COMPOSER_SETUP"
fi

# Bitbucket's predefined composer cache is ~/.composer/cache, but Composer as root
# with no COMPOSER_HOME writes to ~/.cache/composer, so the cache stayed empty and
# every run re-downloaded every package.
export COMPOSER_HOME="$HOME/.composer"

# vendor/ is there for the reviewer to read the framework's and the packages' code,
# as production has them:
# --no-dev: what the application runs with, and a smaller install. The test tools
# are not what a change is judged against.
# --no-scripts and --no-plugins: Composer runs nothing of the pull request. A
# script or a plugin is code the pull request can name in its own composer.json,
# and this step holds the AI provider's key.
# No --optimize-autoloader: a classmap over app + vendor buys runtime speed the
# review never uses.
#
# The installed vendor/ is kept beside Composer's own cache, under the hash of
# composer.json and composer.lock, where the step is given a cache directory. The
# next review with the same lock unpacks it instead of installing. Written before
# the reviewer starts, and the three newest are kept.
#
# Magento's packages come from repo.magento.com and Hyvä's from a private Packagist,
# and neither installs without credentials. Composer reads them from COMPOSER_AUTH,
# the contents of an auth.json, which a repository sets as a secured variable. A
# failed install is not the end of the review - it reads the repository without
# vendor/ - but it is a weaker one, so the log says what would fix it. The variable
# is dropped as soon as Composer is done: the reviewer reads text from the pull
# request and has no use for it.
if [ -f composer.json ]; then
  # Not for a repository that commits anything under vendor/: those files are the
  # pull request's, and kept with the install they would be unpacked into the
  # reviews of other pull requests with the same lock, as though they were a
  # package's own code.
  COMMITTED_VENDOR=$(git ls-files vendor 2> /dev/null | head -n 1)
  VENDOR_TAR=""
  VENDOR_BASE=""
  if [ -n "$COMMITTED_VENDOR" ]; then
    echo "This repository commits files under vendor/, so vendor/ is installed and not kept in the cache."
  elif [ -n "${REVIEW_VENDOR_BASE:-}" ]; then
    # In a Cloudflare sandbox: one vendor/ per repository, the destination
    # branch's, which the Worker brings when it has one. Composer then installs
    # only what this pull request's lock changes.
    VENDOR_BASE="$REVIEW_VENDOR_BASE"
  elif [ -n "${COMPOSER_CACHE_DIR:-}" ] && [ -d "$COMPOSER_CACHE_DIR" ] && [ -w "$COMPOSER_CACHE_DIR" ] && [ -f composer.lock ]; then
    # The flags are part of what was installed, so they are part of the name.
    # "2": an archive from before committed vendor files were kept out may hold
    # some, and is not unpacked again. It goes as the newer ones replace it.
    VENDOR_TAR="$COMPOSER_CACHE_DIR/vendor-nodev2-$(cat composer.json composer.lock | sha256sum | cut -c1-32).tar"
  fi

  # The step says whether it starts from a vendor/ that was kept: an install from
  # nothing takes many times longer, so the figures of the two are kept apart.
  if { [ -n "$VENDOR_BASE" ] && [ -f "$VENDOR_BASE" ]; } || { [ -n "$VENDOR_TAR" ] && [ -f "$VENDOR_TAR" ]; }; then
    echo "##[phase] composer cached"
  else
    echo "##[phase] composer fresh"
  fi

  # Only vendor/ comes out of an archive: one is written from inside a review's
  # sandbox, and anything else in it would land in the checkout of every review
  # that unpacks it, settings for the reviewer's CLI included.
  if [ -n "$VENDOR_BASE" ] && [ -f "$VENDOR_BASE" ] && rm -rf vendor && tar -xf "$VENDOR_BASE" vendor; then
    echo "vendor/ of the destination branch unpacked; Composer installs what this pull request changes."
  fi

  if [ -n "$VENDOR_TAR" ] && [ -f "$VENDOR_TAR" ] && rm -rf vendor && tar -xf "$VENDOR_TAR" vendor; then
    # Its last use, which is what keeps it among the newest.
    touch "$VENDOR_TAR"
    echo "vendor/ unpacked from the cache for this composer.lock."
  elif ! composer install --no-interaction --no-progress --prefer-dist --no-dev --no-scripts --no-plugins --ignore-platform-reqs; then
    echo "composer install failed, so vendor/ is incomplete and the review cannot read the framework's code."
    if [ -z "${COMPOSER_AUTH:-}" ] && grep -Eq 'repo\.magento\.com|hyva-themes|repo\.packagist\.com' composer.json; then
      echo "This project installs from a private repository (repo.magento.com, Hyvä's Packagist), which needs the contents of its auth.json as COMPOSER_AUTH."
    fi
  elif [ -n "$VENDOR_BASE" ]; then
    # Kept for the next review only when this lock is the destination branch's,
    # so what is kept is the destination's vendor/ and never a pull request's
    # own packages; and only when it is not what was unpacked. Written before
    # the reviewer starts; the Worker takes it from beside the base after the step.
    LOCK_SUM=$(sha256sum composer.lock 2> /dev/null | cut -c1-64)
    DEST_SUM=$(git show "${DEST:-none}:composer.lock" 2> /dev/null | sha256sum | cut -c1-64)
    BASE_SUM=$(cat vendor/.review-base-lock 2> /dev/null || true)
    if [ -n "$LOCK_SUM" ] && [ -n "${DEST:-}" ] && [ "$LOCK_SUM" = "$DEST_SUM" ] && [ "$LOCK_SUM" != "$BASE_SUM" ]; then
      printf '%s' "$LOCK_SUM" > vendor/.review-base-lock
      if tar -cf "$VENDOR_BASE.new" vendor 2> /dev/null; then
        echo "vendor/ of the destination branch kept for the next review of this repository."
      else
        rm -f "$VENDOR_BASE.new"
      fi
    fi
  elif [ -n "$VENDOR_TAR" ]; then
    # Written beside it and moved into place, so a review that unpacks it at that
    # moment never reads half of one.
    if tar -cf "$VENDOR_TAR.$$" vendor 2> /dev/null && mv "$VENDOR_TAR.$$" "$VENDOR_TAR"; then
      echo "vendor/ kept in the cache for this composer.lock."
      ls -t "$COMPOSER_CACHE_DIR"/vendor-*.tar 2> /dev/null | tail -n +4 | while read -r old; do rm -f "$old"; done
    else
      rm -f "$VENDOR_TAR.$$"
    fi
  fi
else
  echo "##[phase] composer"
  echo "No composer.json; nothing to install."
fi

# Plain checks over the change, before the reviewer starts: secrets in added lines
# and composer audit (scan-change.sh). Here, where there is network and before
# COMPOSER_AUTH goes, so the audit can ask a private repository too. The reviewer
# reads review-scan.md as candidates. The whole pull request, from its merge base with
# the destination, as the floor guard does on a first review. A review-scan.md the
# pull request brought along is removed first: the reviewer trusts the file as the
# step's.
rm -f review-scan.md
echo "##[phase] scan"
if [ "${REVIEW_SCAN:-true}" = "false" ]; then
  echo "Scan: turned off for this review (REVIEW_SCAN=false)."
elif [ -n "$DEST_BRANCH" ]; then
  sh .review/skill/scan-change.sh "origin/$DEST_BRANCH" "${BITBUCKET_COMMIT:-HEAD}" review-scan.md
else
  echo "Scan: no destination branch known; skipped."
fi
unset COMPOSER_AUTH

# OCR runs in delegation mode: it selects files and resolves review rules, the agent
# does the review. No OCR LLM config is needed. With REVIEW_OCR=false the agent
# reviews without it, and it is not installed, unless OCR is the reviewer itself.
#
# Pinned to a release series, not a release: the newest 1.12.x, so fixes arrive
# without an edit here and a 1.13 waits until someone has tried it. npm says which
# release that is, and the cached copy is replaced only when it is not that one -
# the cache survives every run, so checking only that ocr exists would keep the
# first copy it ever installed. When npm cannot be asked, the cached copy is used.
echo "##[phase] tools"
export PATH="$HOME/.tools/node_modules/.bin:$PATH"
OCR_SERIES=1.12
if [ "${REVIEW_OCR:-true}" = "false" ] && [ "${REVIEW_AGENT:-claude-code}" != "ocr" ]; then
  echo "  OpenCodeReview: turned off for this review (REVIEW_OCR=false)."
else
  # `ocr --version` prints "open-code-review v1.12.12 (182898c) linux/amd64".
  OCR_INSTALLED=$(ocr --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
  OCR_LATEST=$(npm view "@alibaba-group/open-code-review@~${OCR_SERIES}" version --json 2>/dev/null \
    | jq -r 'if type == "array" then .[-1] else . end' 2>/dev/null) || OCR_LATEST=""
  case "$OCR_LATEST" in "$OCR_SERIES".*) ;; *) OCR_LATEST="" ;; esac

  if [ -n "$OCR_LATEST" ] && [ "$OCR_INSTALLED" != "$OCR_LATEST" ]; then
    npm install --prefix "$HOME/.tools" "@alibaba-group/open-code-review@${OCR_LATEST}"
  elif [ -z "$OCR_INSTALLED" ]; then
    npm install --prefix "$HOME/.tools" "@alibaba-group/open-code-review@~${OCR_SERIES}"
  fi
  echo "  OpenCodeReview: $(ocr --version 2>/dev/null | head -1 || echo "not installed")"
fi

# ripgrep, which the agents reach for first: Codex searches with rg, and without it
# every search starts with "rg: command not found". Never fatal - the agents fall
# back to grep - but a review searches the codebase constantly.
#
# The reviewer's image carries this release (docker/reviewer/Dockerfile), so there
# is nothing to do. Where the image has another one, or none, the static musl build
# of the release is downloaded into the tools directory, pinned and compared like
# OCR above.
export PATH="$HOME/.tools/bin:$PATH"
RG_VERSION=15.2.0
if [ "$(rg --version 2>/dev/null | head -1 | cut -d' ' -f2)" != "$RG_VERSION" ]; then
  mkdir -p "$HOME/.tools/bin"
  curl -fsSL "https://github.com/BurntSushi/ripgrep/releases/download/${RG_VERSION}/ripgrep-${RG_VERSION}-x86_64-unknown-linux-musl.tar.gz" \
    | tar -xz -C "$HOME/.tools/bin" --strip-components=1 "ripgrep-${RG_VERSION}-x86_64-unknown-linux-musl/rg" \
    || true
fi
command -v rg > /dev/null 2>&1 \
  && echo "  ripgrep: $(rg --version | head -1)" \
  || echo "  ripgrep: could not be installed; the review falls back to grep."

# Marks the moment the agent starts, so only transcripts from this run are counted
# - a warm image can carry older sessions.
touch .review-started
