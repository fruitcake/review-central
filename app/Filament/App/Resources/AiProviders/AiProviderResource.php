<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\AiProviders;

use App\Enums\ConnectionKind;
use App\Filament\App\Resources\AiProviders\Pages\ManageAiProviders;
use App\Filament\App\Resources\Connections\ConnectionResource;
use App\Filament\Components\ReviewSettingsForm;
use App\Filament\CurrentTenant;
use App\Models\Connection;
use App\Services\Connections;
use App\Services\ReviewSettings;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * Whose AI a tenant's reviews run on: each provider's key, and the model and
 * effort the reviewer on it reviews with: Claude Code on Anthropic, Codex on
 * OpenAI. Which reviewer a pull request gets, and its monthly budget, are under
 * Review defaults.
 */
class AiProviderResource extends ConnectionResource
{
    protected static ?ConnectionKind $kind = ConnectionKind::Llm;

    protected static ?string $slug = 'ai-providers';

    protected static ?string $navigationLabel = 'AI providers';

    protected static ?string $modelLabel = 'AI provider';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 29;

    /** The reviewer each provider runs, with the review settings that are that reviewer's. */
    public const REVIEWERS = [
        'anthropic' => ['label' => 'Claude Code reviews with it', 'fields' => ['claude_model', 'claude_effort', 'claude_advisor', 'claude_max_budget']],
        'openai' => ['label' => 'Codex reviews with it', 'fields' => ['codex_model', 'codex_effort']],
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...self::serviceFields(ConnectionKind::Llm),
                Section::make(self::REVIEWERS['anthropic']['label'])
                    ->description('For every repository that does not set its own.')
                    ->statePath('defaults')
                    ->columns(2)
                    ->schema(ReviewSettingsForm::claudeFields())
                    ->visible(fn (Get $get): bool => $get('driver') === 'anthropic'),
                Section::make(self::REVIEWERS['openai']['label'])
                    ->description('For every repository that does not set its own.')
                    ->statePath('defaults')
                    ->columns(2)
                    ->schema(ReviewSettingsForm::codexFields())
                    ->visible(fn (Get $get): bool => $get('driver') === 'openai'),
            ])
            ->columns(1);
    }

    protected static function editAction(): EditAction
    {
        return EditAction::make()
            // Secrets are never sent back to the browser.
            ->mutateRecordDataUsing(fn (array $data, Connection $record): array => [
                'credentials' => [],
                'defaults' => self::defaultsFor($record->driver),
            ] + $data)
            ->using(function (Connection $record, array $data, Connections $connections): Connection {
                $record->fill(['name' => $data['name'] ?? $record->name, 'settings' => $data['settings'] ?? $record->settings]);
                $record->credentials = $connections->mergeCredentials($record, (array) ($data['credentials'] ?? []));

                DB::transaction(function () use ($record, $data): void {
                    $record->save();
                    self::saveDefaults($record->driver, (array) ($data['defaults'] ?? []));
                });

                return $record;
            });
    }

    /**
     * The tenant's review settings that belong to the reviewer on this provider.
     *
     * @return array<string, mixed>
     */
    public static function defaultsFor(string $driver): array
    {
        $fields = self::REVIEWERS[$driver]['fields'] ?? [];

        return array_intersect_key(ReviewSettingsForm::state(ReviewSettings::defaults(CurrentTenant::get())), array_flip($fields));
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function saveDefaults(string $driver, array $values): void
    {
        $fields = self::REVIEWERS[$driver]['fields'] ?? [];

        if ($fields !== []) {
            ReviewSettings::saveDefaults(CurrentTenant::get(), array_intersect_key($values, array_flip($fields)) + array_fill_keys($fields, null));
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAiProviders::route('/'),
        ];
    }
}
