<?php

declare(strict_types=1);

namespace App\Connections\Fields;

use Filament\Forms\Components\TextInput;

/**
 * A credential field: asked for when the connection is made, and never shown
 * again. Left empty on an edit, the stored value stays.
 */
class Secret
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->password()
            ->revealable()
            ->autocomplete('off')
            ->required(fn (string $operation): bool => $operation === 'create')
            ->placeholder(function (string $operation): ?string {
                if ($operation !== 'edit') {
                    return null;
                }

                return __('Unchanged');
            })
            ->dehydrated(fn (?string $state): bool => filled($state));
    }
}
