<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ReviewInstructions;
use Illuminate\Console\Command;

class ImportReviewInstructions extends Command
{
    protected $signature = 'review:import-instructions
        {directory? : A directory of instruction files; the copy installed with the review skill by default}
        {--overwrite : Replace a default that differs from its file with the text of the file}';

    protected $description = 'Read the AI review instructions from the files the review skill ships with';

    public function handle(): int
    {
        $directory = $this->argument('directory') ?? resource_path(ReviewInstructions::BUNDLED_DIRECTORY);

        if (! is_dir($directory)) {
            $this->error("{$directory} is not a directory.");

            return self::FAILURE;
        }

        $result = ReviewInstructions::import($directory, (bool) $this->option('overwrite'));

        foreach ($result['created'] as $key) {
            $this->line("Created {$key}.");
        }

        foreach ($result['replaced'] as $key) {
            $this->line("Replaced {$key} with the file's text.");
        }

        foreach ($result['kept'] as $key) {
            $this->line("Kept {$key} as it is".($this->option('overwrite') ? ': the file says the same.' : '; --overwrite replaces it with the file.'));
        }

        foreach ($result['skipped'] as $key) {
            $this->warn("Skipped {$key}.md: the review only reads lowercase names of letters, digits, dots, dashes and underscores.");
        }

        $this->info(sprintf('%d created, %d replaced, %d kept, %d skipped.', count($result['created']), count($result['replaced']), count($result['kept']), count($result['skipped'])));

        return self::SUCCESS;
    }
}
