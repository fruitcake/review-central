<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ExchangeRate;
use App\Services\Currency;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SyncExchangeRates extends Command
{
    protected $signature = 'review:sync-exchange-rates';

    protected $description = 'Fetch what a dollar is worth in the currencies a tenant can read its costs in';

    /**
     * The European Central Bank's reference rates of the last working day: what
     * one euro is worth in each currency, the dollar among them.
     */
    public const SOURCE = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    public function handle(): int
    {
        $perEuro = $this->perEuro();
        $dollars = $perEuro[Currency::USD] ?? null;

        if ($dollars === null || $dollars <= 0) {
            // Yesterday's rate beats none, so what is stored stays.
            $this->error('The exchange rates could not be read; those stored are unchanged.');

            return self::FAILURE;
        }

        $stored = [];

        foreach (array_keys(Currency::SYMBOLS) as $currency) {
            $rate = $currency === 'EUR' ? 1.0 : ($perEuro[$currency] ?? null);

            if ($currency === Currency::USD || $rate === null) {
                continue;
            }

            ExchangeRate::query()->updateOrCreate(['currency' => $currency], ['rate' => round($rate / $dollars, 6), 'fetched_at' => now()]);
            $stored[] = $currency;
        }

        $this->info('Stored the rate of '.implode(', ', $stored).' for one US dollar.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, float> currency to what one euro is worth in it; empty when it could not be read
     */
    protected function perEuro(): array
    {
        try {
            $response = Http::timeout(30)->connectTimeout(5)->get(self::SOURCE);
        } catch (ConnectionException $exception) {
            $this->error("The exchange rates could not be reached: {$exception->getMessage()}");

            return [];
        }

        if (! $response->successful()) {
            $this->error("The exchange rates answered HTTP {$response->status()}.");

            return [];
        }

        // Read by pattern: the file is three levels of one element in two namespaces.
        preg_match_all("/currency=['\"]([A-Z]{3})['\"]\s+rate=['\"]([0-9.]+)['\"]/", $response->body(), $matches, PREG_SET_ORDER);

        $rates = [];

        foreach ($matches as [, $currency, $rate]) {
            $rates[$currency] = (float) $rate;
        }

        return $rates;
    }
}
