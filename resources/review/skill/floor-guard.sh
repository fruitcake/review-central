#!/bin/sh
# Lists every place a change made a check easier to pass.
#
# An agent that hits a red check takes the cheapest road to green: a skipped test, a
# suppression, an assertion that quietly went away, an empty catch, a baseline that
# grew. None of those is always wrong, and this script does not decide whether one
# is. It makes each one visible, so that loosening the bar is loud and has to be
# explained, while tightening it stays silent.
#
#   sh floor-guard.sh [base]
#
# Compares the working tree, untracked files included, against <base>. Without one
# it uses the merge base with the remote's default branch, and falls back to HEAD,
# which is the uncommitted work only.
#
# Prints one line per hit, as `path:line: kind: the line`, and exits
#   0  nothing found
#   1  something to explain
#   2  the script could not run (not a repository, unknown base)
# A 2 is never a pass: whatever called this has to treat it as "not checked", not
# as "nothing found".
#
# POSIX sh, git and awk; no PHP, so it runs in any image and on any PHP version.

set -u

if ! git rev-parse --git-dir > /dev/null 2>&1; then
  echo "floor-guard: not a git repository" >&2
  exit 2
fi

base=${1:-}

if [ -z "$base" ]; then
  default=$(git symbolic-ref --quiet --short refs/remotes/origin/HEAD 2> /dev/null)

  for ref in $default origin/main origin/master origin/develop; do
    if found=$(git merge-base HEAD "$ref" 2> /dev/null); then
      base=$found
      break
    fi
  done

  [ -n "$base" ] || base=HEAD
fi

if ! git rev-parse --verify --quiet "$base^{commit}" > /dev/null; then
  echo "floor-guard: unknown base $base" >&2
  exit 2
fi

{
  git diff --no-color --no-ext-diff -U0 -M "$base" --

  # git diff leaves untracked files out, and a new file is exactly where a fresh
  # suppression or a skipped test would otherwise go unseen.
  git ls-files --others --exclude-standard | while IFS= read -r file; do
    git diff --no-color --no-ext-diff --no-index -U0 -- /dev/null "$file"
  done
} | awk '
  function is_test(path) {
    return path ~ /(^|\/)tests?\// || path ~ /(^|\/)Test\/(Unit|Integration|Functional|Api|Mftf)\// || path ~ /Test\.php$/ || path ~ /(^|\/)__tests__\// || path ~ /\.(test|spec)\.[jt]sx?$/
  }

  function is_php(path) {
    # Drupal keeps PHP in .module, .install, .theme and .inc files; Magento templates are .phtml.
    return path ~ /\.(php|phtml|module|install|theme|inc|profile)$/
  }

  function is_js(path) {
    return path ~ /\.(js|jsx|ts|tsx|vue)$/
  }

  function is_baseline(path) {
    return path ~ /(^|\/)(phpstan-baseline\.neon|psalm-baseline\.xml)$/ || path ~ /baseline.*\.neon$/
  }

  function is_tool_config(path) {
    return path ~ /(^|\/)(phpstan[^\/]*\.neon(\.dist)?|phpunit\.xml(\.dist)?|psalm\.xml(\.dist)?|pint\.json|\.php-cs-fixer[^\/]*|\.?phpcs\.xml(\.dist)?|phpmd\.xml(\.dist)?|grumphp\.yml(\.dist)?|rector\.php|\.eslintrc[^\/]*|eslint\.config\.[a-z]+)$/
  }

  function is_assertion(text) {
    return text ~ /assert[A-Z(]|->assert|expect\(|expectException|shouldReceive|shouldHaveReceived|->to(Be|Equal|Have|Contain|Throw|Match)|\.to(Be|Equal|Have|Contain|Throw|Match)/
  }

  # An assertion that compares a literal with itself, so no change to the code can
  # make it fail. Counting assertion lines cannot see this: swapping a real one for
  # `expect(true)->toBeTrue()` leaves the count where it was, which is exactly the
  # shape of a coverage floor being lowered on the quiet.
  function is_vacuous_assertion(text) {
    # PHPUnit: assertTrue(true), assertFalse(false), assertNull(null), and the
    # explicit no-op.
    if (text ~ /assert(True|NotFalse)[ \t]*\([ \t]*true[ \t]*[,)]/) return 1
    if (text ~ /assert(False|NotTrue)[ \t]*\([ \t]*false[ \t]*[,)]/) return 1
    if (text ~ /assertNull[ \t]*\([ \t]*null[ \t]*[,)]/) return 1
    if (text ~ /expectNotToPerformAssertions[ \t]*\(/) return 1

    # PHPUnit: assertSame(1, 1), assertEquals('a', 'a') — the same literal twice.
    if (text ~ /assert(Same|Equals)[ \t]*\([ \t]*(true|false|null|-?[0-9]+)[ \t]*,[ \t]*(true|false|null|-?[0-9]+)[ \t]*\)/) {
      if (literal_repeated(text)) return 1
    }

    # Pest: expect(true)->toBeTrue(), expect(1)->toBe(1).
    if (text ~ /expect[ \t]*\([ \t]*true[ \t]*\)[ \t]*->[ \t]*toBeTrue[ \t]*\(/) return 1
    if (text ~ /expect[ \t]*\([ \t]*false[ \t]*\)[ \t]*->[ \t]*toBeFalse[ \t]*\(/) return 1
    if (text ~ /expect[ \t]*\([ \t]*null[ \t]*\)[ \t]*->[ \t]*toBeNull[ \t]*\(/) return 1
    if (text ~ /expect[ \t]*\([ \t]*(true|false|null|-?[0-9]+)[ \t]*\)[ \t]*->[ \t]*toBe[ \t]*\([ \t]*(true|false|null|-?[0-9]+)[ \t]*\)/) {
      if (literal_repeated(text)) return 1
    }

    return 0
  }

  # Whether the two literals in a two-argument assertion are the same one. awk has
  # no back-references, so they are pulled out and compared.
  function literal_repeated(text,   body, left, right) {
    body = text
    sub(/^[^(]*\(/, "", body)
    gsub(/[ \t]/, "", body)

    if (body ~ /^[^,]+,[^,)]+\)/) {
      left = body
      sub(/,.*$/, "", left)
      right = body
      sub(/^[^,]*,/, "", right)
      sub(/\).*$/, "", right)
      sub(/->.*$/, "", right)

      return left == right && left != ""
    }

    # `expect(x)->toBe(y)`: the two literals sit in separate calls.
    left = text
    sub(/^[^(]*\([ \t]*/, "", left)
    sub(/[ \t]*\).*$/, "", left)
    right = text
    sub(/^.*->[ \t]*toBe[ \t]*\([ \t]*/, "", right)
    sub(/[ \t]*\).*$/, "", right)

    return left == right && left != ""
  }

  function report(line, kind, text) {
    gsub(/^[ \t]+|[ \t]+$/, "", text)
    if (length(text) > 120) text = substr(text, 1, 117) "..."
    printf "%s:%s: %s: %s\n", file, line, kind, text
    hits++
  }

  /^diff --git / || /^diff --no-index / {
    in_hunk = 0
    old = ""
    file = ""
    pending_catch = 0
    next
  }

  !in_hunk && /^--- / {
    old = substr($0, 5)
    sub(/^a\//, "", old)
    next
  }

  !in_hunk && /^\+\+\+ / {
    file = substr($0, 5)
    sub(/^b\//, "", file)

    if (file == "/dev/null") {
      file = old
      deleted[file] = 1
      if (is_test(file)) report(0, "deleted-test", "the whole test file was removed")
    }

    seen[file] = 1
    next
  }

  /^@@ / {
    in_hunk = 1
    split($3, range, ",")
    line = substr(range[1], 2) + 0
    pending_catch = 0
    next
  }

  !in_hunk { next }

  /^-/ {
    text = substr($0, 2)
    if (is_test(file) && is_assertion(text)) removed_asserts[file]++
    if (is_baseline(file)) removed_baseline[file]++
    next
  }

  /^\+/ {
    text = substr($0, 2)

    if (pending_catch && line == pending_catch + 1 && text ~ /^[ \t]*\}/) {
      report(pending_catch, "empty-catch", "catch block with nothing in it")
    }
    pending_catch = 0

    if (text ~ /markTestSkipped|markTestIncomplete/) report(line, "skip", text)
    else if (is_test(file) && text ~ /->(skip|todo)\(|(^|[^A-Za-z])(xit|xdescribe|xtest)\(|(it|test|describe)\.(skip|todo)\(/) report(line, "skip", text)

    if (text ~ /@phpstan-ignore|@psalm-suppress|@codeCoverageIgnore|phpcs:(ignore|disable)|@codingStandardsIgnore|@noinspection|eslint-disable|@ts-ignore|@ts-expect-error/) report(line, "suppression", text)

    if (text ~ /catch[ \t]*\([^)]*\)[ \t]*\{[ \t]*\}/) report(line, "empty-catch", text)
    else if (text ~ /catch[ \t]*\([^)]*\)[ \t]*\{[ \t]*$/) pending_catch = line

    if (!is_test(file) && is_php(file) && text ~ /(^|[^A-Za-z0-9_>:$])(dd|ddd|dump|ray|var_dump|dpm|dsm|dvm|kint)\(|->(dd|dump)\(\)|@(dd|dump)\(|Zend_Debug::dump\(/) report(line, "debug", text)
    if (file ~ /\.twig$/ && text ~ /\{\{-?[ \t]*(dump|kint)\(/) report(line, "debug", text)
    if (!is_test(file) && is_js(file) && text ~ /console\.(log|debug)\(|(^|[^A-Za-z])debugger;/) report(line, "debug", text)

    if (text ~ /(^|[^A-Za-z])(TODO|FIXME|HACK)([^A-Za-z]|$)/) report(line, "todo", text)

    if (is_test(file)) {
      if (is_assertion(text)) added_asserts[file]++

      # Checked on its own rather than inside the branch above, because the two ask
      # different questions. `expectNotToPerformAssertions()` is not an assertion by
      # any reading — counting it would hide a real one being removed alongside it —
      # and it is exactly the line worth reporting.
      #
      # Reported on the line rather than counted at the end: the point is which
      # assertion cannot fail, not how many there are.
      if (is_vacuous_assertion(text)) report(line, "assertions", "this assertion cannot fail; assert what the code produces")
    }
    if (is_baseline(file)) added_baseline[file]++
    else if (is_tool_config(file) && !(file in config_reported)) {
      config_reported[file] = 1
      report(line, "config", "tool configuration changed; check it does not loosen a rule")
    }

    line++
    next
  }

  END {
    for (f in seen) {
      file = f

      if (!(f in deleted) && removed_asserts[f] > added_asserts[f]) {
        report(0, "assertions", (removed_asserts[f] - added_asserts[f]) " fewer assertion lines than before")
      }

      if (added_baseline[f] > removed_baseline[f]) {
        report(0, "baseline", "grew by " (added_baseline[f] - removed_baseline[f]) " lines")
      }
    }

    exit hits > 0 ? 1 : 0
  }
'
