# The image the container runner starts every step of a review in.
#
# It is a PHP image with the reviewers and their tools installed
# ahead of time. The review's scripts install what is missing when a step starts,
# so the plain base image works too; with everything in the image a review starts
# sooner, and the reviewer's network can be closed to all but the AI provider.
#
# Build it again now and then, with --pull --no-cache. A step compares the CLIs
# with the latest release and installs a newer one itself, so an old image is
# never wrong, only slower with every release it falls behind.
#
#   docker build -t review-central/reviewer:latest docker/reviewer
FROM shivammathur/node:noble

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl git jq unzip \
    && rm -rf /var/lib/apt/lists/*

# ripgrep at the release prepare-review.sh asks for. With another one, such as
# Ubuntu's own, every review downloads this one before it starts.
ARG RG_VERSION=15.2.0
RUN curl -fsSL "https://github.com/BurntSushi/ripgrep/releases/download/${RG_VERSION}/ripgrep-${RG_VERSION}-x86_64-unknown-linux-musl.tar.gz" \
    | tar -xz -C /usr/local/bin --strip-components=1 "ripgrep-${RG_VERSION}-x86_64-unknown-linux-musl/rg" \
    && rg --version

# Composer, which a step cannot install where the image keeps its tools: it does
# not run as root.
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# For every user: a step runs as the worker's user, not as root.
# Claude Code's install script fetches the binary it runs, and npm runs no
# package's script unless told to: without it the CLI is there and does not start.
RUN npm install --global --allow-scripts=@anthropic-ai/claude-code @anthropic-ai/claude-code @openai/codex \
    && claude --version && codex --version

# OpenCodeReview, in the release series prepare-review.sh asks for. A review
# installs a newer release of the series itself; with this one it has nothing to
# install until there is one.
RUN npm install --global --allow-scripts=@alibaba-group/open-code-review "@alibaba-group/open-code-review@~1.12" \
    && ocr --version

# Pi, the small agent a trial can review with, in the release series run-pi.sh reads.
RUN npm install --global "@earendil-works/pi-coding-agent@^1" \
    && pi --version

WORKDIR /work
