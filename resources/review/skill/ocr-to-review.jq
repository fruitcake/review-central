# OpenCodeReview's JSON output, turned into ai-review.json. run-ocr.sh runs it as
#
#   jq -n -L <this directory> --slurpfile raw ocr-review.json --slurpfile context review-context.json \
#         --argjson state '{"F1": {"path": "app/A.php", "gone": false, "present": true, "unique": true, "touched": false}}' \
#         --argjson changed '{"app/A.php": [[10, 12]]}' \
#         --arg commit <sha> --arg model <model> --arg range full|follow-up \
#         --arg fail_on medium -f ocr-to-review.jq
#
# $state says, per earlier finding, where its file is now, whether that file is gone,
# whether the code the finding rests on is still in it and occurs there only once,
# and whether its lines were touched since the last review. $changed is the lines
# this pull request changed, per file, on the new side. run-ocr.sh works all of it
# out with git, so this file only decides.
#
# A finding is the same finding again when OCR reports it on the same code in the
# same file, wherever that code has moved to - the matching OCR's own `session
# compare` does, on the snippet rather than the line number. The code is the
# finding's `existing_code`, or its one-line `anchor` when it has nothing more.
# Within five lines of its old place is the fallback, used only where one side has
# no code to compare: a finding with none, or an OCR comment that quotes none.

include "blocking";

def num: if type == "number" then . elif type == "string" then (tonumber? // null) else null end;
def abs: if . < 0 then -. else . end;
def trim: sub("^\\s+"; "") | sub("\\s+$"; "");

# ai-review.json knows three severities; OCR has four.
def severity:
  ((. // "") | ascii_downcase) as $s
  | if $s == "critical" or $s == "high" then "HIGH"
    elif $s == "low" then "LOW"
    else "MEDIUM" end;

# bug and other are the default, correctness, which is left out.
def category:
  ((. // "") | ascii_downcase) as $c
  | if $c == "security" or $c == "performance" then $c
    elif $c == "maintainability" or $c == "style" or $c == "documentation" then "maintainability"
    elif $c == "test" or $c == "tests" then "tests"
    else null end;

def title:
  gsub("\\s+"; " ") | trim
  | (capture("^(?<s>.{1,160}?[.!?])(\\s|$)").s
     // (if length > 120 then .[0:117] + "..." else . end));

def compact: with_entries(select(.value != null and .value != ""));

# Code as the lines that say something: trimmed, blank ones dropped, so re-indenting
# or reflowing around it does not make it different code.
def code_lines: (. // "") | tostring | split("\n") | map(trim) | map(select(. != ""));

# Whether $needle occurs in $hay as a run of consecutive lines.
def has_run($hay; $needle):
  ($needle | length) as $n
  | $n > 0 and ($hay | length) >= $n
    and any(range(0; ($hay | length) - $n + 1); $hay[.:(. + $n)] == $needle);

# The code a finding rests on, as OCR quoted it: its own lines, without the blank
# ones around them, and no longer than a finding needs.
def snippet: (. // "") | tostring | split("\n") | map(sub("\\s+$"; ""))
  | (until(length == 0 or (first | test("\\S")); .[1:]))
  | (until(length == 0 or (last | test("\\S")); .[:-1]))
  | .[0:30] | join("\n");

$context[0] as $ctx
| $raw[0] as $ocr
| ($ctx.comments // []) as $comments
| ($ctx.settings.fail_on // $fail_on | fail_on_spec) as $threshold

# The files OCR actually reviewed this run. A follow-up review only looks at what
# changed since the last one, so silence about a finding in a file it never opened
# says nothing. Unknown when OCR does not list them, and then silence is never read
# as anything.
| (if ($ocr.groups | type) == "array" then [$ocr.groups[] | (.files // [])[]] else null end) as $reviewed

# OCR's comments, in one shape. A start line of 0 is OCR saying it could not place
# the comment, so it has no line.
| [($ocr.comments // [])[]
    | {
        path: ((.path // "") | tostring),
        line: ((.start_line // 0) | num | if . == null or . <= 0 then null else . end),
        end: ((.end_line // 0) | num | if . == null or . <= 0 then null else . end),
        content: ((.content // "") | tostring | trim),
        existing: ((.existing_code // "") | tostring),
        suggestion: ((.suggestion_code // "") | tostring),
        severity: (.severity | severity),
        category: (.category | category)
      }
    | select(.content != "")
  ] as $new

# Whether a comment is about code this pull request changed: its lines, or lines
# within five of them. One further away, or in a file the pull request did not touch,
# is about code that was already like this - worth saying, and not this change's to
# hold. Unknown (null, which counts as introduced) when the comment has no line.
| def introduced($c):
    if $c.path == "" or $c.line == null or ($changed | type) != "object" then null
    else ($changed[$c.path] // []) as $ranges
      | ([$c.line, ($c.end // $c.line)] | [min, max]) as $own
      | any($ranges[]; .[0] <= $own[1] + 5 and .[1] >= $own[0] - 5)
    end;

# An OCR comment as a finding. A suggestion becomes Bitbucket's Apply button, which
# replaces the one line the comment sits on, so only a single-line replacement is one;
# a longer one is shown in the details instead.
  def finding($c):
    ($c.line != null and ($c.end == null or $c.end <= $c.line) and ($c.suggestion | trim) != "") as $applies
    | ($c.content | title) as $title
    | {
        severity: $c.severity,
        path: (if $c.path == "" then null else $c.path end),
        line: $c.line,
        end_line: (if $c.line != null and $c.end != null and $c.end > $c.line then $c.end else null end),
        title: $title,
        anchor: ($c.existing | split("\n") | map(select(test("\\S"))) | (first // "") | trim),
        existing_code: ($c.existing | snippet),
        introduced: introduced($c),
        # The rest of what OCR said; the title is already the first sentence of it.
        details: ((if ($c.content | startswith($title)) then $c.content[($title | length):] | trim else $c.content end)
          + (if $applies or ($c.suggestion | trim) == "" then ""
             else "\n\nSuggested change:\n\n```\n" + ($c.suggestion | split("\n") | .[0:15] | join("\n")) + "\n```" end)
          | trim),
        category: $c.category,
        suggestion: (if $applies then $c.suggestion else null end),
        prompt: ("In " + (if $c.path == "" then "this change" else $c.path end)
          + (if $c.line != null then " at line " + ($c.line | tostring) else "" end)
          + ": " + ($c.content | gsub("\\s+"; " ")))
      }
    | compact;

  # A previous finding's words, read back out of its own comment, so a finding that
  # stays open is republished as it was written rather than as "Untitled finding".
  def written($f):
    (([$comments[] | select(.id != null and .id == $f.comment_id)] | first // {}) | (.text // "") | tostring) as $t
    | {
        title: ($t | (split("\n")[0] // "") | sub("^[A-Z]+[0-9]+: "; "") | sub("^\\*\\*[^*]*\\*\\* — "; "") | trim),
        # Everything between the title line and the first block that is not prose:
        # the suggestion, the prompt or the marker.
        details: (if ($t | contains("\n\n")) | not then ""
                  else "\n\n" + ($t | sub("^[^\n]*\n\n"; ""))
                       | split("\n\n```")[0] | split("\n\n**Prompt:**")[0] | split("\n\n[//]: #")[0] | trim end),
        prompt: (($t | capture("\\*\\*Prompt:\\*\\*\\n\\n```text\\n(?<p>[\\s\\S]*?)\\n```").p) // null),
        suggestion: (($t | capture("```suggestion\\n(?<s>[\\s\\S]*?)\\n```").s) // null)
      }
    | compact;

  def now($f): ($state[$f.id // ""] // {});
  def path_now($f): (now($f).path // $f.path // "");

  # The code a previous finding rests on: all of it where it was stored, its anchor
  # line otherwise.
  def code($f): (($f.existing_code // "") | code_lines) as $lines
    | if ($lines | length) > 0 then $lines else ($f.anchor | code_lines) end;

  # Trusted only when that code occurs once in the file: a line that repeats, a
  # closing brace or a `return null;`, would match a comment about any of them.
  def unique_anchor($f): now($f).unique == true;

  # The same code. OCR quoting all of it is that finding wherever the comment sits.
  # Quoting only part of it counts only where that code is now, give or take two
  # lines: a single line of it - a `return null;`, a closing brace - can occur all
  # over the file, and only the whole block is known to be unique.
  def anchored($f; $c):
    unique_anchor($f)
    and ($c.path != "") and (path_now($f) == $c.path)
    and (code($f) as $mine | ($c.existing | code_lines) as $theirs
         | has_run($theirs; $mine)
           or (has_run($mine; $theirs)
               and (now($f).at | type) == "array" and $c.line != null
               and $c.line >= now($f).at[0] - 2 and $c.line <= now($f).at[1] + 2));

  # Only when the code cannot decide: a finding with an anchor and a comment that
  # quotes code are the same finding by their code or not at all, or a comment
  # about something else on a nearby line would be taken for it. The code cannot
  # decide when the finding has no anchor, when the comment quotes none, or when
  # the anchor line repeats in the file - then the line number does. An anchor
  # that is gone does decide: that code is not there to be commented on.
  def repeated_anchor($f): now($f).present == true and (unique_anchor($f) | not);

  def near($f; $c):
    ($c.path != "") and (path_now($f) == $c.path)
    and ((code($f) | length) == 0 or ($c.existing | trim) == "" or repeated_anchor($f))
    and (now($f).present != false)
    and (($f.line | num) as $l | $l != null and $c.line != null and (($l - $c.line) | abs) <= 5);

  def same($f; $c): anchored($f; $c) or near($f; $c);

  ($ctx.findings // []) as $prev

  # Every previous finding ends the run with a status; the OCR comments it accounts
  # for are spent and do not come back as new findings.
  | (reduce $prev[] as $f ({used: [], out: []};
      . as $taken
      | (($f.status // "open") | ascii_downcase) as $status
      # Its code, category and origin go out again with it, so the next run has them too.
      | ({id: $f.id, severity: (($f.severity // "MEDIUM") | ascii_upcase), path: path_now($f), line: ($f.line | num),
          end_line: ($f.end_line // null), anchor: ($f.anchor // null), existing_code: ($f.existing_code // null),
          introduced: (if $f.introduced == false then false else null end),
          category: ($f.category // null)} | compact) as $base
      # Where nothing below decides, a finding keeps what it was: open, or no longer
      # reported.
      | (if $status == "unreported" then "unreported" else "open" end) as $kept
      | if ["fixed", "acknowledged", "false_positive", "outdated"] | index($status) != null then
          .out += [$base + written($f) + {status: $status}]
        elif $f.resolved == true then
          .out += [$base + written($f) + {status: "acknowledged"}]
        elif now($f).gone == true then
          .out += [$base + written($f) + {status: "outdated"}]
        else
          # An anchor match first, wherever it is in the file; a nearby line after.
          ((first(range(0; $new | length) | select(. as $i | ($taken.used | index($i)) == null and anchored($f; $new[$i]))) // null)
           // (first(range(0; $new | length) | select(. as $i | ($taken.used | index($i)) == null and near($f; $new[$i]))) // null)) as $match
          | if $match != null then
              .used += [$match] | .out += [finding($new[$match]) + {id: $f.id, status: "open"}]
            # The code it was about is gone, every copy of it: whatever replaced it,
            # that problem is.
            elif now($f).present == false then
              .out += [$base + written($f) + {status: "fixed"}]
            # The code is still there, unchanged, and OCR - asked in its background to
            # report it again while it holds - looked at the file and said nothing.
            # Either it was fixed somewhere else, or OCR missed it; which, a person
            # decides. Until then it is listed, and blocks nothing. Only for code that
            # occurs once; a repeated line may be another copy, and the touched lines
            # decide.
            elif now($f).present == true and unique_anchor($f) then
              if $reviewed != null and ($reviewed | index(path_now($f))) != null then
                .out += [$base + written($f) + {status: "unreported"}]
              else
                .out += [$base + written($f) + {status: $kept}]
              end
            elif now($f).touched == true then
              .out += [$base + written($f) + {status: "fixed"}]
            else
              .out += [$base + written($f) + {status: $kept}]
            end
        end
    )) as $carried

  # What a person settled stays settled: a comment where an acknowledged or
  # false-positive finding sits is that finding again.
  | [$prev[] | select(((.status // "") | ascii_downcase) as $s | $s == "acknowledged" or $s == "false_positive" or .resolved == true)] as $settled
  | ([$prev[] | (.id // "") | capture("(?<n>[0-9]+)$").n | tonumber] | max // 0) as $highest
  | [range(0; $new | length) as $i
      | select(($carried.used | index($i)) == null)
      | $new[$i]
      | select(. as $c | [$settled[] | same(.; $c)] | any | not)
    ] as $fresh
  | [range(0; $fresh | length) as $i | finding($fresh[$i]) + {id: ("F" + (($highest + $i + 1) | tostring)), status: "new"}] as $added

  | ($carried.out + $added) as $findings
  | ([$findings[] | select(.status == "new")] | length) as $n_new
  | ([$findings[] | select(.status == "open")] | length) as $n_open
  | ([$findings[] | select(.status == "fixed")] | length) as $n_fixed
  | ([$findings[] | select(.status == "unreported")] | length) as $n_unreported
  | ($ocr.summary.files_reviewed // 0) as $files
  | {
      result: (if [$findings[] | select(blocks($threshold))] | length > 0 then "FAILED" else "PASSED" end),
      summary: (
        "OpenCodeReview (" + $model + ") reviewed " + ($files | tostring) + " file(s)"
        + (if $range == "follow-up" then " changed since the last review" else " in this pull request" end)
        + ": " + ($n_new | tostring) + " new, " + ($n_open | tostring) + " still open, " + ($n_fixed | tostring) + " fixed"
        + (if $n_unreported > 0 then ", " + ($n_unreported | tostring) + " no longer reported on unchanged code" else "" end) + "."
        + (if ($ocr.status // "") == "skipped" then " " + ($ocr.message // "It found no supported files to review.")
           elif $n_new == 0 and $n_open == 0 then " Nothing it examined held up as a defect."
           else "" end)
        + (if (($ocr.warnings // []) | length) > 0
           then " " + (($ocr.warnings | length) | tostring) + " part(s) of the change could not be reviewed; see the build log."
           else "" end)
      ),
      changes: ($ctx.changes // []),
      before_release: ($ctx.before_release // []),
      # OCR checks rules and cannot judge these; what a review before it left stays.
      manual_review: ($ctx.manual_review // []),
      reviewed: $commit,
      findings: $findings
    }
