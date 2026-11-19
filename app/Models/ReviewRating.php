<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a developer thought of one AI review run, from 1 (useless) to 5 (spot on).
 *
 * @property int $id
 * @property int $review_run_id
 * @property int|null $user_id
 * @property string|null $name
 * @property int $score
 * @property string|null $comment
 * @property array<string, bool>|null $findings Finding id => whether it was fair
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read ReviewRun $run
 * @property-read User|null $user
 */
class ReviewRating extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'findings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ReviewRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ReviewRun::class, 'review_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
