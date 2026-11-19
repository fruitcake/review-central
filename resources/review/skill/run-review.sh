#!/bin/sh
# Runs the reviewer the precheck chose, REVIEW_AGENT, in the one AI Review step.
# Run after prepare-review.sh has been sourced. Exits with the reviewer's own exit
# code, so a review that did not run fails the step.
set -u

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
. "$HERE/reviewer.sh"

if ! reviewer_known "${REVIEW_AGENT:-}"; then
  echo "No reviewer to run: REVIEW_AGENT is '${REVIEW_AGENT:-}', not one of $REVIEWERS."
  exit 1
fi

exec sh "$(reviewer_runner "$REVIEW_AGENT")"
