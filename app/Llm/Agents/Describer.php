<?php

declare(strict_types=1);

namespace App\Llm\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Says in a few sentences where a pull request stands: what the one at turn has to
 * do, and what it changes and went through, as structured output.
 */
#[MaxTokens(600)]
class Describer implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(protected string $instructions) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * The answer, when it is one.
     *
     * @return array{next_step: string, summary: string}|null
     */
    public static function read(mixed $answer): ?array
    {
        $next = $answer['next_step'] ?? null;
        $summary = $answer['summary'] ?? null;

        return is_string($next) && trim($next) !== '' && is_string($summary)
            ? ['next_step' => mb_substr(trim($next), 0, 600), 'summary' => mb_substr(trim($summary), 0, 1500)]
            : null;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'next_step' => $schema->string()->required(),
            'summary' => $schema->string()->required(),
        ];
    }
}
