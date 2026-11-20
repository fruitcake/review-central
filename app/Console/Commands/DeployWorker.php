<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Runners\Container\WorkerDeployment;
use Illuminate\Console\Command;

class DeployWorker extends Command
{
    protected $signature = 'review:deploy-worker';

    protected $description = 'Deploy the Cloudflare Worker that runs the sandboxes, with the account and API token under Settings or in CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_API_TOKEN';

    public function handle(WorkerDeployment $deployment): int
    {
        $result = $deployment->deploy(fn (string $line) => $this->line($line));

        if (! $result['ok']) {
            $this->error((string) $result['error']);

            return self::FAILURE;
        }

        $this->info("The Worker answers at {$result['url']}.");

        return self::SUCCESS;
    }
}
