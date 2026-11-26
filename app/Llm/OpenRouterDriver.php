<?php

declare(strict_types=1);

namespace App\Llm;

use App\Models\Connection;
use Illuminate\Http\Client\Response;

class OpenRouterDriver extends ApiKeyDriver
{
    public function label(): string
    {
        return 'OpenRouter';
    }

    protected function sdk(): string
    {
        return 'openrouter';
    }

    protected function pi(): string
    {
        return 'openrouter';
    }

    protected function variable(): string
    {
        return 'OPENROUTER_API_KEY';
    }

    protected function keyPage(): string
    {
        return 'https://openrouter.ai/settings/keys';
    }

    protected function keyNotes(): array
    {
        return [
            __('A key can have a credit limit of its own: set one to cap what reviews spend.'),
        ];
    }

    protected function ask(Connection $connection): Response
    {
        // Its list of models is public; what the key is good for is not.
        return $this->bearer($connection, 'https://openrouter.ai/api/v1/key');
    }
}
