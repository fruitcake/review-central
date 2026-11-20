<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CheckOpenFindings as CheckJob;
use App\Models\OpenFinding;
use App\Models\Repository;
use App\Services\OpenFindings;
use Illuminate\Console\Command;

class CheckOpenFindings extends Command
{
    protected $signature = 'review:check-open-findings';

    protected $description = 'Check the open findings of merged pull requests against the main branch, for the teams that have it done weekly or monthly';

    public function handle(): int
    {
        $queued = 0;

        $repositories = Repository::query()->with('tenant')
            ->whereIn('id', OpenFinding::query()->active()->select('repository_id')->distinct())
            ->get();

        foreach ($repositories as $repository) {
            if (OpenFindings::due($repository)->isNotEmpty()) {
                CheckJob::dispatch($repository->getKey());
                $queued++;
            }
        }

        $this->info("Queued a check for {$queued} repository(ies).");

        return self::SUCCESS;
    }
}
