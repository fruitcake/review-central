<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\SourceControl;

use App\Enums\ConnectionKind;
use App\Filament\App\Resources\Connections\ConnectionResource;
use App\Filament\App\Resources\SourceControl\Pages\ManageSourceControl;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * Where a tenant's repositories are: the hosts it connected, with the webhook
 * that starts a review.
 */
class SourceControlResource extends ConnectionResource
{
    protected static ?ConnectionKind $kind = ConnectionKind::SourceControl;

    protected static ?string $slug = 'source-control';

    protected static ?string $navigationLabel = 'Source control';

    protected static ?string $modelLabel = 'source control connection';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracketSquare;

    protected static ?int $navigationSort = 28;

    public static function getPages(): array
    {
        return [
            'index' => ManageSourceControl::route('/'),
        ];
    }
}
