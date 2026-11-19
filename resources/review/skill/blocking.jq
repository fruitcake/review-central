# What holds a merge, in one place. The worker's report step decides the result and the Code
# Insights report with it, ocr-to-review.jq the result an OCR review writes, and the
# review server's ReviewPublisher::blocks() mirrors it for the tasks - three readers of one
# rule, so they cannot disagree about what counts.
#
#   jq -L <this directory> 'include "blocking"; [.findings[] | select(blocks("medium"))]'
#
# REVIEW_FAIL_ON is one threshold for every category, optionally followed by
# per-category ones:
#
#   medium                                  HIGH and MEDIUM block, in every category
#   medium,security:any,maintainability:never
#
# A threshold is never, any (low is the same), medium or high. A category not
# named, and a finding with none, uses the first.

# Security blocks at any severity, because the cost of letting one through is not
# proportional to its label. Maintainability never does: correct code that reads
# badly is worth a comment, not a held merge. Tests block only when HIGH - a
# behaviour change nothing covers that matters.
def default_fail_on: "medium,security:any,tests:high,maintainability:never";

def fail_on_spec: (if . == null then "" else gsub("\\s"; "") end) as $f
  | if $f == "" or $f == "default" then default_fail_on else ($f | ascii_downcase) end;

def fail_on_valid:
  test("^(never|any|low|medium|high)(,(correctness|security|performance|maintainability|tests):(never|any|low|medium|high))*$");

def threshold($fail_on; $category):
  ($fail_on | fail_on_spec | split(",")) as $parts
  | reduce ($parts[1:][] | split(":") | select(length == 2 and .[0] == $category and .[1] != "")) as $p
      ($parts[0]; $p[1]);

def reaches($severity; $threshold):
  (($severity // "") | ascii_upcase) as $s
  | if $threshold == "never" then false
    elif $threshold == "any" or $threshold == "low" then true
    elif $threshold == "high" then $s == "HIGH" or $s == "CRITICAL"
    else $s == "HIGH" or $s == "CRITICAL" or $s == "MEDIUM" end;

# Still open, established (confidence 8 or more, or none stated), introduced by this
# change rather than already there, and severe enough for its category. A finding
# the review no longer reports while its code is unchanged (`unreported`) is not
# open: it may have been fixed elsewhere, and a person settles it.
def blocks($fail_on):
  ((.status // "new") | ascii_downcase) as $status
  | ($status == "new" or $status == "open")
    # A number, or the string the model wrote it as: "5" sorts above 8 in jq.
    and ((.confidence // 10) | if type == "number" then . else (tonumber? // 10) end) >= 8
    and (.introduced != false)
    and reaches(.severity; threshold($fail_on; ((.category // "") | ascii_downcase)));
