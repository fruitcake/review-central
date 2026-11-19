# Changelog

What changed in each release of Review Central, and what to do when updating to it. Releases are tagged on GitHub; see [Updating](https://review-central.com/guide/updating) for the steps every update takes.

## Unreleased

## 1.0.0

The first public release.

- Reviews of pull requests on GitHub and Bitbucket Cloud, posted as a summary, a comment per finding, and an approval or a request for changes; on Bitbucket also tasks for what blocks the merge. A GitHub connection takes a personal access token; without one, public repositories are only read.
- Reviewers: Claude Code, Codex, OpenCodeReview, Pi in trials, or the diff alone sent to the AI provider.
- Runners: the diff, Docker with gVisor on your own server, and Cloudflare sandboxes (experimental).
- Tickets from Jira, Asana and GitHub Issues as context.
- Many tenants on one installation, each with its own connections, budget, currency, time zone and colours.
