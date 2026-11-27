<?php

/**
 * Claude Code's PreToolUse hook for WebSearch, where an answer to a question may
 * search the web: lets a search go only when it is made of public words, so that
 * nothing the session read (the code, the pull request, a ticket) reaches the search
 * provider. Every word of the query has to be one of these:
 *
 * - a name the repository's dependencies make public: a word in an installed
 *   package (vendor/, node_modules/), in a lock file, or a dependency's name in
 *   composer.json or package.json, and not the project's own name;
 * - a version: a number that an installed or required version starts with, such
 *   as 12.74 for 12.74.0 or 4 for ^4.1;
 * - one of the few words a search for documentation is made of, such as
 *   changelog, release or upgrade.
 *
 * Anything else, a number that is no version included, and the search is refused
 * with the reason, which Claude Code hands the reviewer.
 *
 *   php -n search-guard.php < hook input     (in the checkout)
 *
 * Exits 0 to let the search go and 2 to refuse it. answer-question.sh runs it as
 * `... || exit 2`, so a guard that fails to run refuses too.
 */
const SEARCH_WORDS = [
    'a', 'an', 'and', 'or', 'not', 'no', 'the', 'of', 'in', 'on', 'to', 'for', 'from', 'with', 'without', 'by', 'at', 'as',
    'is', 'are', 'was', 'does', 'do', 'did', 'how', 'what', 'when', 'why', 'which', 'vs', 'versus', 'between', 'since', 'after', 'before',
    'release', 'releases', 'released', 'notes', 'changelog', 'changes', 'change', 'changed', 'upgrade', 'upgrading', 'guide',
    'migration', 'migrate', 'breaking', 'deprecated', 'deprecation', 'removed', 'new', 'version', 'versions', 'major', 'minor', 'patch',
    'docs', 'documentation', 'issue', 'issues', 'bug', 'fix', 'fixed', 'regression', 'security', 'advisory', 'cve',
    'behaviour', 'behavior', 'default', 'support', 'supported', 'github', 'php', 'javascript', 'typescript', 'node', 'npm', 'composer', 'packagist',
];

$refuse = function (string $reason): never {
    fwrite(STDERR, "This search was not sent: {$reason} A search may only use public names of the repository's dependencies, their versions, and words such as release, changelog or upgrade: nothing from the code, the pull request or a ticket.\n");
    exit(2);
};

$input = json_decode((string) stream_get_contents(STDIN), true);
$query = is_array($input) ? ($input['tool_input']['query'] ?? null) : null;

if (! is_string($query) || trim($query) === '') {
    $refuse('there was no query to check.');
}

if (strlen($query) > 200) {
    $refuse('a search is a few public words, not a passage.');
}

// Words, scoped and slashed package names, versions; nothing else.
if (preg_match('/^[A-Za-z0-9 .\/@_\-]+$/', $query) !== 1) {
    $refuse('it holds characters a search for documentation does not need.');
}

/** A JSON file of the checkout, when it is one. */
$json = fn (string $file): array => is_file($file) && ! is_link($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];

// The dependencies' names and the versions they ask for, and the project's own name, which is not public.
$dependencies = [];
$versions = [];
$own = [];

foreach (['composer.json' => ['require', 'require-dev'], 'package.json' => ['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies']] as $file => $sections) {
    $manifest = $json($file);

    foreach (preg_split('/[\/@\-_.\s]+/', strtolower((string) ($manifest['name'] ?? ''))) ?: [] as $part) {
        $own[$part] = true;
    }

    foreach ($sections as $section) {
        foreach ((array) ($manifest[$section] ?? []) as $name => $constraint) {
            $dependencies[strtolower((string) $name)] = true;

            foreach (preg_split('/[\/@\-_.]+/', strtolower((string) $name)) ?: [] as $part) {
                $dependencies[$part] = true;
            }

            preg_match_all('/\d+(?:\.\d+)*/', (string) $constraint, $numbers);
            array_push($versions, ...$numbers[0]);
        }
    }
}

$locks = array_values(array_filter(['composer.lock', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml'], fn (string $file): bool => is_file($file) && ! is_link($file)));

foreach ($locks as $lock) {
    preg_match_all('/version"?\s*:?\s*"?v?(\d+(?:\.\d+)*)/', (string) file_get_contents($lock), $numbers);
    array_push($versions, ...$numbers[1]);
}

/** Whether grep finds the word in the public code, as a whole word in any case. */
$inPublicCode = function (string $word) use ($locks): bool {
    foreach ([...$locks, ...array_filter(['vendor', 'node_modules'], 'is_dir')] as $where) {
        exec(implode(' ', array_map('escapeshellarg', ['timeout', '20', 'grep', '-r', '-q', '-I', '-i', '-w', '-F', '-e', $word, $where])).' > /dev/null 2>&1', $output, $status);

        if ($status === 0) {
            return true;
        }
    }

    return false;
};

foreach (preg_split('/\s+/', trim($query)) ?: [] as $word) {
    $word = strtolower(trim($word, '.'));

    if ($word === '' || in_array($word, SEARCH_WORDS, true)) {
        continue;
    }

    // A version: a number an installed or required version starts with.
    if (preg_match('/^v?(\d+(?:\.\d+)*)$/', $word, $number) === 1) {
        $isVersion = array_filter($versions, fn (string $version): bool => $version === $number[1] || str_starts_with($version, $number[1].'.')) !== [];

        if (! $isVersion) {
            $refuse("`{$word}` is a number that no installed or required version starts with.");
        }

        continue;
    }

    // A name: each part public, and none the project's own.
    foreach (array_unique([$word, ...preg_split('/[\/@\-_.]+/', $word)]) as $part) {
        if ($part === '' || in_array($part, SEARCH_WORDS, true) || isset($dependencies[$part]) && ! isset($own[$part])) {
            continue;
        }

        if (isset($own[$part]) || ! $inPublicCode($part)) {
            $refuse("`{$part}` is no name of an installed package or a dependency.");
        }
    }
}

exit(0);
