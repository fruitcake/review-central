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

    protected function ask(Connection $connection): Response
    {
        return $this->bearer($connection, 'https://api.x.ai/v1/models');
    }
}
