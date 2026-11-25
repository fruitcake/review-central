
SCOPE AND CHECKLISTS (OpenCodeReview):

The `ocr` CLI decides which files you review and which checklist applies to each. Run it in delegation mode before you review any file, as step 2 of START ANALYSIS; never `ocr review`, since you do the review yourself.

1. `ocr delegate preview --from "origin/$BITBUCKET_PR_DESTINATION_BRANCH" --to "$BITBUCKET_COMMIT"` prints the merge base, the reviewable files, and the excluded ones with a reason.
2. `ocr delegate rule <path1> <path2> ...` with every reviewable path prints the checklists, grouped by rule.
3. Each file's diff: `git diff <merge_base>..$BITBUCKET_COMMIT -- <path>`, or from `<sha>` on a follow-up.
4. Review each file against its checklist together with `CLAUDE.md` and the skills; where they conflict, those and this prompt win. The checklists name OCR's own tools, which are not here: use your own reading and searching tools, and `git diff` for `file_read_diff`.

Files OCR excludes are not reviewed for defects of their own, except a file the stack guide calls code. If `ocr` fails, say so in one line in the summary and review the full diff without it.
