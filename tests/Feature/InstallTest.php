<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;

it('installs the instructions, a first administrator and a tenant to start in, and is safe to run again', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $this->artisan('review:install')
        ->expectsQuestion('Name of the first administrator', 'Alex Doe')
        ->expectsQuestion('Email address', 'alex@example.com')
        ->expectsQuestion('Password', 'a-long-enough-password')
        ->expectsQuestion('Name of the first tenant, the team whose repositories are reviewed', 'Acme Web')
        ->assertSuccessful();

    $user = User::query()->sole();
    $tenant = Tenant::query()->sole();

    expect($user->is_admin)->toBeTrue()
        ->and($tenant->only(['name', 'slug']))->toBe(['name' => 'Acme Web', 'slug' => 'acme-web'])
        ->and($user->tenants()->pluck('tenants.id')->all())->toBe([$tenant->id]);

    // Again: nothing more is asked or made.
    $this->artisan('review:install')->assertSuccessful();

    expect(User::query()->count())->toBe(1)->and(Tenant::query()->count())->toBe(1);
});
