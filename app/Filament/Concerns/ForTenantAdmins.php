<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\TenantRole;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * A resource only a tenant's admins open: its connections and keys, its apps,
 * its instructions. A member does not see it in the navigation, and is refused
 * at its address.
 */
trait ForTenantAdmins
{
    public static function canViewAny(): bool
    {
        return TenantRole::isAdmin();
    }

    public static function canCreate(): bool
    {
        return TenantRole::isAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return TenantRole::isAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return TenantRole::isAdmin();
    }

    /**
     * Filament asks a delete action this, not canDelete(): without a policy it
     * would allow anyone.
     */
    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return TenantRole::isAdmin() ? Response::allow() : Response::deny();
    }
}
