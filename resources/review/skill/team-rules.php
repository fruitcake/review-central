<?php

/**
 * Leaves out of the team's rules from Review Central (review-rules.md) every rule
 * limited to paths that no file of this change matches, as REPOSITORY RULES only
 * names the repository's own rule files that apply. A rule keeps its label, R1 or
 * C3, so a finding still cites it as the team knows it; one without paths always
 * stays.
 *
 *   php team-rules.php <instructions directory> <destination ref> <commit>
 *
 * Rewrites each review-rules*.md in place, and leaves it as it is when the change's
 * files cannot be told. Runs with any PHP, without Composer.
 */
require_once __DIR__.'/rule-glob.php';

[$script, $directory, $base, $commit] = array_pad($argv, 4, null);

if ($directory === null || $base === null || $commit === null) {
    fwrite(STDERR, "Usage: php team-rules.php <instructions directory> <destination ref> <commit>\n");
    exit(2);
}

$git = function (array $arguments): ?string {
    $output = shell_exec('git '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>/dev/null; echo "\n$?"');

    if (! is_string($output) || ! preg_match('/\n(\d+)\n$/', $output, $status) || $status[1] !== '0') {
        return null;
    }

    return substr($output, 0, -strlen($status[0]));
};

$mergeBase = trim((string) $git(['merge-base', $base, $commit]));
$listing = $mergeBase === '' ? null : $git(['diff', '-z', '--name-only', "{$mergeBase}...{$commit}"]);

// Without the change's files, nothing can be told about a path: every rule stays.
if ($listing === null) {
    exit(0);
}

$changed = array_values(array_filter(explode("\0", $listing), fn (string $name): bool => $name !== ''));

/** The headings ReviewRules::markdown() writes: a rule's own text may hold headings too. */
const TEAM_RULE_SECTIONS = ['## Check these', '## Not a defect here'];

/** Whether a rule applies: one limited to paths only when a file of this change matches them. */
$applies = function (array $rule) use ($changed): bool {
    // The meta line, when there is one, is the first line after the heading with text.
    foreach (array_slice($rule, 1) as $line) {
        if (trim($line) === '') {
            continue;
        }

        if (! str_starts_with($line, 'Only in: ')) {
            return true;
        }

        preg_match_all('/`([^`]+)`/', explode(' · ', $line)[0], $found);

        return array_filter($changed, fn (string $path): bool => array_filter($found[1], fn (string $glob): bool => review_rule_glob_matches($glob, $path)) !== []) !== [];
    }

    return true;
};

foreach (glob(rtrim($directory, '/').'/review-rules*.md') ?: [] as $file) {
    // The file in parts: what comes before the first section, then each section with
    // its introduction and its rules. A rule runs to the next rule or section.
    $head = [];
    $sections = [];

    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $last = array_key_last($sections);

        if (in_array($line, TEAM_RULE_SECTIONS, true)) {
            $sections[] = ['intro' => [$line], 'rules' => []];
        } elseif ($last === null) {
            $head[] = $line;
        } elseif (preg_match('/^### [RC]\d+\. /', $line) === 1) {
            $sections[$last]['rules'][] = [$line];
        } elseif ($sections[$last]['rules'] === []) {
            $sections[$last]['intro'][] = $line;
        } else {
            $sections[$last]['rules'][array_key_last($sections[$last]['rules'])][] = $line;
        }
    }

    $total = 0;
    $left = 0;
    $out = $head;

    foreach ($sections as $section) {
        $kept = array_values(array_filter($section['rules'], $applies));
        $total += count($section['rules']);
        $left += count($section['rules']) - count($kept);

        // A section whose every rule is left out goes, heading and all.
        if ($kept !== []) {
            array_push($out, ...$section['intro']);

            foreach ($kept as $rule) {
                array_push($out, ...$rule);
            }
        }
    }

    if ($left === 0) {
        continue;
    }

    file_put_contents($file, rtrim(implode("\n", $out))."\n\nRules limited to paths that no file of this change matches are left out: {$left} of {$total}.\n");
    fwrite(STDERR, "Team rules: {$left} of {$total} left out of ".basename($file).", as no changed file matches their paths.\n");
}
