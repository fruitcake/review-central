<?php

declare(strict_types=1);

namespace App\Connections;

use App\Enums\ReviewProvider;
use App\Exceptions\ReviewerFailed;
use App\Models\Connection;
use App\Runners\Diff\Answer;

/**
 * An AI provider whose key the review runs with.
 */
interface LlmDriver extends ConnectionDriver
{
    /**
     * The environment variables the reviewer reads the key from.
     *
     * @return array<string, string>
     */
    public function environment(Connection $connection): array;

    /**
     * The reviewers this key can run.
     *
     * @return array<int, ReviewProvider>
     */
    public function reviewers(): array;

    /**
     * Asks the provider's own API for a review, without a reviewer in between:
     * one request, and the report as its answer.
     *
     * @param  string  $instructions  what a review is and how to write one, the same for every pull request
     * @param  string  $material  the pull request to review
     * @param  string|null  $model  the model the repository set, when it is one this API knows
     * @param  string|null  $effort  low, medium, high or xhigh
     *
     * @throws ReviewerFailed when there is no report to use
     */
    public function review(Connection $connection, string $instructions, string $material, ?string $model = null, ?string $effort = null): Answer;

    /**
     * A yes or a no to one question about a text, from a small model: through the
     * provider's classification where it has one, as structured output where not.
     *
     * @return bool|null null when the provider gave neither
     */
    public function decides(Connection $connection, string $question, string $text): ?bool;
}
