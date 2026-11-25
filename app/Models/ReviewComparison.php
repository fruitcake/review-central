<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
 * @property array<int, array{runner?: string|null, provider?: string|null, model?: string|null, effort?: string|null, verify?: bool|null, ignore_existing?: bool, modules?: array<string, bool>|null, commit?: string|null, build?: int|null, repository_id?: int, error?: string|null, reserved_at?: string}> $rows
 * @property array<int, string>|null $excluded the labels of the columns left out
 * @property string|null $analysis_status running, finished or failed
 * @property string|null $analysis_model
 * @property array{summary?: string, labels?: array<int, string>, findings?: array<int, array{title: string, path: string|null, verdict: string, found_by: array<int, string>, reason: string}>}|null $analysis
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
    public const MAX_ROWS = 10;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rows' => 'array',
            'analysis' => 'array',
            'excluded' => 'array',
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
     * The commit its setups review when it was started at the commit of the review
     * it came from, rather than the pull request as it is; null for the latter.
     */
    public function commit(): ?string
    {
        $commit = $this->rows[0]['commit'] ?? null;

        return is_string($commit) && $commit !== '' ? $commit : null;
    }

    /**
     * The columns: the review it was started from, labelled A, then each setup's
     * run as B, C and on, or null for one whose run has not started or could not.
     *
     * @return Collection<string, array{label: string, run: ReviewRun|null, row: array{runner?: string|null, provider?: string|null, model?: string|null, effort?: string|null, verify?: bool|null, ignore_existing?: bool, modules?: array<string, bool>|null, commit?: string|null, build?: int|null, repository_id?: int, error?: string|null, reserved_at?: string}|null}>
     */
    public function columns(): Collection
    {
        $started = array_filter($this->rows, fn (array $row): bool => isset($row['build']));
        $runs = ReviewRun::query()
            ->where(function ($query) use ($started): void {
                // Nothing started yet matches nothing, rather than every run.
                $query->whereRaw('1 = 0');

                foreach ($started as $row) {
                    $query->orWhere(fn ($run) => $run->where('repository_id', $row['repository_id'] ?? $this->repository_id)->where('build_number', $row['build']));
                }
            })
            ->get()
            ->keyBy(fn (ReviewRun $run): string => "{$run->repository_id}:{$run->build_number}");

        $columns = ['A' => ['label' => 'A', 'run' => $this->reviewRun, 'row' => null]];

        foreach (array_values($this->rows) as $index => $row) {
            $label = range('B', 'Z')[$index] ?? 'R'.($index + 2);
            $run = isset($row['build']) ? $runs->get(($row['repository_id'] ?? $this->repository_id).":{$row['build']}") : null;
            $columns[$label] = ['label' => $label, 'run' => $run instanceof ReviewRun ? $run : null, 'row' => $row];
        }

        return collect($columns);
    }

    /**
     * The columns not left out: what is analysed, scored and waited for.
     *
     * @return Collection<string, array{label: string, run: ReviewRun|null, row: array{runner?: string|null, provider?: string|null, model?: string|null, effort?: string|null, verify?: bool|null, ignore_existing?: bool, modules?: array<string, bool>|null, commit?: string|null, build?: int|null, repository_id?: int, error?: string|null, reserved_at?: string}|null}>
     */
    public function active(): Collection
    {
        return $this->columns()->reject(fn (array $column, string $label): bool => $this->isExcluded($label));
    }

    public function isExcluded(string $label): bool
    {
        return in_array($label, $this->excluded ?? [], true);
    }

    /**
     * Leaves a column out, or brings it back.
     */
    public function toggleExcluded(string $label): void
    {
        // On the saved selection, under a lock: two changes at once both hold.
        DB::transaction(function () use ($label): void {
            $excluded = self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail()->excluded ?? [];

            $this->update(['excluded' => in_array($label, $excluded, true)
                ? array_values(array_diff($excluded, [$label]))
                : [...$excluded, $label]]);
        });
    }

    /**
     * Whether every column's review has ended, the ones left out too: until then
     * the page keeps looking.
     */
    public function settled(): bool
    {
        return $this->columns()->every(fn (array $column): bool => self::ended($column));
    }

    /**
     * Whether every column's review has ended, so the findings can be judged together.
     */
    public function done(): bool
    {
        return $this->active()->every(fn (array $column): bool => self::ended($column));
    }

    /** How long a setup's place waits for its trial to start before it is let go, in minutes. */
    public const RESERVATION_MINUTES = 10;

    /**
     * Why a column has no review: the reason its trial could not start, or that it
     * never started within the time a place is kept; null while it may still come.
     *
     * @param  array{label: string, run: ReviewRun|null, row: array<string, mixed>|null}  $column
     */
    public static function notStarted(array $column): ?string
    {
        if ($column['run'] !== null || $column['row'] === null) {
            return null;
        }

        if (is_string($column['row']['error'] ?? null)) {
            return $column['row']['error'];
        }

        $reserved = $column['row']['reserved_at'] ?? null;

        return ! isset($column['row']['build']) && is_string($reserved) && now()->subMinutes(self::RESERVATION_MINUTES)->isAfter($reserved)
            ? 'It was not started: what was starting it stopped first. Add it again.'
            : null;
    }

    /**
     * @param  array{label: string, run: ReviewRun|null, row: array<string, mixed>|null}  $column
     */
    protected static function ended(array $column): bool
    {
        return $column['run'] === null
            ? self::notStarted($column) !== null
            : ! in_array($column['run']->status, [ReviewRun::STATUS_QUEUED, ReviewRun::STATUS_RUNNING, ReviewRun::STATUS_PENDING], true);
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
