<?php

declare(strict_types=1);

namespace App\Services;

use App\Connections\LlmDriver;
use App\Enums\ConnectionKind;
use App\Llm\AnthropicDriver;
use App\Llm\OpenAiDriver;
use App\Models\Connection;
use App\Models\Tenant;

/**
 * Which of a tenant's AI providers does the small jobs around a review: the notes
 * on whose turn, and classifying a pull request.
 */
class LlmConnections
{
    /**
     * The first of Anthropic, OpenAI and the others, each the oldest of its kind.
     */
    public static function small(Tenant $tenant): ?Connection
    {
        return $tenant->connections()->where('kind', ConnectionKind::Llm)->oldest('id')->get()
            ->filter(fn (Connection $connection): bool => rescue(fn (): bool => $connection->driver() instanceof LlmDriver, false, false))
            ->sortBy(fn (Connection $connection): int => match (true) {
                $connection->driver() instanceof AnthropicDriver => 0,
                $connection->driver() instanceof OpenAiDriver => 1,
                default => 2,
            })
            ->first();
    }
}
