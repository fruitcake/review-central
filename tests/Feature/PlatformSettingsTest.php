<?php

use App\Filament\Admin\Pages\Settings;
use App\Filament\Admin\Resources\Tenants\Pages\ManageTenants;
use App\Filament\LocalTime;
use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use App\Runners\Runners;
use App\Services\Currency;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;

use function Pest\Livewire\livewire;

beforeEach(function () {
    ExchangeRate::query()->create(['currency' => 'EUR', 'rate' => 0.8, 'fetched_at' => now()]);
});

it('holds the defaults until an administrator sets something, and what was set after', function () {
    $settings = app(PlatformSettings::class);

    expect($settings->currency())->toBe('USD')->and($settings->timezone())->toBe('UTC');

    $settings->save(['currency' => 'EUR', 'timezone' => 'America/New_York', 'unknown' => 'ignored']);

    // Read again as the next request would, past the cache.
    app()->forgetScopedInstances();

    expect(app(PlatformSettings::class)->currency())->toBe('EUR')
        ->and(app(PlatformSettings::class)->timezone())->toBe('America/New_York')
        ->and(app(PlatformSettings::class)->get('unknown'))->toBeNull();
});

it('shows money and times outside a tenant in the platform\'s currency and zone, and a tenant\'s in its own', function () {
    app(PlatformSettings::class)->save(['currency' => 'EUR', 'timezone' => 'Asia/Tokyo']);
    $tenant = Tenant::factory()->create(['currency' => 'USD', 'timezone' => 'Europe/London']);
    $noon = CarbonImmutable::parse('2026-11-19 12:00:00', 'UTC');

    expect(Currency::show(10))->toBe('€8.00')
        ->and(Currency::show(10, $tenant))->toBe('$10.00')
        ->and(LocalTime::of($noon)->format('H:i'))->toBe('21:00')
        ->and(LocalTime::of($noon, $tenant)->format('H:i'))->toBe('12:00')
        // Filament's own dates follow the same zone.
        ->and(FilamentTimezone::get())->toBe('Asia/Tokyo');
});

it('lets an administrator set them, and starts a new tenant with them', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    config(['review.docker.enabled' => true, 'review.cloudflare.local' => false]);

    livewire(Settings::class)
        ->assertSchemaStateSet(['currency' => 'USD', 'timezone' => 'UTC', 'runner' => null])
        ->fillForm(['currency' => 'EUR', 'timezone' => 'Europe/Berlin', 'runner' => 'diff'])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();

    expect(app(PlatformSettings::class)->timezone())->toBe('Europe/Berlin')
        // Every repository that picks no runner of its own now reviews from the diff.
        ->and(app(Runners::class)->default())->toBe('diff');

    livewire(ManageTenants::class)
        ->mountAction(TestAction::make('create'))
        ->assertActionDataSet(['currency' => 'EUR', 'timezone' => 'Europe/Berlin']);

    $this->actingAs(User::factory()->create());
    $this->get('/admin/settings')->assertForbidden();
});

it('keeps the runner unpicked when the settings are saved for something else, so the configured one takes over once it is set up', function () {
    config(['review.default_runner' => 'docker', 'review.docker.enabled' => false, 'review.cloudflare.local' => false]);
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // Docker is not set up yet, so the platform reviews from the diff.
    expect(app(Runners::class)->default())->toBe('diff');

    livewire(Settings::class)
        ->assertSchemaStateSet(['runner' => null])
        ->fillForm(['currency' => 'EUR'])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetScopedInstances();
    config(['review.docker.enabled' => true]);

    expect(app(PlatformSettings::class)->runner())->toBeNull()
        ->and(app(Runners::class)->default())->toBe('docker');
});
