<?php

declare(strict_types=1);

namespace App\Llm;

use App\Models\Connection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GeminiDriver extends ApiKeyDriver
{
    public function label(): string
    {
        return 'Google Gemini';
    }

    protected function sdk(): string
    {
        return 'gemini';
    }

    protected function pi(): string
    {
        return 'google';
    }

    protected function variable(): string
    {
        return 'GEMINI_API_KEY';
    }

    protected function ask(Connection $connection): Response
    {
        // Google takes the key in a header of its own.
        return Http::withHeaders(['x-goog-api-key' => (string) $connection->credential('api_key')])->acceptJson()->timeout(15)
            ->get('https://generativelanguage.googleapis.com/v1beta/models', ['pageSize' => 1]);
    }
}
