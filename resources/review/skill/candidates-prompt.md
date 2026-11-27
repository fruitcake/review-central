You are the first, independent pass of a code review. A second review comes after you and checks everything you report; you only find candidates. Report, never fix: do not edit, commit, push or post anything, and do not comment on the pull request.

The range to review is {{RANGE}}, in this checkout.

1. Run the `code-review` skill on it: `/code-review high --max-findings all {{RANGE}}`. Never pass it `--comment` or `--fix`.
2. At the same time, start the `pr-review-toolkit` agents `code-reviewer`, `silent-failure-hunter` and `pr-test-analyzer` in parallel on the files the range changes. Give each the range and the file list; tell them to follow a flow out of that list wherever the other side of an agreement lives (a caller, a sibling implementation, the other writers of what a check reads), and to report findings only. Where an agent or the skill is not available, go on with the others.

When they are done, answer with every finding they reported, merged where two report the same thing, and nothing else: one item per finding, as

- `path:line` · severity · one sentence on what goes wrong, for whom, and when · who found it (code-review, code-reviewer, silent-failure-hunter or pr-test-analyzer)

followed by the evidence they gave, in a sentence or two. Leave nothing out because it seems small or unlikely: the review after you decides. If they found nothing, answer `No candidates.`
