#!/usr/bin/env bash
# What someone running Review Central has to do to update to a release, as
# markdown for the top of its notes. Read from what changed since the release
# before: the Worker, the reviewer's image, the default instructions, the
# migrations and .env.example. Then the "**When updating:**" paragraph of each
# pull request labelled `upgrade`, for what the files do not tell.
#
#   .github/release-notes.sh 2026.11.25 [2026.11.24]
#
# Needs git with the tags, and gh signed in for the pull requests.
set -euo pipefail

tag="$1"
previous="${2:-$(git describe --tags --abbrev=0 --match '20[0-9][0-9].*' "${tag}^" 2>/dev/null || true)}"

if [ -z "$previous" ]; then
  echo "## Updating to ${tag}"
  echo
  echo "The first release: follow the [installation](https://github.com/${GITHUB_REPOSITORY:-fruitcake/review-central}/blob/main/docs/guide/installation.md)."
  exit 0
fi

range="${previous}..${tag}"
changed="$(git diff --name-only "$range")"

# The pull requests that changed these paths, as " (#102, #105)".
by() {
  local numbers
  numbers="$(git log --first-parent --merges --format=%s "$range" -- "$@" | sed -nE 's/^Merge pull request #([0-9]+).*/#\1/p' | sort -t'#' -k2 -n -u | paste -sd, - | sed 's/,/, /g')"
  [ -n "$numbers" ] && printf ' (%s)' "$numbers"
}

touched() {
  grep -Eq "$1" <<< "$changed"
}

steps=()

if touched '^cloudflare/worker/'; then
  steps+=("**Deploy the Cloudflare Worker again**, when reviews run in Cloudflare sandboxes: **Deploy the Worker** under Admin → Setup, or \`php artisan review:deploy-worker\`. Until then, sandboxes work as they did$(by cloudflare/worker).")
fi

if touched '^docker/reviewer/'; then
  steps+=("**Rebuild the reviewer's image**, when reviews run on Docker: \`docker build --pull -t review-central/reviewer:latest docker/reviewer\`$(by docker/reviewer).")
fi

if touched '^resources/review/skill/(references/.*\.md|SKILL\.md|security-prompt\.md)$'; then
  steps+=("**Take the new review instructions**: Admin → Setup → **Update from this release** shows each changed file as a diff. A file edited here is not taken over by itself$(by resources/review/skill/references resources/review/skill/SKILL.md resources/review/skill/security-prompt.md).")
fi

new_settings="$(git diff "$range" -- .env.example | sed -nE 's/^\+#? ?([A-Z][A-Z0-9_]+)=.*/\1/p' | sort -u | paste -sd, - | sed 's/,/, /g')"
if [ -n "$new_settings" ]; then
  steps+=("**New settings**, all optional unless a pull request below says otherwise: ${new_settings}. See the [configuration](https://github.com/${GITHUB_REPOSITORY:-fruitcake/review-central}/blob/main/docs/guide/configuration.md)$(by .env.example).")
fi

if git diff --name-only --diff-filter=A "$range" -- database/migrations | grep -q .; then
  steps+=("**Migrations**: \`php artisan migrate --force\` is part of every update, and this one has new ones$(by database/migrations).")
fi

# What a pull request says to do, from its description.
notes=()
for number in $(git log --first-parent --merges --format=%s "$range" | sed -nE 's/^Merge pull request #([0-9]+).*/\1/p' | sort -n -u); do
  pull="$(gh pr view "$number" --json labels,body,title 2>/dev/null || true)"

  if [ -z "$pull" ] || ! jq -e '[.labels[].name] | index("upgrade")' <<< "$pull" > /dev/null; then
    continue
  fi

  # The paragraph that starts with "**When updating:**", without the label.
  when="$(jq -r '.body // ""' <<< "$pull" | tr -d '\r' | awk '/^\*\*When updating:?\*\*:?/ { found = 1 } found && /^[[:space:]]*$/ { exit } found { print }' | sed -E '1s/^\*\*When updating:?\*\*:? *//' | paste -sd' ' -)"
  title="$(jq -r '.title' <<< "$pull")"
  notes+=("${title} (#${number}): ${when:-see the pull request.}")
done

echo "## Updating to ${tag}"
echo
echo "After the usual steps in [Updating](https://github.com/${GITHUB_REPOSITORY:-fruitcake/review-central}/blob/main/docs/guide/updating.md) (\`composer install\`, \`php artisan migrate --force\`, \`php artisan optimize\` and restarting the queue workers):"
echo

if [ ${#steps[@]} -eq 0 ] && [ ${#notes[@]} -eq 0 ]; then
  echo "Nothing else to do."
else
  for step in "${steps[@]}"; do
    echo "- [ ] ${step}"
  done

  for note in "${notes[@]}"; do
    echo "- [ ] ${note}"
  done
fi

echo
echo "Admin → Setup also says what this release still needs on your installation."
