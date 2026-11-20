<?php

declare(strict_types=1);

namespace App\Llm;

use App\Models\Connection;
use Illuminate\Http\Client\Response;

class XaiDriver extends ApiKeyDriver
{
    public function label(): string
    {
        return 'xAI';
    }

    protected function sdk(): string
    {
        return 'xai';
    }

    protected function pi(): string
    {
        return 'xai';
    }

    protected function variable(): string
    {
        return 'XAI_API_KEY';
    }

    protected function keyPage(): string
    {
        return 'https://console.x.ai/team/default/api-keys';
    }

    protected function keyNotes(): array
    {
        return [
            'A key can be limited to some endpoints and models: it needs the chat endpoint and the list of models, and the model set under Settings.',
        ];
    }

    protected function ask(Connection $connection): Response
    {
        return $this->bearer($connection, 'https://api.x.ai/v1/models');
    }
}
