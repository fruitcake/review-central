<?php

declare(strict_types=1);

namespace App\Llm;

use App\Models\Connection;
use Illuminate\Http\Client\Response;

class GroqDriver extends ApiKeyDriver
{
    public function label(): string
    {
        return 'Groq';
    }

    protected function sdk(): string
    {
        return 'groq';
    }

    protected function pi(): string
    {
        return 'groq';
    }

    protected function variable(): string
    {
        return 'GROQ_API_KEY';
    }

    protected function ask(Connection $connection): Response
    {
        return $this->bearer($connection, 'https://api.groq.com/openai/v1/models');
    }
}
