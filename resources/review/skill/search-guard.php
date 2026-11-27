<?php

/**
 * Claude Code's PreToolUse hook for WebSearch, where an answer to a question may
 * search the web: lets a search go only when it is made of public words, so that
 * nothing the session read (the code, the pull request, a ticket) reaches the search
 * provider. Every word of the query has to be one of these:
 *
 * - a name the repository's dependencies make public: a word in an installed
 *   package (vendor/, node_modules/), in a lock file, or a dependency's name in
 *   composer.json or package.json, and not the project's own name. Only a dependency
 *   from a registry counts: one from a workspace, a path, a file or a repository of
 *   the project's own (as the manifest's constraint or the lock file says) is
 *   private, and its name and its vendor or scope are refused;
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
// A dependency is public by where it comes from, not by being named: a version range from a registry,
// and where a lock file says, a package the public registry served. One from a workspace, a path, a file
// or a repository of the project's own is private: its name may be as confidential as the code.
$dependencies = [];
$versions = [];
$own = [];
$private = [];
$declared = [];
$fromRegistry = fn (string $constraint): bool => preg_match('/^\s*(workspace:|file:|link:|portal:|git[+:@]|github:|https?:|\.{0,2}\/|~\/)/i', $constraint) !== 1
    && preg_match('/(^|[\s|,])(dev-|\*@dev|@dev)/i', $constraint) !== 1;
$isPrivate = function (string $name) use (&$private): void {
    $name = strtolower($name);
    $private[$name] = true;
    // Its vendor or scope too: `acme` of acme/risk-engine and @acme/risk-engine.
    $private[ltrim((string) preg_split('/\//', $name)[0], '@')] = true;
};

foreach (['composer.json' => ['require', 'require-dev'], 'package.json' => ['dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies']] as $file => $sections) {
    $manifest = $json($file);

    foreach (preg_split('/[\/@\-_.\s]+/', strtolower((string) ($manifest['name'] ?? ''))) ?: [] as $part) {
        $own[$part] = true;
    }

    foreach ($sections as $section) {
        foreach ((array) ($manifest[$section] ?? []) as $name => $constraint) {
            if (! $fromRegistry((string) $constraint)) {
                $isPrivate((string) $name);

                continue;
            }

            $declared[strtolower((string) $name)] = true;
            preg_match_all('/\d+(?:\.\d+)*/', (string) $constraint, $numbers);
            array_push($versions, ...$numbers[0]);
        }
    }
}

$locks = array_values(array_filter(['composer.lock', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml'], fn (string $file): bool => is_file($file) && ! is_link($file)));

// What the lock files say of where each package came from: Packagist, or the npm registry.
foreach ([...((array) ($json('composer.lock')['packages'] ?? [])), ...((array) ($json('composer.lock')['packages-dev'] ?? []))] as $package) {
    if (is_array($package) && isset($package['name']) && ! str_contains((string) ($package['notification-url'] ?? ''), 'packagist.org')) {
        $isPrivate((string) $package['name']);
    }
}

foreach ((array) ($json('package-lock.json')['packages'] ?? []) as $path => $package) {
    $name = preg_replace('#^.*node_modules/#', '', (string) $path);

    if ($name !== '' && is_array($package) && (($package['link'] ?? false) === true || (isset($package['resolved']) && preg_match('#^https://registry\.(npmjs\.org|yarnpkg\.com)/#', (string) $package['resolved']) !== 1))) {
        $isPrivate($name);
    }
}

foreach (array_keys(array_diff_key($declared, $private)) as $name) {
    $dependencies[$name] = true;

    foreach (preg_split('/[\/@\-_.]+/', $name) ?: [] as $part) {
        $dependencies[$part] = true;
    }
}

foreach ($locks as $lock) {
    preg_match_all('/version"?\s*:?\s*"?v?(\d+(?:\.\d+)*)/', (string) file_get_contents($lock), $numbers);
    array_push($versions, ...$numbers[1]);
}

/** Whether grep finds the word in the public code, as a whole word in any case. */
$inPublicCode = function (string $word) use ($locks, &$private): bool {
    // Not in a private package's own directory, where its code is the project's.
    $excluded = array_map(fn (string $name): string => '--exclude-dir='.basename($name), array_keys(array_filter($private, fn (bool $yes, string $name): bool => str_contains($name, '/'), ARRAY_FILTER_USE_BOTH)));

    foreach ([...$locks, ...array_filter(['vendor', 'node_modules'], 'is_dir')] as $where) {
        exec(implode(' ', array_map('escapeshellarg', ['timeout', '20', 'grep', '-r', '-q', '-I', '-i', '-w', '-F', ...(is_dir($where) ? $excluded : []), '-e', $word, $where])).' > /dev/null 2>&1', $output, $status);

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

    // A private dependency's name, or its vendor or scope, never goes out.
    if (isset($private[ltrim($word, '@')]) || isset($private[$word])) {
        $refuse("`{$word}` names a dependency of the project's own, not a public package.");
    }

    // A name: each part public. A public dependency's name is public, also where the
    // project's own name has it too (laravel/laravel and laravel/framework); any other
    // part of the project's own name is not.
    foreach (array_unique([$word, ...preg_split('/[\/@\-_.]+/', $word)]) as $part) {
        if ($part === '' || in_array($part, SEARCH_WORDS, true) || isset($dependencies[$part])) {
            continue;
        }

        if (isset($private[$part])) {
            $refuse("`{$part}` names a dependency of the project's own, not a public package.");
        }

        if (isset($own[$part]) || ! $inPublicCode($part)) {
            $refuse("`{$part}` is no name of an installed package or a dependency.");
        }
    }
}

exit(0);
