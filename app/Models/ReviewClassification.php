<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewComplexity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one commit of a pull request was classified as before its review: how much
 * it asks of the review, what kind of change it is, and what it touches.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $repository_id
 * @property int $pull_request_id
 * @property string $commit
 * @property ReviewComplexity $complexity
 * @property string|null $kind
 * @property array<int, string> $flags the yeses among database, breaking, security and irreversible
 * @property string|null $reason
 * @property int $files_count
 * @property int $lines_added
 * @property int $lines_removed
 * @property string|null $model
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property float|null $cost dollars
 * @property int|null $duration_ms
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ReviewClassification extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'complexity' => ReviewComplexity::class,
            'flags' => 'array',
            'cost' => 'float',
        ];
    }

    /**
     * In words, for the log and the panel: "complex · feature · security, database".
     */
    public function text(): string
    {
        return implode(' · ', array_filter([$this->complexity->text(), $this->kind, implode(', ', $this->flags) ?: null]));
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class)->withTrashed();
    }
}
