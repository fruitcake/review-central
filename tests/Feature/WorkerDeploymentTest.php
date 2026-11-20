<?php

use App\Filament\Admin\Pages\Settings;
use App\Filament\Admin\Pages\Setup;
use App\Jobs\DeployCloudflareWorker;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Runners\Container\WorkerDeployment;
use App\Services\PlatformSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Livewire\livewire;

const ACCOUNT = '0123456789abcdef0123456789abcdef';

beforeEach(function () {
    config([
        // The Worker's name as config/review.php has it without REVIEW_CLOUDFLARE_WORKER.
        'review.cloudflare.account' => null, 'review.cloudflare.api_token' => null,
        'review.cloudflare.url' => null, 'review.cloudflare.token' => null,
        'filesystems.disks.r2.key' => null, 'filesystems.disks.r2.secret' => null, 'filesystems.disks.r2.bucket' => null, 'filesystems.disks.r2.endpoint' => null,
    ]);
});

function cloudflareSettings(array $values = []): PlatformSettings
{
    $settings = app(PlatformSettings::class);
    $settings->save($values + [
        PlatformSettings::CLOUDFLARE_ACCOUNT => ACCOUNT,
        PlatformSettings::CLOUDFLARE_API_TOKEN => 'cf-api-token',
        PlatformSettings::CLOUDFLARE_WORKER => 'acme-sandbox',
        PlatformSettings::CLOUDFLARE_BUCKET => 'acme-reviews',
    ]);
    $settings->applyCloudflare();

    return $settings;
}

it('reads the Worker\'s configuration with this installation\'s names in it', function () {
    $configuration = WorkerDeployment::configuration('acme-sandbox', 'acme-reviews');

    expect($configuration['name'])->toBe('acme-sandbox')
        ->and($configuration['r2_buckets'])->toBe([['bucket_name' => 'acme-reviews', 'binding' => 'BUCKET']])
        ->and($configuration['main'])->toBe('src/index.ts')
        ->and($configuration['containers'][0]['class_name'])->toBe('ReviewSandbox');
});

it('keeps the secrets encrypted, keeps one left empty, and fills only what the environment leaves open', function () {
    $settings = cloudflareSettings([PlatformSettings::R2_ACCESS_KEY_ID => 'r2-key', PlatformSettings::R2_SECRET_ACCESS_KEY => 'r2-secret']);

    expect(PlatformSetting::query()->find(PlatformSettings::CLOUDFLARE_API_TOKEN)->value)->not->toContain('cf-api-token')
        ->and($settings->secret(PlatformSettings::CLOUDFLARE_API_TOKEN))->toBe('cf-api-token');

    $settings->save([PlatformSettings::CLOUDFLARE_API_TOKEN => '']);

    expect(app(PlatformSettings::class)->secret(PlatformSettings::CLOUDFLARE_API_TOKEN))->toBe('cf-api-token')
        ->and(config('review.cloudflare.account'))->toBe(ACCOUNT)
        ->and(config('review.cloudflare.worker'))->toBe('acme-sandbox')
        ->and(config('filesystems.disks.r2.bucket'))->toBe('acme-reviews')
        ->and(config('filesystems.disks.r2.endpoint'))->toBe('https://'.ACCOUNT.'.r2.cloudflarestorage.com')
        ->and(config('filesystems.disks.r2.secret'))->toBe('r2-secret');

    // What the environment sets holds.
    config(['review.cloudflare.worker' => 'from-env']);
    $settings->save([PlatformSettings::CLOUDFLARE_WORKER => 'changed']);
    $settings->applyCloudflare();

    expect(config('review.cloudflare.worker'))->toBe('from-env');
});

it('deploys the Worker with Wrangler, and keeps its address and a new token', function () {
    expect(config('review.cloudflare.worker'))->toBeNull();

    cloudflareSettings();
    $deployed = null;
    Process::fake([
        "'node' '--version'" => Process::result('v22.11.0'),
        "'npm' 'ci'*" => Process::result('added 80 packages'),
        "'npx' 'wrangler' 'r2'*" => Process::result(errorOutput: 'The bucket you tried to create already exists, and you own it.', exitCode: 1),
        "'npx' 'wrangler' 'deploy'*" => function () use (&$deployed) {
            $deployed = json_decode(File::get(WorkerDeployment::directory().'/'.WorkerDeployment::CONFIG), true);

            return Process::result("Uploaded acme-sandbox\nDeployed acme-sandbox triggers\n  https://acme-sandbox.acme.workers.dev\n");
        },
        "'npx' 'wrangler' 'secret'*" => Process::result('Success! Uploaded secret API_TOKEN'),
    ]);

    $lines = [];
    $result = app(WorkerDeployment::class)->deploy(function (string $line) use (&$lines) {
        $lines[] = $line;
    });

    $token = app(PlatformSettings::class)->secret(PlatformSettings::CLOUDFLARE_TOKEN);

    expect($result)->toBe(['ok' => true, 'url' => 'https://acme-sandbox.acme.workers.dev', 'error' => null])
        ->and($token)->toMatch('/^[a-f0-9]{64}$/')
        ->and(app(PlatformSettings::class)->text(PlatformSettings::CLOUDFLARE_URL))->toBe('https://acme-sandbox.acme.workers.dev')
        ->and(config('review.cloudflare.url'))->toBe('https://acme-sandbox.acme.workers.dev')
        ->and(config('review.cloudflare.token'))->toBe($token)
        ->and($lines)->toContain('Deployed: https://acme-sandbox.acme.workers.dev')
        ->and(Cache::get(WorkerDeployment::RESULT)['ok'])->toBeTrue()
        // With the names under Settings, never another installation's default.
        ->and($deployed['name'])->toBe('acme-sandbox')
        ->and($deployed['r2_buckets'][0]['bucket_name'])->toBe('acme-reviews')
        // The configuration with this installation's names is gone again.
        ->and(File::exists(WorkerDeployment::directory().'/'.WorkerDeployment::CONFIG))->toBeFalse();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['npx', 'wrangler', 'deploy', '--config', WorkerDeployment::CONFIG]
        && $process->environment['CLOUDFLARE_API_TOKEN'] === 'cf-api-token'
        && $process->environment['CLOUDFLARE_ACCOUNT_ID'] === ACCOUNT);
    // The token goes in on its input, never as an argument.
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['npx', 'wrangler', 'secret', 'put', 'API_TOKEN', '--config', WorkerDeployment::CONFIG]
        && $process->input === $token);
});

it('says why a deploy failed, with what Wrangler printed', function () {
    cloudflareSettings();
    Process::fake([
        "'node' '--version'" => Process::result('v22.11.0'),
        "'npm' 'ci'*" => Process::result('added 80 packages'),
        "'npx' 'wrangler' 'r2'*" => Process::result('Created bucket'),
        "'npx' 'wrangler' 'deploy'*" => Process::result(errorOutput: 'Authentication error [code: 10000]', exitCode: 1),
    ]);

    $result = app(WorkerDeployment::class)->deploy();

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toBe('Could not deploy the Worker (exit 1).')
        ->and(Cache::get(WorkerDeployment::RESULT)['log'])->toContain('Authentication error')
        ->and(app(PlatformSettings::class)->text(PlatformSettings::CLOUDFLARE_URL))->toBeNull();
});

it('refuses an old Node, and a deploy without an account and a token', function () {
    expect(app(WorkerDeployment::class)->deploy()['error'])->toContain('no Cloudflare account and API token');

    cloudflareSettings();
    Process::fake(["'node' '--version'" => Process::result('v18.19.0')]);

    expect(app(WorkerDeployment::class)->deploy()['error'])->toBe('Wrangler needs Node 20 or later; this machine has v18.19.0.');
    $this->artisan('review:deploy-worker')->assertFailed();
});

it('is set up under Settings and deployed from the setup page', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    livewire(Setup::class)->assertActionHidden('deployWorker');

    livewire(Settings::class)
        ->fillForm([PlatformSettings::CLOUDFLARE_ACCOUNT => ACCOUNT, PlatformSettings::CLOUDFLARE_API_TOKEN => 'cf-api-token'])
        ->call('save')
        ->assertHasNoFormErrors();

    app(PlatformSettings::class)->applyCloudflare();

    livewire(Setup::class)->assertActionVisible('deployWorker')->callAction('deployWorker');

    Queue::assertPushed(DeployCloudflareWorker::class);
});

it('keeps the settings out of a configuration cache, where a later change would not take', function () {
    $argv = $_SERVER['argv'];
    $_SERVER['argv'] = ['artisan', 'config:cache'];

    try {
        cloudflareSettings();
    } finally {
        $_SERVER['argv'] = $argv;
    }

    expect(config('review.cloudflare.account'))->toBeNull()
        ->and(config('review.cloudflare.api_token'))->toBeNull();

    app(PlatformSettings::class)->applyCloudflare();

    expect(config('review.cloudflare.account'))->toBe(ACCOUNT);
});

it('tests the Cloudflare setup as it is in use, and says which settings the environment sets instead', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    cloudflareSettings([PlatformSettings::R2_ACCESS_KEY_ID => 'r2-key', PlatformSettings::R2_SECRET_ACCESS_KEY => "  r2-secret\n"]);
    config(['review.cloudflare.url' => 'https://sandbox.example.com', 'review.cloudflare.token' => 'worker-token']);

    // Pasted with a line break, kept without it.
    expect(app(PlatformSettings::class)->secret(PlatformSettings::R2_SECRET_ACCESS_KEY))->toBe('r2-secret');
    Storage::fake('r2');
    $broken = false;
    Http::fake(function (Request $request) use (&$broken) {
        return match (true) {
            $broken => Http::response(['success' => false], 401),
            str_contains($request->url(), '/tokens/verify') => Http::response(['result' => ['status' => 'active']]),
            default => Http::response(['ok' => true]),
        };
    });

    livewire(Settings::class)
        ->assertDontSee('in .env is used instead')
        ->callAction(TestAction::make('testCloudflare')->schemaComponent('cloudflare'))
        ->assertNotified('Cloudflare answers');

    // A key the environment sets is the one in use, whatever is saved here.
    config(['filesystems.disks.r2.key' => 'from-env']);

    livewire(Settings::class)->assertSee('R2_ACCESS_KEY_ID in .env is used instead');

    // A token that was rolled, and a Worker deployed with another one.
    $broken = true;

    livewire(Settings::class)
        ->callAction(TestAction::make('testCloudflare')->schemaComponent('cloudflare'))
        ->assertNotified('Not everything works');

    // Settings checks what is pasted is shaped as the S3 keys are.
    livewire(Settings::class)
        ->fillForm([PlatformSettings::R2_ACCESS_KEY_ID => 'not-a-key', PlatformSettings::R2_SECRET_ACCESS_KEY => 'the-token-value'])
        ->call('save')
        ->assertHasFormErrors([PlatformSettings::R2_ACCESS_KEY_ID => 'regex', PlatformSettings::R2_SECRET_ACCESS_KEY => 'regex']);
});
