<?php

use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoSeeder;

it('seeds a team with a month of reviews to look around in', function () {
    $this->seed(DemoSeeder::class);

    $user = User::query()->where('email', 'demo@example.com')->sole();

    expect(Tenant::query()->where('slug', 'acme')->sole()->users->pluck('id')->all())->toBe([$user->id])
        ->and(ReviewRun::query()->count())->toBeGreaterThan(50)
        ->and(ReviewRun::query()->where('started_at', '>', now())->exists())->toBeFalse();

    $this->actingAs($user)->get('/app/acme')->assertOk()->assertSee('Acme · last 30 days');
});
