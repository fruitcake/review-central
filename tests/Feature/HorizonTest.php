<?php

use App\Models\User;

it('shows the Horizon dashboard to an administrator only', function () {
    // Outside the local environment, where Horizon lets everyone in.
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/horizon')->assertForbidden();

    $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();

    $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/horizon')->assertOk();
});
