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

foreach (glob(rtrim($directory, '/').'/review-rules*.md') ?: [] as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    $kept = [];
    $block = [];
    $left = 0;
    $total = 0;

    // A rule runs from its heading to the next heading of either level.
    $flush = function () use (&$block, &$kept, &$left, &$total, $changed): void {
        if ($block === []) {
            return;
        }

        $total++;
        $globs = null;

        foreach ($block as $line) {
            if (str_starts_with($line, 'Only in: ')) {
                preg_match_all('/`([^`]+)`/', explode(' · ', $line)[0], $found);
                $globs = $found[1];

                break;
            }
        }

        $applies = $globs === null || array_filter($changed, fn (string $path): bool => array_filter($globs, fn (string $glob): bool => review_rule_glob_matches($glob, $path)) !== []) !== [];

        if ($applies) {
            array_push($kept, ...$block);
        } else {
            $left++;
        }

        $block = [];
    };

    foreach ($lines as $line) {
        if (preg_match('/^### [RC]\d+\. /', $line) === 1) {
            $flush();
            $block = [$line];
        } elseif ($block !== [] && preg_match('/^#{1,2} /', $line) !== 1) {
            $block[] = $line;
        } else {
            $flush();
            $kept[] = $line;
        }
    }

    $flush();

    if ($left === 0) {
        continue;
    }

    // A section whose every rule is left out goes, heading and all.
    $text = implode("\n", $kept)."\n";
    $text = (string) preg_replace_callback('/^## [^\n]+\n(?:(?!## )[^\n]*\n)*/m', fn (array $section): string => preg_match('/^### /m', $section[0]) === 1 ? $section[0] : '', $text);
    $text = rtrim($text)."\n\nRules limited to paths that no file of this change matches are left out: {$left} of {$total}.\n";

    file_put_contents($file, $text);
    fwrite(STDERR, "Team rules: {$left} of {$total} left out of ".basename($file).", as no changed file matches their paths.\n");
}
