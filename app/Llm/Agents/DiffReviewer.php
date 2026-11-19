<?php

declare(strict_types=1);

namespace App\Llm\Agents;

use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * The reviewer of a pull request's diff: one request to a provider's API, and the
 * report as its answer.
 *
 * The report's schema is ai-review.schema.json, the file the reviewers in a
 * container are given too, so it goes to the provider as it is written, in the
 * provider's own option, rather than being written a second time in the SDK's
 * schema types.
 */
// The instructions are the same for every review, so the next one within a few
// minutes reads them from the cache.
#[CacheInstructions]
class DiffReviewer implements Agent, HasProviderOptions
{
    use Promptable;

    /**
     * @param  array<string, mixed>  $options  what the provider's API takes beyond the prompt: the schema, the effort
     */
    public function __construct(protected string $instructions, protected array $options) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return $this->options;
    }
}
