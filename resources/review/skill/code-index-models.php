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
 * known by the name it is called with: active. A relation is a method that
 * returns $this->hasMany(Lesson::class) or the like, and is known by the class or
 * trait that declares it.
 *
 * @return array{scopes: list<string>, relations: array<string, array<string, array{relation: string, related: string, many: bool}>>}
 */
function code_index_models(string $directory): array
{
    $scopes = [];
    $relations = [];

    if (! is_dir($directory)) {
        return ['scopes' => [], 'relations' => []];
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match_all('/function\s+scope(\w+)\s*\(|#\[\s*\\\\?(?:Illuminate\\\\Database\\\\Eloquent\\\\Attributes\\\\)?Scope\s*\]\s*(?:(?:public|protected|private|static|final)\s+)*function\s+(\w+)\s*\(/i', $source, $found, PREG_SET_ORDER) > 0) {
            foreach ($found as $match) {
                $scopes[] = strtolower($match[1] !== '' ? $match[1] : $match[2]);
            }
        }

        foreach (code_index_relations_in($source) as $class => $methods) {
            $relations[$class] = array_merge($relations[$class] ?? [], $methods);
        }
    }

    return ['scopes' => array_values(array_unique($scopes)), 'relations' => $relations];
}

/**
 * The relations one file declares, by the lower-case name of its class or trait
 * and of each method.
 *
 * @return array<string, array<string, array{relation: string, related: string, many: bool}>>
 */
function code_index_relations_in(string $source): array
{
    if (preg_match('/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|trait)\s+(\w+)/mi', $source, $declared) !== 1) {
        return [];
    }

    [$namespace, $uses] = code_index_imports($source);
    $class = ltrim("{$namespace}\\{$declared[1]}", '\\');
    $relations = [];
    preg_match_all('/function\s+(\w+)\s*\(\s*\)[^{;]*\{/i', $source, $methods, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

    foreach ($methods as $method) {
        $body = code_index_body($source, $method[0][1] + strlen($method[0][0]) - 1);

        if (preg_match('/\$this\s*->\s*(\w+)\s*\(\s*([\w\\\\]+)::class/i', $body, $call) !== 1 || ! isset(CODE_INDEX_RELATIONS[strtolower($call[1])])) {
            continue;
        }

        [$relation, $many] = CODE_INDEX_RELATIONS[strtolower($call[1])];
        $relations[strtolower($method[1][0])] = [
            'relation' => "Illuminate\\Database\\Eloquent\\Relations\\{$relation}",
            'related' => code_index_resolve($call[2], $namespace, $uses, $class),
            'many' => $many,
        ];
    }

    return $relations === [] ? [] : [strtolower($class) => $relations];
}

/**
 * A file's namespace, and the classes it imports by the lower-case name it uses
 * for each.
 *
 * @return array{0: string, 1: array<string, string>}
 */
function code_index_imports(string $source): array
{
    $namespace = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $source, $found) === 1 ? $found[1] : '';
    $uses = [];

    // Top-level imports only: the ones inside a class are traits.
    preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/mi', $source, $imports, PREG_SET_ORDER);

    foreach ($imports as $import) {
        $alias = $import[2] ?? '';
        $uses[strtolower($alias !== '' ? $alias : substr((string) strrchr('\\'.$import[1], '\\'), 1))] = ltrim($import[1], '\\');
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
