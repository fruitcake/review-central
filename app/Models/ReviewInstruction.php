<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file of the AI review's instructions: the guidance for the review of a pull
 * request, or a stack guide. A tenant's own row of a key replaces the default's. The review writes it to `.review-instructions/<key>.md` and the
 * review reads it there.
 *
 * @property int $id
 * @property string $key
 * @property string $title
 * @property string|null $stack
 * @property int $position
 * @property string|null $shipped_hash the hash of the shipped text it was imported or copied from; it differs from the body's once someone here edits it
 * @property string|null $body
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ReviewInstruction extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
