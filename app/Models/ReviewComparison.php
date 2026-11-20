<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Reviews of one pull request with different setups, side by side: the review it
 * was started from, and a trial for each setup asked for. A model can then judge
 * their findings together: which hold, which are false, and who missed what.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $repository_id
 * @property int $pull_request_id
 * @property int|null $review_run_id
 * @property int|null $user_id
 * @property array<int, array{runner?: string|null, provider?: string|null, model?: string|null, effort?: string|null, verify?: bool, ignore_existing?: bool, build?: int|null, error?: string|null}> $rows
 * @property string|null $analysis_status running, finished or failed
 * @property string|null $analysis_model
 * @property array{summary?: string, findings?: array<int, array{title: string, path: string|null, verdict: string, found_by: array<int, string>, reason: string}>}|null $analysis
 * @property string|null $analysis_error
 * @property float|null $analysis_cost dollars
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Repository $repository
 * @property-read ReviewRun|null $reviewRun
 */
class ReviewComparison extends Model
{
    use HasUuids;

    public const ANALYSIS_RUNNING = 'running';

    public const ANALYSIS_FINISHED = 'finished';

    public const ANALYSIS_FAILED = 'failed';

    /** The setups one comparison tries at most, besides the review it started from. */
    public const MAX_ROWS = 5;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rows' => 'array',
            'analysis' => 'array',
            'analysis_cost' => 'float',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The columns: the review it was started from, labelled A, then each setup's
     * run as B, C and on, or null for one whose run has not started or could not.
     *
     * @return Collection<string, array{label: string, run: ReviewRun|null, row: array{runner?: string|null, provider?: string|null, model?: string|null, effort?: string|null, verify?: bool, ignore_existing?: bool, build?: int|null, error?: string|null}|null}>
     */
    public function columns(): Collection
    {
        $builds = array_values(array_filter(array_map(fn (array $row): ?int => $row['build'] ?? null, $this->rows)));
        $runs = ReviewRun::query()
            ->where('repository_id', $this->repository_id)
            ->whereIn('build_number', $builds)
            ->get()
            ->keyBy('build_number');

        $columns = ['A' => ['label' => 'A', 'run' => $this->reviewRun, 'row' => null]];

        foreach (array_values($this->rows) as $index => $row) {
            $label = range('B', 'Z')[$index] ?? 'R'.($index + 2);
            $run = isset($row['build']) ? $runs->get($row['build']) : null;
            $columns[$label] = ['label' => $label, 'run' => $run instanceof ReviewRun ? $run : null, 'row' => $row];
        }

        return collect($columns);
    }

    /**
     * Whether every column's review has ended, so the findings can be judged together.
     */
    public function done(): bool
    {
        return $this->columns()->every(fn (array $column): bool => $column['run'] === null
            ? ($column['row']['error'] ?? null) !== null
            : ! in_array($column['run']->status, [ReviewRun::STATUS_QUEUED, ReviewRun::STATUS_RUNNING, ReviewRun::STATUS_PENDING], true));
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
