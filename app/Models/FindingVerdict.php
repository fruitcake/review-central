<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FindingVerdictKind;
use App\Enums\FindingVerdictReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one user thought of one finding of a run.
 *
 * @property int $id
 * @property int $review_run_id
 * @property int $user_id
 * @property string $finding_id
 * @property FindingVerdictKind $verdict
 * @property FindingVerdictReason|null $reason why it was dismissed; null for one judged valid
 * @property string|null $note
 */
class FindingVerdict extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verdict' => FindingVerdictKind::class,
            'reason' => FindingVerdictReason::class,
        ];
    }

    /**
     * @return BelongsTo<ReviewRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ReviewRun::class, 'review_run_id');
    }
}
