
THE SCAN:

`review-scan.md` holds plain checks run over the whole pull request before you started: secrets in added lines and Composer advisories. Read it in step 2 of START ANALYSIS; every line is a candidate.

- A secret is a `security` finding once you have read the line and it is a real credential, not a placeholder, an example or a fixture. Anchor it to that line and never repeat the value in any field beyond what the scan shows.
- A Composer advisory for a package this change added or moved is a `security` finding on `composer.lock`, at that package's `"name"` line, with the advisory as evidence; weigh its severity by whether this project reaches the affected code. An advisory for a package that was already there is one line in the `summary`, not a finding. On a follow-up, the previous findings settle what earlier commits brought in.
