<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A finding a pull request's last review still had open when the pull request was
 * merged. It stays on the repository's backlog until a check finds it resolved on
 * the main branch, or someone closes it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $repository_id
 * @property int $pull_request_id
 * @property int|null $review_run_id
 * @property string $finding_id
 * @property string|null $severity
 * @property string $title
 * @property string|null $path
 * @property int|null $line
 * @property string|null $details
 * @property string|null $merge_commit
 * @property string $status open (not checked yet), present, resolved, unclear or dismissed
 * @property string|null $note what the last check or the person who closed it said
 * @property CarbonInterface|null $checked_at
 * @property string|null $checked_by_model
 * @property CarbonInterface|null $closed_at
 * @property string|null $closed_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Repository $repository
 */
class OpenFinding extends Model
{
    public const OPEN = 'open';

    public const PRESENT = 'present';

    public const RESOLVED = 'resolved';

    public const UNCLEAR = 'unclear';

    public const DISMISSED = 'dismissed';

    /** The statuses that are still on the backlog. */
    public const ACTIVE = [self::OPEN, self::PRESENT, self::UNCLEAR];

    /** @var array<string, string> */
    public const LABELS = [
        self::OPEN => 'Not checked yet',
        self::PRESENT => 'Still there',
        self::UNCLEAR => 'Unclear',
        self::RESOLVED => 'Resolved',
        self::DISMISSED => 'Closed',
    ];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', self::ACTIVE);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ReviewRun, $this>
     */
    public function reviewRun(): BelongsTo
    {
        return $this->belongsTo(ReviewRun::class);
    }
}
