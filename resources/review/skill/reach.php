<?php

/**
 * Facts about how far a Laravel change reaches, for the prompt's REACH: for each
 * check the change adds or changes (a method named as one, such as canBook,
 * allows or moduleAccessError), the entry points that reach it, and the entry
 * points that reach a method of the same class that writes, without passing the
 * check. A rule about who may do something has to hold on every path to what it
 * guards, and a path the change never touches is the one a review misses.
 *
 *   php reach.php <destination ref> <commit>     (in the checkout)
 *
 * Name-based, with git grep: a call ->name( or ::name( is taken to reach every
 * method of that name, and on the first step only in a file that names the
 * class. So it points a reviewer at paths, and is never proof. Runs with any
 * PHP, without Composer. Prints nothing when the change adds or changes no check.
 */
[$script, $base, $commit] = array_pad($argv, 3, null);

if ($base === null || $commit === null) {
    fwrite(STDERR, "Usage: php reach.php <destination ref> <commit>\n");
    exit(2);
}

/** How many calls up from a method it follows. */
const DEPTH = 4;

/** How many searches one run makes at most, so a large codebase stays quick. */
const SEARCHES = 300;

/** How many entry points without the check it names per check. */
const LISTED = 25;

/** All of the section's text at most. */
const BUDGET = 8000;

/** Where a request, a command or a job starts. */
const ENTRY = '#^(app/Http/Controllers/|app/Livewire/|app/Console/Commands/|app/Jobs/|app/Listeners/|app/Mcp/|app/Filament/|routes/|resources/views/)#';

/** A method named as a check. */
const CHECK = '/^(can[A-Z]\w*|allows?|allows[A-Z]\w*|authori[sz]e\w*|check[A-Z]\w*|has\w*(Error|Errors|Access|Permission)|is\w*(Allowed|Accessible|Permitted|Authori[sz]ed)|\w*Access(Error|Errors)?|\w*Permission)$/';

/** What makes a method an effect: it writes. */
const WRITES = '/(->|::)(save|create|createMany|insert|update|updateOrCreate|firstOrCreate|attach|sync|syncWithoutDetaching|delete|forceDelete|restore|increment|decrement|push)\(/';

/** Names too common to follow by name alone. */
const COMMON = ['get', 'set', 'find', 'first', 'save', 'create', 'update', 'delete', 'handle', 'render', 'mount', 'boot', 'make', 'query', 'where', 'all', 'toArray', 'fill', 'load', 'fresh', 'refresh', 'map', 'filter', 'each', 'count', 'exists', 'push', 'attach', 'sync', 'value', 'has', 'is', 'can', 'check', 'add', 'remove', 'store', 'show', 'index', 'edit', 'destroy', 'run', 'send', 'build', 'with', 'when', 'tap', '__construct', '__invoke'];

$searches = 0;

$sh = function (array $arguments): string {
    $output = [];
    exec('git '.implode(' ', array_map('escapeshellarg', $arguments)).' 2>/dev/null', $output);

    return implode("\n", $output);
};

$mergeBase = trim($sh(['merge-base', $base, $commit])) ?: $base;

$functionsOf = [];
$functions = function (string $file) use (&$functionsOf): array {
    if (isset($functionsOf[$file])) {
        return $functionsOf[$file];
    }

    $lines = @file($file) ?: [];
    $found = [];

    foreach ($lines as $index => $line) {
        if (preg_match('/^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+(\w+)\s*\(/', $line, $match) === 1) {
            $found[] = ['name' => $match[1], 'start' => $index + 1, 'end' => count($lines)];
        }
    }

    for ($i = 0; $i < count($found) - 1; $i++) {
        $found[$i]['end'] = $found[$i + 1]['start'] - 1;
    }

    return $functionsOf[$file] = $found;
};

$enclosing = function (string $file, int $line) use ($functions): ?string {
    foreach ($functions($file) as $function) {
        if ($line >= $function['start'] && $line <= $function['end']) {
            return $function['name'];
        }
    }

    return null;
};

$classOf = function (string $file): ?string {
    return preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|trait)\s+(\w+)/m', (string) @file_get_contents($file), $match) === 1 ? $match[1] : null;
};

// The checks the change adds or changes: methods named as one, with a changed line in them.
$checks = [];

foreach (array_filter(explode("\n", $sh(['diff', '--name-only', "{$mergeBase}...{$commit}", '--', 'app']))) as $file) {
    if (! str_ends_with($file, '.php') || ! is_file($file)) {
        continue;
    }

    preg_match_all('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/m', $sh(['diff', '-U0', "{$mergeBase}...{$commit}", '--', $file]), $hunks, PREG_SET_ORDER);

    foreach ($hunks as $hunk) {
        $count = ($hunk[2] ?? '') === '' ? 1 : (int) $hunk[2];

        for ($line = (int) $hunk[1]; $line < (int) $hunk[1] + max($count, 1); $line++) {
            $name = $enclosing($file, $line);

            if ($name !== null && preg_match(CHECK, $name) === 1) {
                $checks["{$file}::{$name}"] = ['file' => $file, 'name' => $name];
            }
        }
    }
}

if ($checks === []) {
    exit(0);
}

$callersOf = [];
$mentions = [];
$callers = function (string $name, ?string $class) use (&$callersOf, &$mentions, &$searches, $sh, $enclosing): array {
    $key = "{$class}::{$name}";

    if (isset($callersOf[$key])) {
        return $callersOf[$key];
    }

    if (++$searches > SEARCHES) {
        return [];
    }

    $found = [];

    foreach (array_filter(explode("\n", $sh(['grep', '-n', '-E', '(->|::)'.$name.'\(', '--', 'app', 'routes', 'resources/views']))) as $hit) {
        if (preg_match('/^([^:]+):(\d+):/', $hit, $match) !== 1) {
            continue;
        }

        // A call by that name in a file that never names the class is another class's method.
        if ($class !== null) {
            $mentions[$match[1]][$class] ??= preg_match('/\b'.preg_quote($class, '/').'\b/', (string) @file_get_contents($match[1])) === 1;

            if (! $mentions[$match[1]][$class]) {
                continue;
            }
        }

        $caller = str_ends_with($match[1], '.php') && ! str_starts_with($match[1], 'routes/') && ! str_starts_with($match[1], 'resources/') ? ($enclosing($match[1], (int) $match[2]) ?? '(file)') : '(file)';
        $found["{$match[1]}::{$caller}"] = ['file' => $match[1], 'name' => $caller];
    }

    return $callersOf[$key] = $found;
};

/** The entry points that reach a method, each with how many calls it is away. */
$reach = function (string $name, ?string $class) use ($callers): array {
    $entries = [];
    $queue = [[$name, 0]];
    $seen = [$name => true];

    while ($queue !== []) {
        [$current, $depth] = array_shift($queue);

        // The class is known for the first step only; past it, any caller of that name.
        foreach ($callers($current, $depth === 0 ? $class : null) as $key => $caller) {
            if (preg_match(ENTRY, $caller['file']) === 1) {
                $entries[$key] ??= $depth + 1;
            }

            if ($depth + 1 < DEPTH && $caller['name'] !== '(file)' && ! in_array($caller['name'], COMMON, true) && ! isset($seen[$caller['name']])) {
                $seen[$caller['name']] = true;
                $queue[] = [$caller['name'], $depth + 1];
            }
        }
    }

    return $entries;
};

$out = [];

foreach ($checks as $key => $check) {
    $class = $classOf($check['file']);
    $entries = $reach($check['name'], $class);
    $lines = @file($check['file']) ?: [];
    $without = [];

    // The class's effects: its methods that write, and who reaches them without the check.
    foreach ($functions($check['file']) as $function) {
        $body = implode('', array_slice($lines, $function['start'] - 1, $function['end'] - $function['start'] + 1));

        if ($function['name'] === $check['name'] || in_array($function['name'], COMMON, true) || preg_match(WRITES, $body) !== 1) {
            continue;
        }

        foreach (array_diff_key($reach($function['name'], $class), $entries) as $entry => $distance) {
            $without[$entry]['via'][] = $function['name'];
            $without[$entry]['distance'] = min($without[$entry]['distance'] ?? PHP_INT_MAX, $distance);
        }
    }

    // The closest first: an entry point a call or two from the write is the likeliest to need the check.
    uasort($without, fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);

    $out[] = "- `{$key}` is reached from ".count($entries).' entry point(s)'.($entries === [] ? '.' : ', such as '.implode(', ', array_map(fn (string $entry): string => "`{$entry}`", array_slice(array_keys($entries), 0, 6))).'.');

    if ($without !== []) {
        $out[] = "  These reach a method of `{$class}` that writes, without passing `{$check['name']}`:";

        foreach (array_slice($without, 0, LISTED, true) as $entry => $hit) {
            $out[] = "  - `{$entry}`, through `".implode('`, `', array_unique($hit['via'])).'`';
        }

        if (count($without) > LISTED) {
            $out[] = '  - and '.(count($without) - LISTED).' more, further from the write.';
        }
    }
}

$text = implode("\n", $out);

if (strlen($text) > BUDGET) {
    $text = substr($text, 0, strrpos(substr($text, 0, BUDGET), "\n") ?: BUDGET)."\n  - (cut short)";
}

echo "\nREACH:\n\n";
echo "Worked out from the code before you started, by method name: a call to ->name( is taken to reach every method of that name, so this is a floor that can also name a path that does not apply. Verify each before you rely on it. For each check this change adds or changes, it lists the entry points (controllers, Livewire components, jobs, commands, routes, views) that reach it, and the entry points that reach a method of the same class that writes without passing the check. Where the change brings in a rule about who may do something, decide for each of those whether the rule has to hold there too: one that skips it is this change's finding, though the path is old.\n\n";
echo $text."\n";
