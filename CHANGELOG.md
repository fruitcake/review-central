# Changelog

What changed in each release of Review Central, and what to do when updating to it. A release is named after the day it came out, `2026.11.19`, and tagged on GitHub under that name; see [Updating](https://review-central.com/guide/updating) for the steps every update takes.

## Unreleased

The first public release.

- Reviews of pull requests on GitHub and Bitbucket Cloud, posted as a summary, a comment per finding, and an approval or a request for changes; on Bitbucket also tasks for what blocks the merge. A GitHub connection takes a personal access token; without one, public repositories are only read.
- Reviewers: Claude Code, Codex, OpenCodeReview, Pi in trials, or the diff alone sent to the AI provider.
- Runners: the diff, Docker with gVisor on your own server, and Cloudflare sandboxes (experimental).
- Tickets from Jira, Asana and GitHub Issues as context.
- Pull requests from forks: reviewed through GitHub's pull ref when a tenant turns that on, never by themselves on Bitbucket.
- Many tenants on one installation, each with its own connections, budget, currency, time zone and colours.
