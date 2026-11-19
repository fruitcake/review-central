<?php

use App\Filament\Admin\Resources\Tenants\Pages\ManageTenants;
use App\Models\Connection;
use App\Models\ExchangeRate;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Currency;
use App\Services\PlatformOverview;
use App\Services\TenantOverview;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

function ecbRates(string $dollarsPerEuro = '1.2500'): string
{
    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
            <Cube><Cube time='2026-11-18'>
                <Cube currency='USD' rate='{$dollarsPerEuro}'/>
                <Cube currency='JPY' rate='174.85'/>
            </Cube></Cube>
        </gesmes:Envelope>
        XML;
}

beforeEach(function () {
    Http::preventStrayRequests();

    $this->tenant = Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme', 'currency' => 'EUR', 'monthly_budget' => 10]);
    $connection = Connection::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->repository = Repository::factory()->create(['tenant_id' => $this->tenant->id, 'connection_id' => $connection->id, 'slug' => 'shop']);

    $this->run = fn (array $attributes = []): ReviewRun => ReviewRun::query()->create($attributes + [
        'tenant_id' => $this->tenant->id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => $this->repository->slug,
        'branch' => 'feature/export', 'pull_request_id' => 7, 'commit' => str_repeat('a', 40), 'build_number' => 1,
        'status' => ReviewRun::STATUS_FINISHED, 'result' => 'PASSED', 'started_at' => now()->subHour(), 'duration_seconds' => 100,
    ]);

    // One dollar is 80 euro cents.
    $this->euro = fn () => ExchangeRate::query()->create(['currency' => 'EUR', 'rate' => 0.8, 'fetched_at' => now()]);
});

it('stores what a dollar is worth in euros, from the rates of the European Central Bank', function () {
    Http::fake(['www.ecb.europa.eu/*' => Http::response(ecbRates())]);

    $this->artisan('review:sync-exchange-rates')->assertSuccessful();

    expect((float) ExchangeRate::query()->sole()->rate)->toBe(0.8)
        ->and(ExchangeRate::query()->sole()->currency)->toBe('EUR');
});

it('keeps the stored rate when the rates cannot be read', function (mixed $response) {
    ($this->euro)();
    Http::fake(['www.ecb.europa.eu/*' => $response]);

    $this->artisan('review:sync-exchange-rates')->assertFailed();

    expect((float) ExchangeRate::query()->sole()->rate)->toBe(0.8);
})->with([
    'an error' => [fn () => Http::response('', 503)],
    'another answer' => [fn () => Http::response('<html></html>')],
]);

it('shows a cost in the tenant\'s currency, and in dollars without one', function () {
    ($this->euro)();

    expect(Currency::show(10, $this->tenant))->toBe('€8.00')
        ->and(Currency::show('1.2345', Tenant::factory()->create()))->toBe('$1.23')
        ->and(Currency::show(10))->toBe('$10.00');

    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setTenant($this->tenant);

    expect(Currency::show(10))->toBe('€8.00');
});

it('shows dollars while there is no rate for the tenant\'s currency', function () {
    expect(Currency::show(10, $this->tenant))->toBe('$10.00');
});

it('compares the month\'s cost with the budget in the tenant\'s currency', function () {
    ($this->euro)();
    ($this->run)(['cost' => 10]);

    $figure = collect(app(TenantOverview::class)->for($this->tenant, 30)['figures'])->firstWhere('label', 'Cost this month');

    expect($figure)->toMatchArray(['value' => '€8.00', 'note' => '80% of €10 budget', 'bar' => 80]);

    $row = collect(app(PlatformOverview::class)->for(now()->subDays(30))['tenants'])->firstWhere('tenant.slug', 'acme');

    expect($row)->toMatchArray(['spent' => 8.0, 'budget' => 10.0, 'percent' => 80, 'money' => '€8.00 of €10']);
});

it('reads euros on the tenant\'s pages and in the administrator\'s list of tenants', function () {
    ($this->euro)();
    $run = ($this->run)(['cost' => 2.5]);

    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get('/app/acme')->assertOk()->assertSee('€2.00')->assertDontSee('$2.50');
    $this->get('/app/acme/review-runs')->assertOk()->assertSee('€2.00');
    $this->get("/app/acme/review-runs/{$run->uuid}")->assertOk()->assertSee('€2.00');
    $this->get('/app/acme/repositories')->assertOk()->assertSee('€2.00');
    $this->get('/admin/tenants')->assertOk()->assertSee('€2.00');
    $this->get('/admin')->assertOk()->assertSee('€2.00 of €10');
});

it('lets an administrator choose a tenant\'s currency, fetching the rate it needs', function () {
    Http::fake(['www.ecb.europa.eu/*' => Http::response(ecbRates())]);
    $tenant = Tenant::factory()->create();

    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect($tenant->refresh()->currency)->toBe('USD');

    livewire(ManageTenants::class)
        ->callAction(TestAction::make('edit')->table($tenant), ['currency' => 'EUR'])
        ->assertHasNoFormErrors();

    expect($tenant->refresh()->currency)->toBe('EUR')
        ->and(Currency::show(10, $tenant))->toBe('€8.00');
});

it('refuses a currency whose rate cannot be fetched', function () {
    Http::fake(['www.ecb.europa.eu/*' => Http::response('', 503)]);
    $tenant = Tenant::factory()->create();

    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    livewire(ManageTenants::class)
        ->callAction(TestAction::make('edit')->table($tenant), ['currency' => 'EUR'])
        ->assertHasFormErrors(['currency']);

    expect($tenant->refresh()->currency)->toBe('USD');
});
