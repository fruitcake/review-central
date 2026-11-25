
EXTRA PASSES:

The team turned on extra passes for this review. In step 2 of START ANALYSIS, where your runtime has them:

- Run the `pr-review-toolkit` subagents `code-reviewer`, `silent-failure-hunter` and `pr-test-analyzer` in parallel on the reviewable files. Give each the base commit, `$BITBUCKET_COMMIT` and the file list; tell them to follow a flow out of that list wherever the other side of an agreement lives, and to report findings only, never edit or post.
- Run the `code-review` skill once on the same range: `/code-review --max-findings all <base>...$BITBUCKET_COMMIT`, or `<sha>..$BITBUCKET_COMMIT` on a follow-up. Never pass it `--comment` or `--fix`.

What they find are candidates, never findings of their own: they go through the same filtering and verification. Where they are not available, go on without them.
