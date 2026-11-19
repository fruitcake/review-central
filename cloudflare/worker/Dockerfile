# The reviewer's image for a Cloudflare sandbox, for when the install at the start
# of every review is not wanted. Not used unless wrangler.jsonc names it: see "An
# image of your own" in ../README.md.
#
# Built on the image the worker's Docker runs, which is built first:
#
#   docker build --pull -t review-central/reviewer:latest docker/reviewer
#
# That one starts from a published image with every PHP version and Node in it,
# and adds the reviewers' CLIs. A sandbox cannot pull a published image itself:
# it starts from Cloudflare's own or from one in Cloudflare's registry, which is
# where `wrangler deploy` pushes this.
FROM review-central/reviewer:latest

# The Worker unpacks the checkout as root and runs the reviewer as user 1000, as
# Docker on the worker runs it as an unprivileged user.
USER root
RUN mkdir -p /work /home/reviewer && chown 1000:1000 /work /home/reviewer

# A sandbox stops when its first process ends; the Worker destroys it when the
# review is done.
CMD ["sleep", "infinity"]
