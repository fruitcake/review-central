<?php

declare(strict_types=1);

namespace App\Enums;

enum ConnectionKind: string
{
    case SourceControl = 'source_control';

    case Tickets = 'tickets';

    case Llm = 'llm';

    public function text(): string
    {
        return match ($this) {
            self::SourceControl => __('Source control'),
            self::Tickets => __('Tickets'),
            self::Llm => __('AI provider'),
        };
    }
}
