<?php

declare(strict_types=1);

namespace App\Llm\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Answers one question about a text with a yes or a no, as structured output: for
 * a provider that has no classification of its own.
 */
#[MaxTokens(200)]
class Decider implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(protected string $question) {}

    public function instructions(): string
    {
        return $this->question;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['answer' => $schema->boolean()->required()];
    }
}
