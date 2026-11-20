<?php

use App\Models\Repository;
use App\Runners\Runners;
use App\Services\PlatformSettings;
use App\Services\SetupCheck;

beforeEach(function () {
    config(['review.default_runner' => null, 'review.docker.enabled' => true, 'review.diff.enabled' => true, 'review.cloudflare.local' => false]);
});

/** The checks the setup page lists, by title. */
function setupChecks(): Illuminate\Support\Collection
{
    return collect(app(SetupCheck::class)->groups())->flatMap(fn (array $group): array => $group['checks'])->keyBy('title');
}

it('reviews from the diff, not on Docker, while nothing is picked and no sandbox is set up', function () {
    expect(app(Runners::class)->default())->toBe('diff');
});

it('takes a Cloudflare sandbox once it is set up, before the diff and Docker', function () {
    config(['review.cloudflare.local' => true]);

    expect(app(Runners::class)->default())->toBe('cloudflare');

    // What is configured, or picked, still goes first.
    config(['review.default_runner' => 'docker']);

    expect(app(Runners::class)->default())->toBe('docker');

    config(['review.default_runner' => null]);
    app(PlatformSettings::class)->save(['runner' => 'diff']);

    expect(app(Runners::class)->default())->toBe('diff');
});

it('says on the setup page when repositories review on Docker while sandboxes are set up', function () {
    Repository::factory()->create(['review_runner' => 'docker']);
    Repository::factory()->create(['review_runner' => null]);

    // No sandbox: nothing to say.
    expect(setupChecks()->keys()->filter(fn (string $title): bool => str_contains($title, 'Docker'))->all())->toBe([]);

    config(['review.cloudflare.local' => true]);
    $check = setupChecks()['A repository reviews on Docker'];

    expect($check['state'])->toBe(SetupCheck::NOTE)
        ->and($check['fix'])->toContain('Runs on');

    // With the platform on Docker, both do, and the platform's pick is the fix.
    app(PlatformSettings::class)->save(['runner' => 'docker']);
    $check = setupChecks()['2 repositories review on Docker'];

    expect($check['fix'])->toContain('Settings → Reviews run on');

    app(PlatformSettings::class)->save(['runner' => 'cloudflare']);
    Repository::query()->update(['review_runner' => null]);

    expect(setupChecks()['Reviews that read the code run in a sandbox']['state'])->toBe(SetupCheck::OK);
});
