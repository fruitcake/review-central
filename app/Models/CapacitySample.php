<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * What the installation was doing at one moment: reviews running, waiting and held, and
 * how loaded the server was.
 *
 * @property int $id
 * @property CarbonInterface $sampled_at
 * @property int $running
 * @property int $running_sandboxes of them, with the reviewer in a Cloudflare sandbox
 * @property int $waiting Queued for a free worker
 * @property int $held Held until another review of the same pull request is done
 * @property int $workers
 * @property string|null $load
 * @property int|null $cpus
 * @property string|null $memory_used_percent
 * @property string|null $disk_used_percent
 */
class CapacitySample extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'running' => 'integer',
            'waiting' => 'integer',
            'held' => 'integer',
            'workers' => 'integer',
            'cpus' => 'integer',
        ];
    }
}
