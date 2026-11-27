<?php

/**
 * Claude Code's PreToolUse hook for WebSearch, where an answer to a question may
 * search the web: lets a search go only when every name in it is public. A name
 * the repository uses that no installed package does (a class, a method, a path of
 * the repository's own) may be private, and the search would carry it to the
 * search provider, so the search is refused and the reviewer told why.
 *
 *   php -n search-guard.php < hook input     (in the checkout)
 *
 * Exits 0 to let the search go, 2 to refuse it with the reason on stderr, which
 * Claude Code hands the reviewer. Anything it cannot tell refuses: answer-question.sh
 * runs it as `... || exit 2`, so a guard that fails to run refuses too.
 */
$refuse = function (string $reason): never {
    fwrite(STDERR, "This search was not sent: {$reason} Search with public names and versions only, such as a package, a framework class or a release, and nothing that is this repository's own.\n");
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

if (preg_match('/[$;{}<>`]|->|::|=>/', $query) === 1) {
    $refuse('it holds code.');
}

/** Whether git or grep finds the word, as a whole word in any case; null when it could not look. */
$finds = function (array $command): ?bool {
    exec(implode(' ', array_map('escapeshellarg', $command)).' > /dev/null 2>&1', $output, $status);

    return match ($status) {
        0 => true,
        1 => false,
        default => null,
    };
};

if ($finds(['git', 'rev-parse', '--git-dir']) !== true) {
    $refuse('the repository could not be read to check it.');
}

// Every word and every dotted, slashed or namespaced name, and their parts.
preg_match_all('/[A-Za-z_][\w]*(?:[\\\\\/.\-][A-Za-z_][\w]*)*/', $query, $found);
$names = [];

foreach ($found[0] as $name) {
    foreach ([$name, ...preg_split('/[\\\\\/.\-]+/', $name)] as $part) {
        if (strlen($part) >= 4) {
            $names[strtolower($part)] = $part;
        }
    }
}

foreach ($names as $name) {
    // A name of the repository's own: in its tracked files, outside what it installs.
    $own = $finds(['git', 'grep', '-q', '-I', '-i', '-w', '-F', '-e', $name, '--', '.', ':!vendor', ':!node_modules']);

    if ($own === null) {
        $refuse('the repository could not be searched to check it.');
    }

    if (! $own) {
        continue;
    }

    // Public when an installed package or the lock file names it too.
    $public = $finds(['grep', '-q', '-i', '-w', '-F', '-e', $name, 'composer.lock'])
        || (is_dir('vendor') && $finds(['timeout', '20', 'grep', '-r', '-q', '-I', '-i', '-w', '-F', '-e', $name, 'vendor']));

    if (! $public) {
        $refuse("`{$name}` is in this repository and in no installed package, so it may be private.");
    }
}

exit(0);
