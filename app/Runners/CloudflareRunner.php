<?php

declare(strict_types=1);

namespace App\Runners;

use App\Runners\Container\CloudflareSandbox;

/**
 * The review as a queued job on this installation's worker, as on Docker, with
 * the reviewer in a Cloudflare sandbox: a microVM of its own per step, so as
 * many run at once as the worker has processes, whatever its own size.
 */
class CloudflareRunner extends ContainerRunner
{
    public const NAME = CloudflareSandbox::NAME;

    public function label(): string
    {
        return 'A Cloudflare sandbox';
    }

    public function shortLabel(): string
    {
        return 'Cloudflare';
    }

    public function description(): string
    {
        return 'The reviewer reads the repository in a Cloudflare sandbox, started for the review and gone after it. The worker clones and files the report.';
    }

    public function setupHint(): string
    {
        return 'Deploy the Worker in cloudflare/worker and set REVIEW_CLOUDFLARE_URL and REVIEW_CLOUDFLARE_TOKEN, or run it on this machine with `npx wrangler dev` and REVIEW_CLOUDFLARE_LOCAL=true. Admin → Setup gives every step.';
    }

    public function available(): bool
    {
        return CloudflareSandbox::configured();
    }
}
