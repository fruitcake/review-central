<?php

declare(strict_types=1);

namespace App\Runners\Container;

use App\Models\Repository;
use App\Runners\Runners;

/**
 * Where a review's steps run: Docker on this worker, or a Cloudflare sandbox. That
 * is the runner: a trial's own, else the repository's, else the platform's.
 */
class Sandboxes
{
    public function __construct(protected Runners $runners) {}

    /**
     * The sandbox for a review. A Cloudflare one that is asked for and not set up
     * falls back to Docker: the settings were changed while a review waited, and a
     * review on Docker is better than none.
     */
    public function for(?Trial $trial, Repository $repository, Sandbox $docker): Sandbox
    {
        $runner = $this->runners->containerFor($trial !== null && $trial->sandbox !== null ? $trial->sandbox : $repository->runnerName());

        return $runner->name() === CloudflareSandbox::NAME && CloudflareSandbox::configured() ? app(CloudflareSandbox::class) : $docker;
    }
}
