<?php

/**
 * What code-index-worker.php needs to know of a project's Eloquent models before
 * Mago analyses it, read from the source the way Larastan reads it: the local
 * scopes, which are called by a name no method has, and the relations, whose
 * related model a method's declared return type mostly leaves out.
 *
 * Plain PHP, with no SDK, so it can be tested apart from the worker.
 */

declare(strict_types=1);

/** The relation methods of a model that give their related model's class as the first argument, and whether they give many. */
const CODE_INDEX_RELATIONS = [
    'hasone' => ['HasOne', false],
    'hasmany' => ['HasMany', true],
    'belongsto' => ['BelongsTo', false],
    'belongstomany' => ['BelongsToMany', true],
    'morphone' => ['MorphOne', false],
    'morphmany' => ['MorphMany', true],
    'morphtomany' => ['MorphToMany', true],
    'morphedbymany' => ['MorphToMany', true],
    'hasonethrough' => ['HasOneThrough', false],
    'hasmanythrough' => ['HasManyThrough', true],
];

/**
 * The local scopes and relations declared in the PHP files under a directory.
 *
 * A scope is scopeActive(), or active() with #[Scope] since Laravel 12, and is
 * known by the name it is called with: active. Which model has it, the worker
 * asks Mago: a scopeActive() of the model, or an active() of a class that marks
 * it with #[Scope]. A relation is a method that returns $this->hasMany(Lesson::class)
 * or the like, and is known by the class or trait that declares it.
 *
 * @return array{scopes: list<string>, marked: array<string, list<string>>, relations: array<string, array<string, array{method: string, relation: string, related: string, many: bool}>>}
 */
function code_index_models(string $directory): array
{
    $scopes = [];
    $marked = [];
    $relations = [];

    if (! is_dir($directory)) {
        return ['scopes' => [], 'marked' => [], 'relations' => []];
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match_all('/function\s+scope(\w+)\s*\(|#\[\s*\\\\?(?:Illuminate\\\\Database\\\\Eloquent\\\\Attributes\\\\)?Scope\s*\]\s*(?:(?:public|protected|private|static|final)\s+)*function\s+(\w+)\s*\(/i', $source, $found, PREG_SET_ORDER) > 0) {
            foreach ($found as $match) {
                $scopes[] = strtolower(($match[2] ?? '') !== '' ? $match[2] : $match[1]);

                if (($match[2] ?? '') !== '' && ($class = code_index_declared($source)) !== null) {
                    $marked[strtolower($class)][] = strtolower($match[2]);
                }
            }
        }

        foreach (code_index_relations_in($source) as $class => $methods) {
            $relations[$class] = array_merge($relations[$class] ?? [], $methods);
        }
    }

    return ['scopes' => array_values(array_unique($scopes)), 'marked' => $marked, 'relations' => $relations];
}

/**
 * The models config/auth.php can sign users in as: every user provider's model,
 * as App\Models\User::class or as a string, also as env()'s default. Which guard a
 * request uses, middleware such as auth:member can choose, so the user can be any
 * of them. Without the file, or without a model in it, App\Models\User where the
 * application has one.
 *
 * @return list<string>
 */
function code_index_auth_models(string $root): array
{
    $file = "{$root}/config/auth.php";
    $source = is_file($file) ? (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file)) : '';
    [$namespace, $uses] = code_index_imports($source);
    $models = [];

    // 'model' => App\Models\User::class, 'App\Models\User', or either as env('AUTH_MODEL', ...)'s default.
    if (preg_match_all('/[\'"]model[\'"]\s*=>\s*(?:env\s*\([^,]+,\s*)?(?:(\\\\?[\w\\\\]+)::class|([\'"])\\\\{0,2}([\w\\\\]+)\2)/', $source, $found, PREG_SET_ORDER) > 0) {
        foreach ($found as $model) {
            $models[] = ($model[1] ?? '') !== ''
                ? code_index_resolve($model[1], $namespace, $uses, '')
                : (string) preg_replace('/\\\\+/', '\\', $model[3]);
        }
    }

    if ($models === [] && is_file("{$root}/app/Models/User.php")) {
        return ['App\\Models\\User'];
    }

    return array_values(array_unique($models));
}

/** The class or trait a file declares, in full. */
function code_index_declared(string $source): ?string
{
    if (preg_match('/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|trait)\s+(\w+)/mi', $source, $declared) !== 1) {
        return null;
    }

    return ltrim(code_index_imports($source)[0].'\\'.$declared[1], '\\');
}

/**
 * The relations one file declares, by the lower-case name of its class or trait
 * and of each method. A method that returns something made from a relation,
 * such as $this->hasMany(Lesson::class)->get(), or declares another return
 * type, is no relation. A related model named self or static is kept so:
 * which class that is, only the call it is made on says.
 *
 * @return array<string, array<string, array{method: string, relation: string, related: string, many: bool}>>
 */
function code_index_relations_in(string $source): array
{
    $class = code_index_declared($source);

    if ($class === null) {
        return [];
    }

    [$namespace, $uses] = code_index_imports($source);
    $relations = [];
    preg_match_all('/function\s+(\w+)\s*\(\s*\)\s*(?::\s*\??([\w\\\\]+))?[^{;]*\{/i', $source, $methods, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

    foreach ($methods as $method) {
        $declared = strtolower(substr((string) strrchr('\\'.($method[2][0] ?? ''), '\\'), 1));

        if ($declared !== '' && ! in_array($declared, ['relation', 'morphto', ...array_map(strtolower(...), array_column(CODE_INDEX_RELATIONS, 0))], true)) {
            continue;
        }

        $body = code_index_body($source, $method[0][1] + strlen($method[0][0]) - 1);

        if (preg_match('/\breturn\s+\$this\s*->\s*(\w+)\s*\(\s*([\w\\\\]+)::class([^;]*);/i', $body, $call) !== 1
            || ! isset(CODE_INDEX_RELATIONS[strtolower($call[1])])
            || preg_match('/->\s*(get|first|firstOrFail|find|findOrFail|sole|count|exists|doesntExist|pluck|value|sum|min|max|avg|paginate|simplePaginate|cursorPaginate|cursor|lazy|all|toArray|chunk|each|getResults)\s*\(/i', $call[3]) === 1) {
            continue;
        }

        [$relation, $many] = CODE_INDEX_RELATIONS[strtolower($call[1])];
        $relations[strtolower($method[1][0])] = [
            'method' => $method[1][0],
            'relation' => "Illuminate\\Database\\Eloquent\\Relations\\{$relation}",
            'related' => in_array(strtolower($call[2]), ['self', 'static'], true) ? strtolower($call[2]) : code_index_resolve($call[2], $namespace, $uses, $class),
            'many' => $many,
        ];
    }

    return $relations === [] ? [] : [strtolower($class) => $relations];
}

/**
 * A file's namespace, and the classes it imports by the lower-case name it uses
 * for each, grouped imports included.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function code_index_imports(string $source): array
{
    $namespace = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $source, $found) === 1 ? $found[1] : '';
    $uses = [];

    // Top-level imports only: the ones inside a class are traits.
    preg_match_all('/^use\s+([\w\\\\]+?)(?:\\\\\{([^}]*)\}|\s+as\s+(\w+))?\s*;/mi', $source, $imports, PREG_SET_ORDER);

    foreach ($imports as $import) {
        $names = ($import[2] ?? '') !== ''
            ? array_map(fn (string $name): array => preg_split('/\s+as\s+/i', trim($name)) + [1 => null], array_filter(explode(',', $import[2]), fn (string $name): bool => trim($name) !== ''))
            : [['', ($import[3] ?? '') !== '' ? $import[3] : null]];

        foreach ($names as [$name, $alias]) {
            $full = ltrim($import[1].($name !== '' ? '\\'.$name : ''), '\\');
            $uses[strtolower($alias ?? substr((string) strrchr('\\'.$full, '\\'), 1))] = $full;
        }
    }

    return [$namespace, $uses];
}

/**
 * A class name as the file writes it, in full.
 *
 * @param  array<string, string>  $uses
 */
function code_index_resolve(string $name, string $namespace, array $uses, string $self): string
{
    if (str_starts_with($name, '\\')) {
        return ltrim($name, '\\');
    }

    if (in_array(strtolower($name), ['self', 'static'], true)) {
        return $self;
    }

    $parts = explode('\\', $name, 2);
    $first = strtolower($parts[0]);

    if (isset($uses[$first])) {
        return $uses[$first].(isset($parts[1]) ? "\\{$parts[1]}" : '');
    }

    return ltrim("{$namespace}\\{$name}", '\\');
}

/** The body of a method, from its opening brace to the brace that closes it. */
function code_index_body(string $source, int $open): string
{
    $depth = 0;
    $length = strlen($source);

    for ($at = $open; $at < $length; $at++) {
        if ($source[$at] === '{') {
            $depth++;
        } elseif ($source[$at] === '}' && --$depth === 0) {
            return substr($source, $open, $at - $open + 1);
        }
    }

    return substr($source, $open);
}
