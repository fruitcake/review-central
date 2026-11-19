<?php

use App\Models\ReviewInstruction;
use App\Services\ReviewInstructions;
use App\Support\LineDiff;
use Illuminate\Support\Facades\File;

it('diffs two texts by line, with a few lines around each change', function () {
    $old = implode("\n", ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j']);
    $new = implode("\n", ['a', 'b', 'C', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k']);

    expect(LineDiff::lines($old, $new, context: 1))->toBe([
        ['type' => ' ', 'text' => 'b'],
        ['type' => '-', 'text' => 'c'],
        ['type' => '+', 'text' => 'C'],
        ['type' => ' ', 'text' => 'd'],
        ['type' => '@@', 'text' => ''],
        ['type' => ' ', 'text' => 'j'],
        ['type' => '+', 'text' => 'k'],
    ]);
});

it('offers what a release changes, leaves what someone edited unpicked, and takes only what is asked', function () {
    ReviewInstructions::import(resource_path(ReviewInstructions::BUNDLED_DIRECTORY));

    // Everything is this release's.
    expect(ReviewInstructions::updates())->toBe([]);

    // The method as an older release shipped it, untouched here since: safe to take.
    ReviewInstruction::query()->whereNull('tenant_id')->where('key', 'method')->update(['body' => 'Old method.', 'shipped_hash' => hash('sha256', 'Old method.')]);
    // The Laravel guide as someone here edited it.
    ReviewInstruction::query()->whereNull('tenant_id')->where('key', 'stack-laravel')->update(['body' => 'Our own Laravel rules.']);
    // A guide this release added.
    ReviewInstruction::query()->whereNull('tenant_id')->where('key', 'stack-drupal')->delete();

    $updates = collect(ReviewInstructions::updates())->keyBy('key');

    expect($updates->map(fn (array $update): string => $update['state'])->sortKeys()->all())->toBe(['method' => 'outdated', 'stack-drupal' => 'new', 'stack-laravel' => 'edited'])
        ->and(collect($updates['method']['diff'])->firstWhere('type', '-')['text'])->toBe('Old method.');

    expect(ReviewInstructions::applyUpdates(['method', 'stack-drupal']))->toBe(['method', 'stack-drupal'])
        ->and(ReviewInstructions::base('stack-laravel'))->toBe('Our own Laravel rules.')
        ->and(array_column(ReviewInstructions::updates(), 'key'))->toBe(['stack-laravel']);
});

it('takes instructions imported from a directory of one\'s own for one\'s own words, not an older release', function () {
    $own = storage_path('framework/testing/own-instructions');
    File::ensureDirectoryExists($own);
    File::put("{$own}/method.md", "# Our method\n\nReview it our way.");

    ReviewInstructions::import($own);

    $update = collect(ReviewInstructions::updates())->firstWhere('key', 'method');

    expect(ReviewInstruction::query()->whereNull('tenant_id')->where('key', 'method')->value('shipped_hash'))->toBeNull()
        ->and($update['state'])->toBe('edited');

    File::deleteDirectory($own);
});
