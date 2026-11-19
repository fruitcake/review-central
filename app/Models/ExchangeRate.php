<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * What one US dollar is worth in another currency, as last fetched.
 *
 * @property string $currency
 * @property string $rate
 * @property CarbonInterface $fetched_at
 */
class ExchangeRate extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'currency';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fetched_at' => 'datetime'];
    }
}
