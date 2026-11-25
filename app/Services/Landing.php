<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LandingPage;
use App\Filament\App\Pages\MyWork;
use App\Filament\TenantRole;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Where someone who signed in belongs.
 */
class Landing
{
    /**
     * Where the user chose to land, on their profile, or else where their role
     * sends them: an administrator to the admin panel, anyone else to their
     * tenant, its overview for an admin of it and what waits for them there for
     * a member. The tenant is the one they chose, or the first of theirs. Null
     * for a user no tenant has been given to yet.
     */
    public function urlFor(User $user): ?string
    {
        // A choice counts while they have one: not for an admin made a member since.
        $page = $this->canChoose($user) ? $user->landing_page : null;

        if ($user->is_admin && ($page === null || $page === LandingPage::Platform)) {
            return Filament::getPanel('admin')->getUrl();
        }

        $tenant = $user->getDefaultTenant(Filament::getPanel('app'));

        if ($tenant === null) {
            return $user->is_admin ? Filament::getPanel('admin')->getUrl() : null;
        }

        if ($page === null || ! $page->inTenant()) {
            $page = TenantRole::isAdmin($user, $tenant) ? LandingPage::Tenant : LandingPage::Personal;
        }

        return $page === LandingPage::Tenant
            ? Filament::getPanel('app')->getUrl($tenant)
            : MyWork::getUrl(panel: 'app', tenant: $tenant);
    }

    /**
     * Whether the user has a landing page to choose: an administrator of the
     * platform or of a tenant. A member lands on what waits for them.
     */
    public function canChoose(User $user): bool
    {
        return $user->is_admin || $user->tenants()->wherePivot('role', TenantRole::ADMIN)->exists();
    }
}
