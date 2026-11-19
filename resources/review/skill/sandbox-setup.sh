#!/bin/sh
# Installs what a review needs in a sandbox that starts from a plain Debian image,
# such as Cloudflare's own. Run as root in a fresh sandbox, before any checkout is
# in it; the reviewer's step then runs as user 1000.
#
# The Worker runs this once and keeps a snapshot of the sandbox afterwards, which
# every later review starts from. So this is the place for whatever most reviews
# use: PHP 8.1 to 8.5 and Composer, git and the small tools the scripts call, and
# the reviewers' own CLIs. A review still updates the CLI it runs when a newer one is
# out, as it does in Docker.
#
# A change to this file is a new snapshot: the Worker names it by this file's hash.
# An image that already has these (docker/reviewer) skips what it has.
set -eu

export DEBIAN_FRONTEND=noninteractive

need() {
  for tool in "$@"; do
    command -v "$tool" > /dev/null 2>&1 || return 0
  done
  return 1
}

if need git jq curl unzip xz ps; then
  echo "Installing git and the small tools the review's scripts call."
  apt-get update -qq
  apt-get install -y -qq --no-install-recommends ca-certificates curl git jq unzip xz-utils procps > /dev/null
fi

# PHP in every version a repository here may be on, side by side as php8.1,
# php8.2 and so on, the way the reviewer's Docker image has them. prepare-review.sh
# puts the one composer.json asks for first on the PATH; `php` itself is the
# newest. From Ondřej Surý's packages, which is where Debian's own PHP comes from.
# Only where the image has no PHP: one that has several versions keeps them.
PHP_VERSIONS="8.1 8.2 8.3 8.4 8.5"
if need php composer; then
  echo "Installing PHP ($PHP_VERSIONS) and Composer."
  curl -fsSL -o /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
  CODENAME=$(. /etc/os-release && echo "$VERSION_CODENAME")
  echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $CODENAME main" > /etc/apt/sources.list.d/php.list
  apt-get update -qq

  for version in $PHP_VERSIONS; do
    # A version these packages do not have for this Debian is passed over: the
    # review then runs on the newest, as it does for a version nobody listed.
    apt-get install -y -qq --no-install-recommends \
      "php$version-cli" "php$version-mbstring" "php$version-xml" "php$version-curl" "php$version-zip" \
      "php$version-intl" "php$version-bcmath" "php$version-gd" "php$version-sqlite3" "php$version-mysql" > /dev/null 2>&1 \
      && echo "Installed PHP $version." \
      || echo "PHP $version is not available here; passed over."
  done

  command -v php > /dev/null 2>&1 || { echo "No PHP could be installed."; exit 1; }

  # Composer by its own installer, checked against the hash it publishes: Debian's
  # package would bring Debian's PHP along as a sixth.
  EXPECTED=$(curl -fsSL https://composer.github.io/installer.sig)
  curl -fsSL -o /tmp/composer-setup.php https://getcomposer.org/installer
  [ "$(php -r 'echo hash_file("sha384", "/tmp/composer-setup.php");')" = "$EXPECTED" ] || { echo "Composer's installer does not match its published hash."; exit 1; }
  php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

# The reviewers' CLIs want a current Node. Cloudflare's image has one; on a plain
# Debian, whose own is older than Pi takes, it is installed.
NODE_MAJOR=$(node --version 2> /dev/null | sed -E 's/^v([0-9]+).*/\1/') || NODE_MAJOR=""
if [ -z "$NODE_MAJOR" ] || [ "$NODE_MAJOR" -lt 22 ]; then
  echo "Installing Node 24."
  curl -fsSL https://deb.nodesource.com/setup_24.x | bash - > /dev/null
  apt-get install -y -qq --no-install-recommends nodejs > /dev/null
fi

# A name for user 1000: git and npm ask who they run as.
if ! getent passwd 1000 > /dev/null 2>&1; then
  useradd --uid 1000 --home-dir /home/reviewer --no-create-home --shell /bin/sh reviewer
fi
mkdir -p /home/reviewer
chown 1000:1000 /home/reviewer

# The reviewers' CLIs, for everyone: a review that finds its CLI here installs
# nothing. One that does not install is not in the way of the others, and its own
# script installs it when it is asked for.
for package in @anthropic-ai/claude-code @openai/codex "@alibaba-group/open-code-review@~1.12" "@earendil-works/pi-coding-agent@^1"; do
  npm install --global --no-fund --no-audit "$package" > /dev/null 2>&1 \
    && echo "Installed $package." \
    || echo "Could not install $package; the review that needs it installs it."
done

echo "Sandbox ready: $(php -r 'echo "PHP ".PHP_VERSION;'), Composer $(composer --version 2> /dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1), Node $(node --version), git $(git --version | grep -oE '[0-9.]+' | head -1)."
