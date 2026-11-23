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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Why it was dismissed, as a line for the team: "Sam: Not a problem · Nitpick: too small".
     */
    public function explanation(): string
    {
        return trim(($this->user->name ?? 'Someone').': '.$this->verdict->text().($this->reason === null ? '' : ' · '.$this->reason->text()).(filled($this->note) ? " — {$this->note}" : ''));
    }

    /**
     * @return BelongsTo<ReviewRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ReviewRun::class, 'review_run_id');
    }
}
