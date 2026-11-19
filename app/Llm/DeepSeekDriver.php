<?php

declare(strict_types=1);

namespace App\Llm;

use App\Models\Connection;
use Illuminate\Http\Client\Response;

class DeepSeekDriver extends ApiKeyDriver
{
    public function label(): string
    {
        return 'DeepSeek';
    }

    protected function sdk(): string
    {
        return 'deepseek';
    }

    protected function pi(): string
    {
        return 'deepseek';
    }

    protected function variable(): string
    {
        return 'DEEPSEEK_API_KEY';
    }

    protected function ask(Connection $connection): Response
    {
        return $this->bearer($connection, 'https://api.deepseek.com/models');
    }
}
