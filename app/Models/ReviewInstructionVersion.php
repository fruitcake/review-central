<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A set of review instructions exactly as a run was given them. Keyed by the hash
 * of the files, so a set that was served before is the same row.
 *
 * @property int $id
 * @property string $version
 * @property array<int, array{key: string, title: string, stack: string|null, file: string, body: string}> $files
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ReviewInstructionVersion extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'files' => 'array',
        ];
    }
}
