<?php

use App\Models\Repository;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// A tenant's reviews starting and ending: for whoever may open the tenant.
Broadcast::channel('tenants.{tenant}', fn (User $user, Tenant $tenant): bool => $user->canAccessTenant($tenant));

// One container review printing: for whoever may open the repository's tenant.
Broadcast::channel('review-logs.{repository}.{build}', function (User $user, int $repository, int $build): bool {
    $tenant = Repository::query()->withoutGlobalScopes()->find($repository)?->tenant;

    return $tenant !== null && $user->canAccessTenant($tenant);
});
