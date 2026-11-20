<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Connection;
use App\Models\ReviewComparison;
use App\Services\ReviewComparisons;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Has a model judge the findings of a comparison's reviews together.
 */
class AnalyzeReviewComparison implements ShouldQueue
{
    use Queueable;

    public int $timeout = 360;

    public int $tries = 1;

    public function __construct(public int $comparisonId, public int $connectionId, public ?string $model = null) {}

    public function handle(ReviewComparisons $comparisons): void
    {
        $comparison = ReviewComparison::query()->find($this->comparisonId);
        $connection = Connection::query()->find($this->connectionId);

        if ($comparison === null || $connection === null || $connection->tenant_id !== $comparison->tenant_id) {
            return;
        }

        $comparisons->analyze($comparison, $connection, $this->model);
    }
}
