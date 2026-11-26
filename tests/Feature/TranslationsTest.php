<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/*
 * Our own text is the English key of __() and is translated in lang/<locale>.json.
 * These keep each translation file in step with the code: every key the code uses
 * has a translation, with the same placeholders, and none is left that it does not use.
 */

/**
 * Every literal key passed to __() in the app and its views.
 *
 * @return array<string, string> key => the first file it is used in
 */
function translationKeysInCode(): array
{
    $files = collect([...File::allFiles(app_path()), ...File::allFiles(resource_path('views'))])
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.php'));

    $keys = [];

    foreach ($files as $file) {
        preg_match_all('/(?<![\w>:$])__\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\$]|\\\\.)*)")\s*[,)]/s', $file->getContents(), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = ($match[2] ?? '') !== '' ? stripcslashes($match[2]) : str_replace(["\\'", '\\\\'], ["'", '\\'], $match[1]);
            $keys[$key] ??= $file->getRelativePathname();
        }
    }

    return $keys;
}

/**
 * @return list<string>
 */
function translationPlaceholders(string $text): array
{
    preg_match_all('/:([a-z_]+)/i', $text, $matches);

    return collect($matches[1])->map(fn (string $name): string => strtolower($name))->unique()->sort()->values()->all();
}

// Listed before the app boots, so from this file's place rather than lang_path().
dataset('translation files', fn (): array => collect(glob(dirname(__DIR__, 2).'/lang/*.json') ?: [])
    ->mapWithKeys(fn (string $path): array => [basename($path) => [$path]])
    ->all());

it('translates every text the code passes to __()', function (string $path) {
    $translations = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    $missing = collect(translationKeysInCode())
        ->reject(fn (string $file, string $key): bool => is_string($translations[$key] ?? null) && $translations[$key] !== '')
        ->map(fn (string $file, string $key): string => "{$key} ({$file})");

    expect($missing->values()->all())->toBe([], basename($path).' has no translation for these.');
})->with('translation files');

it('keeps the placeholders of a text in its translation', function (string $path) {
    $translations = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    $different = collect($translations)
        ->filter(fn (string $translation, string $key): bool => translationPlaceholders($translation) !== translationPlaceholders($key))
        ->keys();

    expect($different->all())->toBe([], basename($path).' translates these with other placeholders.');
})->with('translation files');

it('keeps no translation of a text the code no longer uses', function (string $path) {
    $translations = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    $unused = array_values(array_diff(array_keys($translations), array_keys(translationKeysInCode())));

    expect($unused)->toBe([], basename($path).' translates these, which the code does not use (any more).');
})->with('translation files');
