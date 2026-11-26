<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ReviewRules;

use App\Enums\ReviewRuleKind;
use App\Filament\App\Resources\ReviewRules\Pages\ManageReviewRules;
use App\Filament\CurrentTenant;
use App\Filament\TenantRole;
use App\Models\Repository;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * A team's own rules for its reviews. Everyone in the team reads them, as the
 * reviewer does; its admins write them.
 */
class ReviewRuleResource extends Resource
{
    protected static ?string $model = ReviewRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 36;

    public static function getModelLabel(): string
    {
        return __('rule');
    }

    public static function getPluralModelLabel(): string
    {
        return __('rules');
    }

    public static function getNavigationLabel(): string
    {
        return __('Rules');
    }

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
                ->label(__('Kind'))
                ->options(ReviewRuleKind::options())
                ->descriptions(array_column(array_map(fn (ReviewRuleKind $kind): array => [$kind->value, $kind->description()], ReviewRuleKind::cases()), 1, 0))
                ->default(ReviewRuleKind::Check->value)
                ->required()
                ->live(),
            TextInput::make('title')
                ->label(__('Rule'))
                ->required()
                ->maxLength(255)
                ->helperText(__('In one line, as the reviewer cites it: "Every controller action checks the policy", "Raw queries in migrations are fine".')),
            Textarea::make('body')
                ->label(__('Why, and what counts'))
                ->rows(4)
                ->maxLength(5000)
                ->helperText(__('Optional. What the rule protects, and the cases it does and does not cover.')),
            Select::make('repository_id')
                ->label(__('For'))
                ->options(fn (): array => self::repositoryOptions())
                ->placeholder(__('Every repository')),
            TagsInput::make('paths')
                ->label(__('Only in'))
                ->placeholder('app/Http/Controllers/**')
                ->helperText(__('Optional globs. Without any, the rule holds for every file.')),
            Select::make('severity')
                ->label(__('Severity when broken'))
                ->options(ReviewRule::severities())
                ->placeholder(__('As the reviewer judges it'))
                ->visible(fn (Get $get): bool => $get('kind') === ReviewRuleKind::Check->value || $get('kind') === ReviewRuleKind::Check),
            Toggle::make('enabled')
                ->label(__('On'))
                ->default(true)
                ->helperText(__('Off, the reviewer does not read it, and it stays here.')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('repository')->orderByRaw('repository_id is not null')->orderBy('title'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('Rule'))
                    ->wrap()
                    ->description(fn (ReviewRule $record): ?string => $record->body === null ? null : str($record->body)->limit(160)->toString())
                    ->searchable(['title', 'body']),
                TextColumn::make('kind')
                    ->label(__('Kind'))
                    ->badge()
                    ->formatStateUsing(fn (ReviewRuleKind $state): string => $state->text())
                    ->color(fn (ReviewRuleKind $state): string => $state === ReviewRuleKind::Check ? 'primary' : 'gray'),
                TextColumn::make('repository_id')
                    ->label(__('For'))
                    ->formatStateUsing(fn (ReviewRule $record): string => self::repositoryLabel($record))
                    ->placeholder(__('Every repository')),
                TextColumn::make('paths')
                    ->label(__('Only in'))
                    ->badge()
                    ->color('gray')
                    ->placeholder(__('Every file')),
                TextColumn::make('severity')
                    ->label(__('Severity'))
                    ->formatStateUsing(fn (?string $state): ?string => ReviewRule::severities()[$state] ?? $state)
                    ->placeholder('—'),
                ToggleColumn::make('enabled')
                    ->label(__('On'))
                    ->disabled(fn (): bool => ! TenantRole::isAdmin()),
                TextColumn::make('archived_to')
                    ->label(__('In the repository'))
                    ->description(fn (ReviewRule $record): ?string => $record->archived_note)
                    ->tooltip(function (ReviewRule $record): ?string {
                        if ($record->archived_at !== null) {
                            return __('Found on the main branch :when, and turned off here. Turned on again, it stays on.', ['when' => $record->archived_at->diffForHumans()]);
                        }

                        if ($record->archived_to !== null) {
                            return __('Found on the main branch, and turned on again by hand: the check leaves it on.');
                        }

                        return null;
                    })
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('repository_id')
                    ->label(__('For'))
                    ->options(fn (): array => ['all' => __('Every repository')] + self::repositoryOptions())
                    // A repository's rules are its own and those for every repository: what its review reads.
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'all' => $query->whereNull('repository_id'),
                        default => $query->where(fn (Builder $scope) => $scope->whereNull('repository_id')->orWhere('repository_id', (int) $data['value'])),
                    }),
                SelectFilter::make('kind')->label(__('Kind'))->options(ReviewRuleKind::options()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No rules yet'))
            ->emptyStateDescription(__('A rule says what the reviewer checks in your code, or what is not a defect here. Mark a finding "Not a problem" to make one from it.'));
    }

    /**
     * The team's repositories by id, each named so that two cannot be mistaken for
     * one another: by workspace and name; where two connections have one of the
     * same name, by connection as well; and where those connections are named alike
     * too, by the connection's number. Removed ones are left out.
     *
     * @return array<int, string>
     */
    public static function repositoryOptions(): array
    {
        return self::labels(CurrentTenant::get()->repositories()->with('integration')->orderBy('workspace')->orderBy('slug')->get());
    }

    /**
     * The label of a rule's repository in the list: as the options name it, or by
     * its full name and "removed" once it was removed.
     */
    public static function repositoryLabel(ReviewRule $rule): string
    {
        if ($rule->repository === null) {
            return __('Every repository');
        }

        return self::repositoryOptions()[$rule->repository_id] ?? __(':repository (removed)', ['repository' => $rule->repository->fullName()]);
    }

    /**
     * @param  Collection<int, Repository>  $repositories
     * @return array<int, string>
     */
    protected static function labels(Collection $repositories): array
    {
        $byName = $repositories->groupBy(fn (Repository $repository): string => $repository->fullName());

        return $repositories->mapWithKeys(function (Repository $repository) use ($byName): array {
            $same = $byName[$repository->fullName()];

            if ($same->count() === 1) {
                return [$repository->getKey() => $repository->fullName()];
            }

            $connection = $repository->integration->name ?? __('a connection');
            $alike = $same->filter(fn (Repository $other): bool => ($other->integration->name ?? __('a connection')) === $connection)->count() > 1;

            return [$repository->getKey() => $repository->fullName().' ('.$connection.($alike ? " #{$repository->connection_id}" : '').')'];
        })->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageReviewRules::route('/'),
        ];
    }
}
