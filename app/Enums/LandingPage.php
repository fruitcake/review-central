<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an admin lands after signing in, as they chose on their profile.
 */
enum LandingPage: string
{
    /** The admin panel's figures for the whole platform: for an administrator of it. */
    case Platform = 'platform';

    /** A tenant's overview, with the team's figures. */
    case Tenant = 'tenant';

    /** What waits for them in a tenant: My work. */
    case Personal = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::Platform => __('Platform dashboard'),
            self::Tenant => __('Tenant dashboard'),
            self::Personal => __('Personal dashboard'),
        };
    }

    /**
     * Whether it is a page of a tenant, which then needs one.
     */
    public function inTenant(): bool
    {
        return $this !== self::Platform;
    }

    /**
     * @return array<string, string>
     */
    public static function options(bool $withPlatform): array
    {
        return collect(self::cases())
            ->reject(fn (self $case): bool => $case === self::Platform && ! $withPlatform)
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
