<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ReviewRules;

use App\Enums\ReviewRuleKind;
use App\Filament\App\Resources\ReviewRules\Pages\ManageReviewRules;
use App\Filament\CurrentTenant;
use App\Filament\TenantRole;
use App\Models\ReviewRule;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A team's own rules for its reviews. Everyone in the team reads them, as the
 * reviewer does; its admins write them.
 */
class ReviewRuleResource extends Resource
{
    protected static ?string $model = ReviewRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $navigationLabel = 'Rules';

    protected static ?string $modelLabel = 'rule';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 36;

    public static function canCreate(): bool
    {
        return TenantRole::isAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return TenantRole::isAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return TenantRole::isAdmin();
    }

    /**
     * Filament asks a delete action this, not canDelete(): without a policy it
     * would let a member remove a rule.
     */
    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return TenantRole::isAdmin() ? Response::allow() : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::fields())->columns(1);
    }

    /**
     * The fields of a rule, for the rules page and for a rule made from a finding.
     *
     * @return array<int, Component>
     */
    public static function fields(): array
    {
        return [
            Radio::make('kind')
                ->options(ReviewRuleKind::options())
                ->descriptions(array_column(array_map(fn (ReviewRuleKind $kind): array => [$kind->value, $kind->description()], ReviewRuleKind::cases()), 1, 0))
                ->default(ReviewRuleKind::Check->value)
                ->required()
                ->live(),
            TextInput::make('title')
                ->label('Rule')
                ->required()
                ->maxLength(255)
                ->helperText('In one line, as the reviewer cites it: "Every controller action checks the policy", "Raw queries in migrations are fine".'),
            Textarea::make('body')
                ->label('Why, and what counts')
                ->rows(4)
                ->maxLength(5000)
                ->helperText('Optional. What the rule protects, and the cases it does and does not cover.'),
            Select::make('repository_id')
                ->label('For')
                ->options(fn (): array => CurrentTenant::get()->repositories()->orderBy('slug')->pluck('slug', 'id')->all())
                ->placeholder('Every repository'),
            TagsInput::make('paths')
                ->label('Only in')
                ->placeholder('app/Http/Controllers/**')
                ->helperText('Optional globs. Without any, the rule holds for every file.'),
            Select::make('severity')
                ->label('Severity when broken')
                ->options(ReviewRule::SEVERITIES)
                ->placeholder('As the reviewer judges it')
                ->visible(fn (Get $get): bool => $get('kind') === ReviewRuleKind::Check->value || $get('kind') === ReviewRuleKind::Check),
            Toggle::make('enabled')
                ->label('On')
                ->default(true)
                ->helperText('Off, the reviewer does not read it, and it stays here.'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('repository')->orderByRaw('repository_id is not null')->orderBy('title'))
            ->columns([
                TextColumn::make('title')
                    ->label('Rule')
                    ->wrap()
                    ->description(fn (ReviewRule $record): ?string => $record->body === null ? null : str($record->body)->limit(160)->toString())
                    ->searchable(['title', 'body']),
                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (ReviewRuleKind $state): string => $state->text())
                    ->color(fn (ReviewRuleKind $state): string => $state === ReviewRuleKind::Check ? 'primary' : 'gray'),
                TextColumn::make('repository.slug')
                    ->label('For')
                    ->placeholder('Every repository'),
                TextColumn::make('paths')
                    ->label('Only in')
                    ->badge()
                    ->color('gray')
                    ->placeholder('Every file'),
                TextColumn::make('severity')
                    ->formatStateUsing(fn (?string $state): ?string => ReviewRule::SEVERITIES[$state] ?? $state)
                    ->placeholder('—'),
                ToggleColumn::make('enabled')
                    ->label('On')
                    ->disabled(fn (): bool => ! TenantRole::isAdmin()),
            ])
            ->filters([
                SelectFilter::make('repository_id')
                    ->label('For')
                    ->options(fn (): array => ['all' => 'Every repository'] + CurrentTenant::get()->repositories()->orderBy('slug')->pluck('slug', 'id')->all())
                    // A repository's rules are its own and those for every repository: what its review reads.
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'all' => $query->whereNull('repository_id'),
                        default => $query->where(fn (Builder $scope) => $scope->whereNull('repository_id')->orWhere('repository_id', (int) $data['value'])),
                    }),
                SelectFilter::make('kind')->options(ReviewRuleKind::options()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No rules yet')
            ->emptyStateDescription('A rule says what the reviewer checks in your code, or what is not a defect here. Mark a finding "Not a problem" to make one from it.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageReviewRules::route('/'),
        ];
    }
}
