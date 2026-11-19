<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * What one model costs per token, kept so a review's tokens can be turned into
 * money without anyone maintaining a price list by hand.
 *
 * @property string $model
 * @property string|null $input
 * @property string|null $output
 * @property string|null $cache_read
 * @property string|null $cache_creation
 * @property string $source
 * @property CarbonInterface $fetched_at
 */
class ModelPrice extends Model
{
    /**
     * A price list older than this is not used. Prices change, and a stale one
     * produces a confident number that is quietly wrong — worse than no number.
     */
    public const STALE_AFTER_DAYS = 14;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fetched_at' => 'datetime'];
    }

    /**
     * The price for a model, or null when it is unknown or too old to trust.
     *
     * Falls back to the undated id: Claude Code reports `claude-sonnet-4-6` while a
     * catalogue may carry `claude-sonnet-4-6-20260115`, and the other way round.
     */
    public static function for(string $model): ?self
    {
        $price = self::query()
            ->where('model', $model)
            ->orWhere('model', preg_replace('/-\d{8}$/', '', $model))
            ->orderByRaw('CASE WHEN model = ? THEN 0 ELSE 1 END', [$model])
            ->first();

        if ($price === null || $price->fetched_at->lt(now()->subDays(self::STALE_AFTER_DAYS))) {
            return null;
        }

        return $price;
    }

    /**
     * What a run's tokens cost, or null when any model in it has no usable price.
     *
     * All or nothing on purpose: a total that silently leaves out one model reads
     * as the full cost of the run and is not.
     *
     * @param  array<string, array<string, int>>  $usage
     */
    public static function estimate(array $usage): ?float
    {
        $total = 0.0;

        foreach ($usage as $model => $tokens) {
            $price = self::for((string) $model);

            if ($price === null) {
                return null;
            }

            foreach (['input', 'output', 'cache_read', 'cache_creation'] as $type) {
                $perToken = $price->{$type};

                if ($perToken === null && ($tokens[$type] ?? 0) > 0) {
                    return null;
                }

                $total += (float) ($tokens[$type] ?? 0) * (float) $perToken;
            }
        }

        return round($total, 6);
    }
}
