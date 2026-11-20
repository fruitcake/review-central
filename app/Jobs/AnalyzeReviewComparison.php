<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Connection;
use App\Models\ReviewComparison;
use App\Services\ReviewComparisons;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Has a model judge the findings of a comparison's reviews together.
 */
class AnalyzeReviewComparison implements ShouldQueue
{
    use Queueable;

    public int $timeout = 360;

    public int $tries = 1;

    /**
     * The reviews it reads, taken when it was asked for. A property with a default
     * of its own, so a job queued before it existed still reads as null.
     *
     * @var array<int, string>|null
     */
    public ?array $labels = null;

    /**
     * @param  array<int, string>|null  $labels  the reviews it reads, taken when it was asked for
     */
    public function __construct(public int $comparisonId, public int $connectionId, public ?string $model = null, ?array $labels = null)
    {
        $this->labels = $labels;

        // The queue the reviews run on, whose workers wait long enough for one
        // request with a whole diff; the default one gives up on a job sooner.
        $this->onConnection(config('review.container.connection') ?: null)
            ->onQueue(config('review.container.queue'));
    }

    public function handle(ReviewComparisons $comparisons): void
    {
        $comparison = ReviewComparison::query()->find($this->comparisonId);

        if ($comparison === null) {
            return;
        }

        $connection = Connection::query()->find($this->connectionId);

        if ($connection === null || $connection->tenant_id !== $comparison->tenant_id) {
            $this->markFailed($comparison, 'The AI provider it was to use is gone. Pick another and analyse again.');

            return;
        }

        $comparisons->analyze($comparison, $connection, $this->model, $this->labels);
    }

    /**
     * A job that died, timed out or was given up on leaves the comparison failed,
     * so it can be analysed again.
     */
    public function failed(?Throwable $exception): void
    {
        $comparison = ReviewComparison::query()->find($this->comparisonId);

        if ($comparison !== null) {
            $this->markFailed($comparison, 'The analysis stopped before it was done'.($exception === null ? '' : ': '.Str::limit($exception->getMessage(), 500)).'. Analyse again.');
        }
    }

    protected function markFailed(ReviewComparison $comparison, string $reason): void
    {
        $comparison->update(['analysis_status' => ReviewComparison::ANALYSIS_FAILED, 'analysis_error' => $reason]);
    }
}
