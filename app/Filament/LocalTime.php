<?php

declare(strict_types=1);

namespace App\Filament;

use App\Models\Tenant;
use App\Services\PlatformSettings;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A moment as the tenant reads it: in its own time zone. Times are stored in UTC,
 * and shown that way "18 Nov 14:05" is an hour or two off for whoever reads it.
 */
class LocalTime
{
    public const DEFAULT = PlatformSettings::DEFAULTS[PlatformSettings::TIMEZONE];

    /**
     * @param  Tenant|null  $tenant  the tenant to read it as; the one whose panel is open when left out
     */
    public static function of(DateTimeInterface $time, ?Tenant $tenant = null): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->setTimezone(self::zone($tenant));
    }

    public static function now(?Tenant $tenant = null): CarbonImmutable
    {
        return CarbonImmutable::now(self::zone($tenant));
    }

    /**
     * The tenant's time zone, or the platform's outside a tenant, as in the admin
     * panel, and for a tenant that holds a name PHP does not know.
     */
    public static function zone(?Tenant $tenant = null): string
    {
        $zone = ($tenant ?? CurrentTenant::find())?->timezone;

        return is_string($zone) && in_array($zone, timezone_identifiers_list(), true) ? $zone : app(PlatformSettings::class)->timezone();
    }
}
