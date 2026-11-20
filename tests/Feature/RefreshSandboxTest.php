<?php

use App\Jobs\PrepareCloudflareSandbox;
use App\Runners\Container\CloudflareSandbox;
use App\Services\PlatformSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['review.cloudflare.url' => 'https://sandbox.example.com', 'review.cloudflare.token' => 'worker-token', 'review.cloudflare.setup' => true]);
});

function npmAnswers(string $claude, string $codex): void
{
    Http::fake([
        'registry.npmjs.org/@anthropic-ai%2Fclaude-code/latest' => Http::response(['version' => $claude]),
        'registry.npmjs.org/@openai%2Fcodex/latest' => Http::response(['version' => $codex]),
        'sandbox.example.com/prepare' => Http::response(['ok' => true, 'made' => true, 'seconds' => 200]),
    ]);
}

it('installs the CLIs at the versions of the newest snapshot, and a new release is a new setup', function () {
    $unpinned = (string) CloudflareSandbox::setupScript();

    expect($unpinned)->toContain('for package in @anthropic-ai/claude-code @openai/codex ');

    $pinned = (string) CloudflareSandbox::setupScript(['@anthropic-ai/claude-code' => '2.1.329', '@openai/codex' => '0.9.1']);

    expect($pinned)->toContain('for package in "@anthropic-ai/claude-code@2.1.329" "@openai/codex@0.9.1" "@alibaba-group/open-code-review@~1.12"')
        ->and(CloudflareSandbox::setupVersion($pinned))->not->toBe(CloudflareSandbox::setupVersion($unpinned))
        // Only a version, never anything a registry could slip in.
        ->and((string) CloudflareSandbox::setupScript(['@anthropic-ai/claude-code' => '1.0; rm -rf /']))->toBe($unpinned);
});

it('prepares a snapshot for a new release, and has reviews install it only once that is made', function () {
    Queue::fake();
    npmAnswers('2.1.329', '0.9.1');

    $this->artisan('review:refresh-sandbox')->expectsOutputToContain('@anthropic-ai/claude-code 2.1.329')->assertSuccessful();

    Queue::assertPushed(PrepareCloudflareSandbox::class, fn (PrepareCloudflareSandbox $job): bool => $job->versions === ['@anthropic-ai/claude-code' => '2.1.329', '@openai/codex' => '0.9.1']);
    // Not yet: the snapshot is still being made.
    expect(CloudflareSandbox::pinnedClis())->toBe([]);

    $job = new PrepareCloudflareSandbox(['@anthropic-ai/claude-code' => '2.1.329', '@openai/codex' => '0.9.1']);
    $job->handle(app(CloudflareSandbox::class), app(PlatformSettings::class));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sandbox.example.com/prepare' && str_contains($request['setup'], '"@anthropic-ai/claude-code@2.1.329"'));
    expect(CloudflareSandbox::pinnedClis())->toBe(['@anthropic-ai/claude-code' => '2.1.329', '@openai/codex' => '0.9.1'])
        ->and(CloudflareSandbox::setupScript())->toContain('"@anthropic-ai/claude-code@2.1.329"');

    // The same release again: nothing to do.
    Queue::fake();
    $this->artisan('review:refresh-sandbox')->expectsOutputToContain('newest CLIs')->assertSuccessful();
    Queue::assertNothingPushed();
});

it('keeps the versions of the last snapshot when a new one cannot be made', function () {
    app(PlatformSettings::class)->save([PlatformSettings::CLOUDFLARE_CLIS => ['@anthropic-ai/claude-code' => '2.1.328']]);
    Http::fake(['sandbox.example.com/prepare' => Http::response(['error' => 'No room.'], 500)]);

    (new PrepareCloudflareSandbox(['@anthropic-ai/claude-code' => '2.1.329']))->handle(app(CloudflareSandbox::class), app(PlatformSettings::class));

    expect(CloudflareSandbox::pinnedClis())->toBe(['@anthropic-ai/claude-code' => '2.1.328']);
});

it('leaves an installation without Cloudflare sandboxes be', function () {
    config(['review.cloudflare.url' => null, 'review.cloudflare.token' => null, 'review.cloudflare.local' => false]);
    Queue::fake();
    Http::fake();

    $this->artisan('review:refresh-sandbox')->assertSuccessful();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
