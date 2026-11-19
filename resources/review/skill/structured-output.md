STRUCTURED OUTPUT:

This run takes the report as structured output. Where this skill says to write `ai-review.json`, return the same object as your structured output instead, and write no file: the step writes it from what you return. Print the review as Phase 2 says before you return the report: the report is the last thing you answer.

Where this skill says to stop with the skipped report, return that object: `{"skipped": true, "summary": "<why, in one line>", "result": "PASSED", "findings": []}`. Nothing is published for it.
