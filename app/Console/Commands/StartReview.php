<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Repository;
use Illuminate\Console\Command;
use Throwable;

class StartReview extends Command
{
    protected $signature = 'review:start
        {repository : The repository, as workspace/name or by its id}
        {branch : The branch of an open pull request}';

    protected $description = 'Start a review by hand, on the runner the repository chose, as a /review comment would';

    public function handle(): int
    {
        $name = (string) $this->argument('repository');

        $repositories = ctype_digit($name)
            ? Repository::query()->whereKey((int) $name)->get()
            : Repository::query()->where('workspace', str($name)->before('/')->toString())->where('slug', str($name)->after('/')->toString())->get();

        if ($repositories->count() !== 1) {
            $this->error($repositories->isEmpty()
                ? "No repository matches {$name}."
                : "More than one tenant has {$name}; pass its id: ".$repositories->pluck('id')->implode(', ').'.');

            return self::FAILURE;
        }

        $repository = $repositories->sole();

        try {
            $build = $repository->runner()->start($repository, (string) $this->argument('branch'));
        } catch (Throwable $exception) {
            $this->error('The review could not be started: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Started a review of {$repository->fullName()} on {$repository->runner()->label()}".($build === null ? '.' : " as run {$build}."));

        return self::SUCCESS;
    }
}
