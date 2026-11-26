<?php

/**
 * The vendor/ directories the code index needs: the packages the project's own code
 * names in a `use` statement, and everything those require, as Composer installed
 * them. Mago spends most of an index reading vendor/; a project's own code reaches
 * only part of it, and the types it resolves come from that part.
 *
 *   php code-index-includes.php <path> ...     (in the checkout, the project's own paths)
 *
 * Prints one directory per line, or nothing when vendor/composer/installed.json is not
 * there; the caller then takes all of vendor/.
 */
$paths = array_slice($argv, 1);
$installed = json_decode((string) @file_get_contents('vendor/composer/installed.json'), true);
$packages = is_array($installed) ? ($installed['packages'] ?? $installed) : null;

if (! is_array($packages) || $packages === []) {
    exit(0);
}

// What only a developer installs (PHPUnit, PHPStan): the code under review calls none of it.
$development = array_fill_keys((array) ($installed['dev-package-names'] ?? []), true);
$prefixes = [];
$global = [];
$requires = [];
$directories = [];

foreach ($packages as $package) {
    $name = (string) ($package['name'] ?? '');

    if ($name === '') {
        continue;
    }

    $requires[$name] = array_keys((array) ($package['require'] ?? []));
    // Where Composer put it: install-path is relative to vendor/composer.
    $directories[$name] = isset($package['install-path'])
        ? 'vendor/'.ltrim(preg_replace('#^\.\./#', '', (string) $package['install-path']), '/')
        : "vendor/{$name}";

    $namespaced = false;

    foreach (['psr-4', 'psr-0'] as $kind) {
        foreach (array_keys((array) ($package['autoload'][$kind] ?? [])) as $namespace) {
            if ($namespace !== '') {
                // Several packages can share one, as yajra's datatables packages do.
                $prefixes[$namespace][$name] = true;
                $namespaced = true;
            }
        }
    }

    // A package of classes without a namespace, such as XLSXWriter or Google_Client, declares
    // global names no import gives away: it is taken whole, unless only a developer uses it.
    if (! $namespaced && ((array) ($package['autoload']['classmap'] ?? [])) !== [] && ! isset($development[$name])) {
        $global[$name] = true;
    }
}

// The names the project's own code uses: imported, or written out in full, in code or in a
// docblock, such as \Spatie\DbDumper\Databases\MySql::create().
$used = [];

foreach ($paths as $path) {
    if (! is_dir($path)) {
        continue;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $text = (string) @file_get_contents($file->getPathname());

        if (preg_match_all('/^\s*use\s+\\\\?([\w\\\\]+)/m', $text, $matches) > 0) {
            $used += array_fill_keys($matches[1], true);
        }

        if (preg_match_all('/\\\\([A-Z]\w*(?:\\\\\w+)+)/', $text, $matches) > 0) {
            $used += array_fill_keys($matches[1], true);
        }
    }
}

// Each import to the package whose namespace prefix matches it longest.
krsort($prefixes);
$wanted = [];

foreach (array_keys($used) as $import) {
    foreach ($prefixes as $namespace => $names) {
        if (str_starts_with($import, $namespace)) {
            $wanted += $names;

            break;
        }
    }
}

$wanted += $global;

// And what those require, as far as Composer installed it.
$queue = array_keys($wanted);

while ($queue !== []) {
    foreach ($requires[array_pop($queue)] ?? [] as $required) {
        if (isset($requires[$required]) && ! isset($wanted[$required])) {
            $wanted[$required] = true;
            $queue[] = $required;
        }
    }
}

foreach (array_keys($wanted) as $name) {
    if (is_dir($directories[$name])) {
        echo $directories[$name], "\n";
    }
}
