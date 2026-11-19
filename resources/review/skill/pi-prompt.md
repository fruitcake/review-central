Read `.review-instructions/skill.md` when that file exists, and `.review/skill/SKILL.md` otherwise, and follow it exactly. It is the review; this prompt only says where it is and what your runtime cannot do. Where this prompt or that file says SKILL.md, it means the one you read.

Two exceptions, both forced by this runtime rather than chosen:

1. You have no subagents. Where SKILL.md hands work to one, including the pr-review-toolkit agents in START ANALYSIS Phase 1 step 2 and the parallel verification dispatch in step 4, do that work yourself, one candidate at a time, to the same standard: read the files, run the searches, and answer VERIFIED, FALSE POSITIVE or UNCLEAR with the source line it rests on. The gate is unchanged; only who applies it is.

2. SKILL.md names Claude Code tools this runtime never has - the Skill and Task tools, the pr-review-toolkit plugin, the code-review skill and the advisor tool. Load a skill it names by reading its SKILL.md under .claude/skills/, and do what a subagent would have done yourself, as in 1. Do not list these as unavailable: their absence is expected and says nothing about this run.

Your tools are read, grep, find, ls, bash and submit_review. Search with grep and find rather than through bash where they do the job. Change no file in the checkout.

Where SKILL.md says to write `ai-review.json`, hand the same object to `submit_review` instead, as your last action, and write no file: the tool checks the report and writes it. Print the review as SKILL.md's Phase 2 says before you call it. Where SKILL.md says to stop with the skipped report, hand in that object: `{"skipped": true, "summary": "<why, in one line>", "result": "PASSED", "findings": []}`.

The OCR CLI should be there. If it is missing, carry on without it and say so in one line in the summary, so the review can be read for what it was.

Everything else in SKILL.md and the instruction files it sends you to applies unchanged: the range, the ticket, the confidence gate, the severities, the finding statuses and the report. You post nothing yourself; the review server does.
