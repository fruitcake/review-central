<?php

declare(strict_types=1);

namespace App\Llm;

use App\Models\Connection;
use Illuminate\Http\Client\Response;

class MistralDriver extends ApiKeyDriver
{
    public function label(): string
    {
        return 'Mistral';
    }

    protected function sdk(): string
    {
        return 'mistral';
    }

    protected function pi(): string
    {
        return 'mistral';
    }

    protected function variable(): string
    {
        return 'MISTRAL_API_KEY';
    }

    protected function keyPage(): string
    {
        return 'https://console.mistral.ai/api-keys';
    }

    protected function keyNotes(): array
    {
        return [
            __('A key works only once the workspace has a plan or credits under **Billing**.'),
        ];
    }

    protected function ask(Connection $connection): Response
    {
        return $this->bearer($connection, 'https://api.mistral.ai/v1/models');
    }
}
