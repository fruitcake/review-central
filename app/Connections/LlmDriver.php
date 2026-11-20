<?php

declare(strict_types=1);

namespace App\Connections;

use App\Enums\ReviewProvider;
use App\Exceptions\ReviewerFailed;
use App\Models\Connection;
use App\Runners\Diff\Answer;
use Laravel\Ai\Responses\AgentResponse;

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

    /**
     * Where a pull request stands, in a few sentences from a small model: what the
     * one at turn has to do, and what it changes and went through. The model
     * the team picked, or the provider's small one when it picked none.
     *
     * @return array{next_step: string, summary: string}|null null when the provider gave no such answer
     */
    public function describes(Connection $connection, string $instructions, string $text, ?string $model = null): ?array;

    /**
     * What a pull request asks of its review, from a small model: the Classifier's
     * structured output, with the tokens and model it took.
     */
    public function classifies(Connection $connection, string $text): AgentResponse;

    /**
     * Several reviews of one pull request judged together, by the model asked for:
     * the Comparer's structured output, with the tokens and model it took.
     *
     * @param  string|null  $model  a Laravel AI tier or a model by name; the provider's smartest when none
     */
    public function compares(Connection $connection, string $text, ?string $model = null): AgentResponse;
}
