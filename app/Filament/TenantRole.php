<?php

declare(strict_types=1);

namespace App\Filament;

use App\Models\Tenant;
use App\Models\User;

/**
 * What someone may do in a tenant. An admin manages its connections and their
 * keys, its apps, repositories, review settings, instructions and members; a
 * member reads its reviews, answers and rates them, asks the reviewer and starts
 * a review. An administrator of the platform is an admin of every tenant.
 */
final class TenantRole
{
    public const ADMIN = 'admin';

    public const MEMBER = 'member';

    /** @var array<string, string> */
    public const LABELS = [self::ADMIN => 'Admin', self::MEMBER => 'Member'];

    /**
     * The roles by name, in the reader's language.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [self::ADMIN => __('Admin'), self::MEMBER => __('Member')];
    }

    /**
     * Whether the user is an admin of the tenant: the signed-in one, of the open
     * one, when left out.
     */
    public static function isAdmin(?User $user = null, ?Tenant $tenant = null): bool
    {
        $user ??= auth()->user();
        $tenant ??= CurrentTenant::find();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        return $tenant !== null && $user->tenants()->whereKey($tenant->getKey())->wherePivot('role', self::ADMIN)->exists();
    }
}
