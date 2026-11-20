<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PrepareCloudflareSandbox;
use App\Runners\Container\CloudflareSandbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Has a snapshot made with the newest release of the reviewers' CLIs. Without
 * it, every review in a sandbox would update its CLI itself, which takes half a
 * minute each time, until the setup script changed. Reviews switch to the new
 * versions once their snapshot is there.
 */
class RefreshSandbox extends Command
{
    protected $signature = 'review:refresh-sandbox';

    protected $description = 'Make a sandbox snapshot with the newest release of the reviewers\' CLIs, when there is one';

    public function handle(): int
    {
        if (! CloudflareSandbox::configured() || CloudflareSandbox::setupScript() === null) {
            $this->info('No Cloudflare sandbox with a setup of its own: nothing to refresh.');

            return self::SUCCESS;
        }

        $latest = CloudflareSandbox::latestClis();
        $pinned = CloudflareSandbox::pinnedClis();
        $newer = array_diff_assoc($latest, $pinned);

        if ($newer === []) {
            $this->info('The snapshot has the newest CLIs.');

            return self::SUCCESS;
        }

        // One at a time, and not while one is being prepared.
        if (Cache::has(PrepareCloudflareSandbox::PREPARING)) {
            $this->info('A snapshot is being prepared already.');

            return self::SUCCESS;
        }

        PrepareCloudflareSandbox::ask($latest + $pinned);

        $this->info('Preparing a snapshot with '.collect($newer)->map(fn (string $version, string $package): string => "{$package} {$version}")->implode(', ').'.');

        return self::SUCCESS;
    }
}
