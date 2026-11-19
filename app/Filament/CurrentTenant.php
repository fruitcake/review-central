<?php

namespace App\Filament;

use App\Models\Tenant;
use Filament\Facades\Filament;
use LogicException;

/**
 * The tenant whose panel is open.
 */
class CurrentTenant
{
    /**
     * The tenant whose panel is open, or null outside one: the admin panel, a sign-in page.
     */
    public static function find(): ?Tenant
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant : null;
    }

    public static function get(): Tenant
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            throw new LogicException('This page is only shown inside a tenant.');
        }

        return $tenant;
    }
}
