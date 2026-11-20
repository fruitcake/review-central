<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\AiProviders;

use App\Enums\ConnectionKind;
use App\Filament\App\Pages\ReviewDefaults;
use App\Filament\App\Resources\AiProviders\Pages\ManageAiProviders;
use App\Filament\App\Resources\Connections\ConnectionResource;
use App\Models\Connection;
use App\Services\Connections;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Whose AI a tenant's reviews run on: each provider's key. Which reviewer a pull
 * request gets, in which order, at which model, effort and monthly budget, are
 * under Review defaults.
 */
class AiProviderResource extends ConnectionResource
{
    protected static ?ConnectionKind $kind = ConnectionKind::Llm;

    protected static ?string $slug = 'ai-providers';

    protected static ?string $navigationLabel = 'AI providers';

    protected static ?string $modelLabel = 'AI provider';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 29;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...self::serviceFields(ConnectionKind::Llm),
                // Only the key here: which reviewer runs on it, and how, is the team's
                // review defaults', where the other choices of a review are.
                Text::make(fn (): HtmlString => new HtmlString('Which reviewer runs with this key, in which order, and at which model and effort is set under <a href="'.e(ReviewDefaults::getUrl()).'" class="underline">Review defaults</a>.'))
                    ->key('reviewersNote')
                    ->visible(fn (Get $get): bool => filled($get('driver'))),
            ])
            ->columns(1);
    }

    protected static function editAction(): EditAction
    {
        return EditAction::make()
            // Secrets are never sent back to the browser.
            ->mutateRecordDataUsing(fn (array $data): array => ['credentials' => []] + $data)
            ->using(function (Connection $record, array $data, Connections $connections): Connection {
                $record->fill(['name' => $data['name'] ?? $record->name, 'settings' => $data['settings'] ?? $record->settings]);
                $record->credentials = $connections->mergeCredentials($record, (array) ($data['credentials'] ?? []));
                $record->save();

                return $record;
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAiProviders::route('/'),
        ];
    }
}
