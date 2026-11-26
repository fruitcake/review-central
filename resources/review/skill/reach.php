<?php

/**
 * Facts about how far a Laravel change reaches, for the prompt's REACH: for each
 * check the change adds or changes (a method named as one, such as canBook,
 * allows or moduleAccessError), the entry points that reach it, and the entry
 * points that reach a method of the same class that writes, without passing the
 * check. With the code index, also the models the check reads by name and the
 * entry points that add to them without passing it: through a relation, or with
 * the model's own create. A rule about who may do something has to hold on every
 * path to what it guards, and a path the change never touches is the one a
 * review misses.
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

/** How many entry points that add to a check's data it names; past that, only how many. */
const DATA_LISTED = 15;

/** How many methods may add to one model before it counts as central: added to everywhere, and said so in a count. */
const ADDERS = 10;

/** A call that adds to a relation: what a path into the data a check reads goes through. */
const ADDS = 'attach|create|createMany|createQuietly|save|saveMany|saveQuietly|insert|firstOrCreate|updateOrCreate|sync|syncWithoutDetaching';

/** A model's own static call that adds a row of it. */
const CREATES = 'create|forceCreate|insert|firstOrCreate|updateOrCreate';

/** Where a request, a command or a job starts. */
const ENTRY = '#^(app/Http/Controllers/|app/Livewire/|app/Console/Commands/|app/Jobs/|app/Listeners/|app/Mcp/|app/Filament/|routes/|resources/views/)#';

/** A method named as a check. */
const CHECK = '/^(can[A-Z]\w*|allows?|allows[A-Z]\w*|authori[sz]e\w*|check[A-Z]\w*|has\w*(Error|Errors|Access|Permission)|is\w*(Allowed|Accessible|Permitted|Authori[sz]ed)|\w*Access(Error|Errors)?|\w*Permission)$/';

/** A line that guards who may go on: a check written inline, in the method it guards. */
const GUARD = '/\babort\s*\(\s*403\b|\babort_(if|unless)\s*\(|->authori[sz]e\s*\(|\bGate::(allows|denies|authori[sz]e|check|any|none|inspect)\s*\(|->(can|cannot|cant)\s*\(|\b(AuthorizationException|AccessDeniedHttpException|AccessDeniedException)\b/';

/** Where every public method is a check: Laravel's policies. */
const POLICIES = 'app/Policies/';

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

    // From the tokens, so a method ends at its own closing brace, not at whatever is declared next.
    $tokens = @token_get_all((string) @file_get_contents($file)) ?: [];
    $found = [];

    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }

        $start = $tokens[$i][2];
        $name = null;

        // The name, unless it is a closure; then the body, unless it is abstract.
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $name = $tokens[$j][1];

                break;
            }

            if ($tokens[$j] === '(') {
                break;
            }
        }

        // A closure is kept by where it starts: a route file's entry points are closures.
        $name ??= "(closure at line {$start})";

        $depth = 0;
        $line = $start;

        for ($j++; $j < $count; $j++) {
            if (is_array($tokens[$j])) {
                $line = $tokens[$j][2] + substr_count($tokens[$j][1], "\n");

                if (in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                }

                continue;
            }

            if ($tokens[$j] === ';' && $depth === 0) {
                break;
            }

            if ($tokens[$j] === '{') {
                $depth++;
            } elseif ($tokens[$j] === '}' && --$depth === 0) {
                $found[] = ['name' => $name, 'start' => $start, 'end' => $line];

                break;
            }
        }
    }

    return $functionsOf[$file] = $found;
};

/** The method a line is in, or else the innermost closure, or null outside both. */
$enclosing = function (string $file, int $line) use ($functions): ?string {
    $closure = null;

    foreach ($functions($file) as $function) {
        if ($line < $function['start'] || $line > $function['end']) {
            continue;
        }

        if (! str_starts_with($function['name'], '(closure')) {
            return $function['name'];
        }

        if ($closure === null || $function['start'] >= $closure['start']) {
            $closure = $function;
        }
    }

    return $closure['name'] ?? null;
};

$classOf = function (string $file): ?string {
    return preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|trait)\s+(\w+)/m', (string) @file_get_contents($file), $match) === 1 ? $match[1] : null;
};

$linesOf = function (string $file): array {
    static $lines = [];

    return $lines[$file] ??= @file($file) ?: [];
};

/** Whether a method is public: declared so, or with no visibility at all. */
$isPublic = function (string $file, string $name) use ($functions, $linesOf): bool {
    foreach ($functions($file) as $function) {
        if ($function['name'] === $name) {
            $declaration = $linesOf($file)[$function['start'] - 1] ?? '';

            return preg_match('/\b(private|protected)\b/', $declaration) !== 1 && $name !== '__construct';
        }
    }

    return false;
};

/** Whether a method is declared private or protected: one that cannot be found is not. */
$isHidden = function (string $file, string $name) use ($functions, $linesOf): bool {
    foreach ($functions($file) as $function) {
        if (strcasecmp($function['name'], $name) === 0) {
            return preg_match('/\b(private|protected)\b/', $linesOf($file)[$function['start'] - 1] ?? '') === 1;
        }
    }

    return false;
};

// The checks the change adds or changes: methods named as one, policies' methods, and
// methods with a changed line that guards who may go on.
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

            if ($name === null || str_starts_with($name, '(closure')) {
                continue;
            }

            // Named as a check; any public method of a policy; or a method a changed line guards.
            if (preg_match(CHECK, $name) === 1
                || (str_starts_with($file, POLICIES) && $isPublic($file, $name))
                || preg_match(GUARD, $linesOf($file)[$line - 1] ?? '') === 1) {
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
        if (preg_match('/^([^:]+):(\d+):(.*)$/', $hit, $match) !== 1) {
            continue;
        }

        // A name in a comment or a docblock is no call.
        if (preg_match('#^\s*(\*|//|/\*|\#)#', $match[3]) === 1) {
            continue;
        }

        // A call by that name in a file that never names the class is another class's method.
        if ($class !== null) {
            $mentions[$match[1]][$class] ??= preg_match('/\b'.preg_quote($class, '/').'\b/', (string) @file_get_contents($match[1])) === 1;

            if (! $mentions[$match[1]][$class]) {
                continue;
            }
        }

        $caller = str_ends_with($match[1], '.php') && ! str_ends_with($match[1], '.blade.php') ? $enclosing($match[1], (int) $match[2]) : null;

        // A route closure is its own entry point, so a checked one does not hide an
        // unchecked one in the same file; a call in no function at all, by its line.
        $key = $caller === null ? "{$match[1]}:{$match[2]}" : "{$match[1]}::{$caller}";
        $found[$key] = ['file' => $match[1], 'name' => $caller === null || str_starts_with($caller, '(closure') ? '(file)' : $caller];
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

// The code index, when the review built one (REVIEW_CODE_INDEX): calls resolved by the
// type of what they are called on, so a path is one the code takes, not one a name allows.
$indexed = null;
$directory = (string) getenv('REACH_INDEX');

if ($directory !== '' && @filesize("{$directory}/edges.tsv") > 0) {
    require_once __DIR__.'/code-index-models.php';
    $indexed = ['callers' => [], 'files' => [], 'ancestors' => []];

    foreach (['edges' => 'callers', 'classes' => 'files', 'ancestors' => 'ancestors'] as $file => $field) {
        foreach (@file("{$directory}/{$file}.tsv", FILE_IGNORE_NEW_LINES) ?: [] as $row) {
            [$left, $right] = array_pad(explode("\t", $row, 2), 2, '');

            match ($field) {
                'callers' => $indexed['callers'][$right][$left] = true,
                'files' => $indexed['files'][$left] = $right,
                'ancestors' => $indexed['ancestors'][$left][] = $right,
            };
        }
    }
}

/** A file's class by its full name, in lower case as the index has it. */
$fullClassOf = function (string $file): ?string {
    $text = (string) @file_get_contents($file);

    if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|trait|interface|enum)\s+(\w+)/m', $text, $class) !== 1) {
        return null;
    }

    $namespace = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $text, $match) === 1 ? $match[1].'\\' : '';

    return strtolower($namespace.$class[1]);
};

/** The entry points that reach a method in the index, each with how many calls it is away. */
$reachIndexed = function (string $symbol) use (&$indexed, $functions, $enclosing, $isHidden): array {
    $entries = [];
    $queue = [[$symbol, 0]];
    $seen = [$symbol => true];

    while ($queue !== [] && count($seen) < 5000) {
        [$current, $depth] = array_shift($queue);
        [$class, $method] = explode('::', $current, 2);
        // A call through a parent or an interface can land on this class's method.
        $targets = array_merge([$current], array_map(fn (string $ancestor): string => "{$ancestor}::{$method}", $indexed['ancestors'][$class] ?? []));

        foreach ($targets as $target) {
            $through = $target === $current ? null : explode('::', $target, 2)[0];

            foreach (array_keys($indexed['callers'][$target] ?? []) as $source) {
                $sourceClass = str_starts_with($source, 'file:') ? null : explode('::', $source, 2)[0];

                // A sibling calling the method it inherits from the same parent calls its
                // own, not this class's: only a call from outside that family can land here.
                if ($through !== null && $sourceClass !== null && $sourceClass !== $class
                    && in_array($through, $indexed['ancestors'][$sourceClass] ?? [], true)
                    && ! in_array($sourceClass, $indexed['ancestors'][$class] ?? [], true)) {
                    continue;
                }

                if (str_starts_with($source, 'file:')) {
                    $file = substr($source, 5);
                    $keys = [];

                    // Code outside any class, such as a route file's closures, is one origin in the
                    // index: each call site is told apart by the closure it is in, as by name.
                    foreach (@file($file) ?: [] as $number => $text) {
                        if (preg_match('/(->|::)'.preg_quote(explode('::', $target, 2)[1], '/').'\s*\(/i', $text) === 1 && preg_match('#^\s*(\*|//|/\*|\#)#', $text) !== 1) {
                            $caller = $enclosing($file, $number + 1);
                            $keys[] = $caller === null ? "{$file}:".($number + 1) : "{$file}::{$caller}";
                        }
                    }

                    $keys = $keys === [] ? [$file] : array_unique($keys);
                } else {
                    [$sourceClass, $sourceMethod] = explode('::', $source, 2);
                    $writtenIn = $indexed['files'][$sourceClass] ?? null;

                    // A parent's method that calls this one runs, for this class, as this
                    // class's own: the way in is this class's, not every class the parent has.
                    if ($sourceClass !== $class && in_array($sourceClass, $indexed['ancestors'][$class] ?? [], true)) {
                        $sourceClass = $class;
                        $source = "{$class}::{$sourceMethod}";
                    }

                    // This method again, through a parent's of the same name and parent::.
                    if ($source === $current) {
                        continue;
                    }

                    $file = $indexed['files'][$sourceClass] ?? null;

                    if ($file === null || $writtenIn === null) {
                        continue;
                    }

                    // The method as it is written, for the reader.
                    $name = $sourceMethod;

                    foreach ($functions($writtenIn) as $function) {
                        if (strtolower($function['name']) === $sourceMethod) {
                            $name = $function['name'];

                            break;
                        }
                    }

                    $keys = ["{$file}::{$name}"];
                }

                // A controller's protected or private method is no way in: what calls it is.
                if (preg_match(ENTRY, $file) === 1 && (str_starts_with($source, 'file:') || ! $isHidden($writtenIn, $name))) {
                    foreach ($keys as $key) {
                        $entries[$key] ??= $depth + 1;
                    }
                }

                if ($depth + 1 < DEPTH && ! str_starts_with($source, 'file:') && ! isset($seen[$source])) {
                    $seen[$source] = true;
                    $queue[] = [$source, $depth + 1];
                }
            }
        }
    }

    return $entries;
};

/** A function's text, by its name in any case. */
$bodyOf = function (string $file, string $name) use ($functions, $linesOf): ?array {
    foreach ($functions($file) as $function) {
        if (strcasecmp($function['name'], $name) === 0) {
            return $function + ['text' => implode('', array_slice($linesOf($file), $function['start'] - 1, $function['end'] - $function['start'] + 1))];
        }
    }

    return null;
};

/** A class's name as its file writes it, from the index's lower-case one. */
$writtenClass = function (string $class) use (&$indexed): string {
    $short = substr((string) strrchr('\\'.$class, '\\'), 1);

    return preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|trait|interface|enum)\s+('.preg_quote($short, '/').')\b/mi', isset($indexed['files'][$class]) ? (string) @file_get_contents($indexed['files'][$class]) : '', $declared) === 1 ? $declared[1] : $short;
};

/**
 * The models a check reads by name: the ones its method, and the methods of its
 * class that it calls, name as X:: or new X. A model it is only handed, such as
 * the member who books, is everywhere, and says nothing about what it guards.
 *
 * @return list<string> lower case, as the index has them
 */
$modelsReadBy = function (array $check) use (&$indexed, $bodyOf, $fullClassOf): array {
    $source = (string) @file_get_contents($check['file']);
    [$namespace, $uses] = code_index_imports($source);
    $own = $fullClassOf($check['file']);
    $models = [];
    $queue = [$check['name']];
    $read = [];

    while ($queue !== [] && count($read) < 20) {
        $name = array_shift($queue);

        if (isset($read[strtolower($name)]) || ($function = $bodyOf($check['file'], $name)) === null) {
            continue;
        }

        $read[strtolower($name)] = true;
        preg_match_all('/(?:\$this\s*->|(?:self|static)::)\s*(\w+)\s*\(/', $function['text'], $calls);
        array_push($queue, ...$calls[1]);
        preg_match_all('/(?:\bnew\s+|(?<![\w\\\\$>:]))(\\\\?[A-Z][\w\\\\]*)(?:::(?!class\b)\w|\s*\()/', $function['text'], $names);

        foreach ($names[1] as $written) {
            $class = strtolower(code_index_resolve($written, $namespace, $uses, (string) $own));

            if ($class !== $own && isset($indexed['files'][$class]) && in_array('illuminate\\database\\eloquent\\model', $indexed['ancestors'][$class] ?? [], true)) {
                $models[$class] = true;
            }
        }
    }

    return array_keys($models);
};

/**
 * The relations a model has: its own, its parents' and its traits', each with
 * the class or trait that declares it, by the relation's lower-case name.
 *
 * @return array<string, string>
 */
$relationsOf = function (string $model) use (&$indexed): array {
    $relations = [];
    $declarers = [$model, ...($indexed['ancestors'][$model] ?? [])];

    // The traits the model and its parents use, as their class bodies name them.
    foreach ($declarers as $declarer) {
        $source = isset($indexed['files'][$declarer]) ? (string) @file_get_contents($indexed['files'][$declarer]) : '';
        $body = substr($source, (int) strpos($source, '{'));
        [$namespace, $uses] = code_index_imports($source);
        preg_match_all('/^\s*use\s+([\w\\\\,\s]+);/m', $body, $traits);

        foreach (preg_split('/\s*,\s*/', implode(',', $traits[1])) ?: [] as $trait) {
            if (trim($trait) !== '') {
                $declarers[] = strtolower(code_index_resolve(trim($trait), $namespace, $uses, ''));
            }
        }
    }

    foreach (array_unique($declarers) as $declarer) {
        $source = isset($indexed['files'][$declarer]) ? (string) @file_get_contents($indexed['files'][$declarer]) : '';

        foreach (array_keys(code_index_relations_in($source)[$declarer] ?? []) as $relation) {
            $relations[$relation] ??= $declarer;
        }
    }

    return $relations;
};

/** Every static call that adds a row, in app/: the file, the line, the class as written and the method. */
$creations = function () use ($sh): array {
    static $found = null;

    if ($found !== null) {
        return $found;
    }

    $found = [];

    foreach (array_filter(explode("\n", $sh(['grep', '-n', '-E', '::('.CREATES.')\(', '--', 'app']))) as $hit) {
        if (preg_match('/^([^:]+):(\d+):(.*)$/', $hit, $match) !== 1 || preg_match('#^\s*(\*|//|/\*|\#)#', $match[3]) === 1) {
            continue;
        }

        preg_match_all('/(?<![\w\\\\$])(\\\\?[A-Za-z_][\w\\\\]*)::('.CREATES.')\s*\(/', $match[3], $calls, PREG_SET_ORDER);

        foreach ($calls as $call) {
            if (! in_array(strtolower($call[1]), ['self', 'static', 'parent'], true)) {
                $found[] = ['file' => $match[1], 'line' => (int) $match[2], 'class' => $call[1], 'method' => $call[2]];
            }
        }
    }

    return $found;
};

/**
 * The methods outside a model that add to it: through one of its relations, such
 * as ->participants()->attach(, or with its own Model::create(, however the file
 * names the class.
 *
 * @return array<string, array{file: string, name: string, line: int, what: string, note: string}> by the index's name of the method
 */
$addersTo = function (string $model) use (&$indexed, $linesOf, $enclosing, $fullClassOf, $writtenClass, $relationsOf, $creations): array {
    $file = $indexed['files'][$model];
    $written = $writtenClass($model);

    $adders = [];
    $add = function (string $file, int $line, string $what, string $note = '') use (&$adders, $enclosing, $fullClassOf): void {
        $name = $enclosing($file, $line);
        $class = $fullClassOf($file);

        if ($name === null || str_starts_with($name, '(closure') || $class === null) {
            return;
        }

        $adders["{$class}::".strtolower($name)] ??= ['file' => $file, 'name' => $name, 'line' => $line, 'what' => $what, 'note' => $note];
    };

    // The methods that use the model itself: a parent's or a trait's relation is called on
    // every class that has it, and only a method that works with this model calls it on this one.
    $users = [];

    foreach ($indexed['callers'] as $callee => $callers) {
        if (str_starts_with($callee, "{$model}::")) {
            $users += $callers;
        }
    }

    // Through a relation: its callers in the index, and in each the line that adds.
    foreach ($relationsOf($model) as $relation => $declarer) {
        foreach (array_keys(($indexed['callers']["{$model}::{$relation}"] ?? []) + ($indexed['callers']["{$declarer}::{$relation}"] ?? [])) as $source) {
            [$sourceClass] = explode('::', $source, 2);
            $sourceFile = $indexed['files'][$sourceClass] ?? null;

            if (str_starts_with($source, 'file:') || $sourceClass === $model || $sourceFile === null
                || ($declarer !== $model && ! isset($indexed['callers']["{$model}::{$relation}"][$source]) && ! isset($users[$source]))) {
                continue;
            }

            $text = implode('', $linesOf($sourceFile));

            if (preg_match_all('/->\s*'.preg_quote($relation, '/').'\s*\(\s*\)\s*->\s*('.ADDS.')\s*\(/i', $text, $found, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            // The index says the method calls this relation on the model, not on which line:
            // where it also calls one of the same name on another class, either may be the one.
            $others = [];

            foreach ($indexed['callers'] as $callee => $callers) {
                if (isset($callers[$source]) && str_ends_with($callee, "::{$relation}") && ! in_array(explode('::', $callee, 2)[0], [$model, $declarer], true)) {
                    $others[] = $writtenClass(explode('::', $callee, 2)[0]);
                }
            }

            foreach ($found[0] as [$match, $offset]) {
                $line = substr_count(substr($text, 0, $offset), "\n") + 1;

                if ("{$fullClassOf($sourceFile)}::".strtolower((string) $enclosing($sourceFile, $line)) === $source) {
                    $add($sourceFile, $line, "{$written}->".ltrim((string) preg_replace('/\s+/', '', $match), '->').')',
                        $others === [] ? '' : ', or on `'.implode('`, `', array_unique($others)).'`, which it calls it on as well');
                }
            }
        }
    }

    // With the model's own static call, however the file names the class.
    foreach ($creations() as $creation) {
        if ($creation['file'] === $file) {
            continue;
        }

        [$namespace, $uses] = code_index_imports(implode('', $linesOf($creation['file'])));

        if (strtolower(code_index_resolve($creation['class'], $namespace, $uses, '')) === $model) {
            $add($creation['file'], $creation['line'], "{$written}::{$creation['method']}()");
        }
    }

    return $adders;
};

$out = [];

foreach ($checks as $key => $check) {
    $class = $classOf($check['file']);
    $fullClass = $indexed === null ? null : $fullClassOf($check['file']);
    $reaches = fn (string $method): array => $fullClass === null ? $reach($method, $class) : $reachIndexed($fullClass.'::'.strtolower($method));
    $entries = $reaches($check['name']);
    $lines = @file($check['file']) ?: [];
    $without = [];

    // The class's effects: its methods that write, and who reaches them without the check.
    foreach ($functions($check['file']) as $function) {
        $body = implode('', array_slice($lines, $function['start'] - 1, $function['end'] - $function['start'] + 1));

        if ($function['name'] === $check['name'] || str_starts_with($function['name'], '(closure') || preg_match(WRITES, $body) !== 1) {
            continue;
        }

        // In a controller or a component the class's other public actions are entry points
        // themselves: one that writes, guards nothing and reaches the check by no path skips it.
        if (preg_match(ENTRY, $check['file']) === 1 && $isPublic($check['file'], $function['name'])
            && ! isset($entries["{$check['file']}::{$function['name']}"])
            && preg_match(GUARD, $body) !== 1 && preg_match('/(->|::)'.preg_quote($check['name'], '/').'\s*\(/', $body) !== 1) {
            $without["{$check['file']}::{$function['name']}"] = ['via' => [$function['name']], 'distance' => 0];
        }

        if (in_array($function['name'], COMMON, true)) {
            continue;
        }

        foreach (array_diff_key($reaches($function['name']), $entries) as $entry => $distance) {
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
            $out[] = $hit['distance'] === 0
                ? "  - `{$entry}`, which writes itself, with no guard of its own"
                : "  - `{$entry}`, through `".implode('`, `', array_unique($hit['via'])).'`';
        }

        if (count($without) > LISTED) {
            $out[] = '  - and '.(count($without) - LISTED).' more, further from the write.';
        }
    }

    // With the index: the data the check reads, and the ways in that add to it without the check.
    if ($indexed === null) {
        continue;
    }

    $models = $modelsReadBy($check);
    $adding = [];
    $central = [];

    foreach ($models as $model) {
        $adders = $addersTo($model);

        // A model the whole application adds to says nothing about this check in particular.
        if (count($adders) > ADDERS) {
            $central[] = "`{$writtenClass($model)}` (".count($adders).' methods)';

            continue;
        }

        foreach ($adders as $symbol => $adder) {
            $own = $bodyOf($adder['file'], $adder['name'])['text'] ?? '';

            // An entry point itself, unless it guards or checks in its own body.
            if (preg_match(ENTRY, $adder['file']) === 1 && $isPublic($adder['file'], $adder['name']) && ! isset($entries["{$adder['file']}::{$adder['name']}"])
                && preg_match(GUARD, $own) !== 1 && preg_match('/(->|::)'.preg_quote($check['name'], '/').'\s*\(/', $own) !== 1) {
                $adding["{$adder['file']}::{$adder['name']}"] ??= ['adder' => $adder, 'distance' => 0];
            }

            foreach (array_diff_key($reachIndexed($symbol), $entries) as $entry => $distance) {
                if (! isset($adding[$entry]) || $adding[$entry]['distance'] > $distance) {
                    $adding[$entry] = ['adder' => $adder, 'distance' => $distance];
                }
            }
        }
    }

    if ($adding === []) {
        if ($central !== []) {
            $out[] = "  `{$check['name']}` reads ".implode(', ', $central).', added to in too many places to list.';
        }

        continue;
    }

    uasort($adding, fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);
    $read = implode(', ', array_map(fn (string $model): string => "`{$writtenClass($model)}`", $models));
    $read .= $central === [] ? '' : ' (of which '.implode(', ', $central).' are added to in too many places to list)';

    if (count($adding) > DATA_LISTED) {
        $out[] = "  `{$check['name']}` reads {$read}, and ".count($adding)." entry points add to it without passing `{$check['name']}`: too many to list, so this says nothing about any one of them.";

        continue;
    }

    $out[] = "  `{$check['name']}` reads {$read}. These add to it without passing `{$check['name']}`:";

    foreach ($adding as $entry => ['adder' => $adder, 'distance' => $distance]) {
        $out[] = $distance === 0
            ? "  - `{$entry}`, with `{$adder['what']}` at line {$adder['line']}{$adder['note']}"
            : "  - `{$entry}`, through `{$adder['name']}`, with `{$adder['what']}` at `{$adder['file']}:{$adder['line']}`{$adder['note']}";
    }
}

$text = implode("\n", $out);

if (strlen($text) > BUDGET) {
    $text = substr($text, 0, strrpos(substr($text, 0, BUDGET), "\n") ?: BUDGET)."\n  - (cut short)";
}

echo "\nREACH:\n\n";
echo $indexed !== null
    ? 'Worked out from the code before you started, from a code index that resolves each call by the type of what it is called on: a path listed is one the code takes. A call on a value whose type is not known (mixed) is not in the index, so a path can be missing; search for one you expect.'
    : 'Worked out from the code before you started, by method name: a call to ->name( is taken to reach every method of that name, so this is a floor that can also name a path that does not apply. Verify each before you rely on it.';
echo ' For each check this change adds or changes, it lists the entry points (controllers, Livewire components, jobs, commands, routes, views) that reach it, and the entry points that reach a method of the same class that writes without passing the check.'.($indexed !== null ? ' With the index it also lists the models the check reads, and the entry points that add to them, through a relation or with create, without passing the check: another way into what the check guards.' : '')." Where the change brings in a rule about who may do something, decide for each of those whether the rule has to hold there too: one that skips it is this change's finding, though the path is old.\n\n";
echo $text."\n";
