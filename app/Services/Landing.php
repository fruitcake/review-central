<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Filament\Facades\Filament;

/**
 * Where someone who signed in belongs.
 */
class Landing
{
    /**
     * An administrator's place is the admin panel; anyone else's is the first of
     * their tenants. Null for a user no tenant has been given to yet.
     */
    public function urlFor(User $user): ?string
    {
        if ($user->is_admin) {
            return Filament::getPanel('admin')->getUrl();
        }

        $tenant = $user->tenants()->orderBy('name')->first();

        return $tenant === null ? null : Filament::getPanel('app')->getUrl($tenant);
    }
}
