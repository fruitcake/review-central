<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;

/**
 * Shows money in the currency a tenant chose.
 *
 * A review's cost is stored in US dollars, which is what the models are priced in.
 * A tenant that reads euros gets it converted on the page, at the latest rate: the
 * figures are what the reviews would cost today, not what was billed on the day.
 */
class Currency
{
    public const USD = 'USD';

    /** @var array<string, string> the currencies a tenant can choose, with their symbol */
    public const SYMBOLS = ['USD' => '$', 'EUR' => '€'];

    /** @var array<string, string> */
    public const NAMES = ['USD' => 'US dollar ($)', 'EUR' => 'Euro (€)'];

    /** @var array<string, float>|null */
    protected ?array $rates = null;

    /**
     * A cost in dollars, as the tenant reads it: "$1.09" or "€0.99".
     *
     * Without a tenant it is the one whose panel is open, and the platform's
     * currency in the admin panel.
     */
    public static function show(float|string|null $dollars, ?Tenant $tenant = null, int $decimals = 2): string
    {
        $currency = app(self::class);
        $tenant ??= self::openTenant();

        return $currency->symbol($tenant).number_format($currency->convert((float) $dollars, $tenant), $decimals);
    }

    /**
     * An amount that already is in the tenant's currency, such as its budget.
     */
    public static function showOwn(float|string|null $amount, ?Tenant $tenant = null, int $decimals = 2): string
    {
        return app(self::class)->symbol($tenant ?? self::openTenant()).number_format((float) $amount, $decimals);
    }

    public function convert(float $dollars, ?Tenant $tenant): float
    {
        return $dollars * ($this->rate($this->of($tenant)) ?? 1.0);
    }

    public function symbol(?Tenant $tenant): string
    {
        return self::SYMBOLS[$this->of($tenant)];
    }

    /**
     * The currency a tenant's money is shown in: its own, the platform's without
     * a tenant, and dollars for a currency there is no rate for.
     */
    public function of(?Tenant $tenant): string
    {
        $currency = $tenant === null ? app(PlatformSettings::class)->currency() : $tenant->currency;

        return isset(self::SYMBOLS[$currency]) && $this->rate($currency) !== null ? $currency : self::USD;
    }

    /**
     * @return float|null what one dollar is in that currency, or null when no rate was fetched yet
     */
    public function rate(string $currency): ?float
    {
        if ($currency === self::USD) {
            return 1.0;
        }

        $this->rates ??= ExchangeRate::query()->pluck('rate', 'currency')->map(fn (mixed $rate): float => (float) $rate)->all();

        return $this->rates[$currency] ?? null;
    }

    /**
     * Whether there is a rate for the currency, fetching the rates when there is not:
     * a tenant must not be given a currency its costs cannot be converted to.
     */
    public function ensureRate(string $currency): bool
    {
        if ($this->rate($currency) === null) {
            Artisan::call('review:sync-exchange-rates');
            $this->rates = null;
        }

        return $this->rate($currency) !== null;
    }

    protected static function openTenant(): ?Tenant
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant : null;
    }
}
