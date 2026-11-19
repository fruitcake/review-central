<?php

declare(strict_types=1);

namespace App\Runners\Diff;

/**
 * What an AI provider answered when asked for a review.
 */
final readonly class Answer
{
    /**
     * @param  array<string, mixed>  $report  the review, in the shape of ai-review.json
     * @param  string  $model  the model that wrote it, as the provider names it
     * @param  array{input: int, output: int, cache_read: int, cache_creation: int}  $usage  the tokens it took; input is what was not read from a cache
     */
    public function __construct(
        public array $report,
        public string $model,
        public array $usage,
    ) {}
}
