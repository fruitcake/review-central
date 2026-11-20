<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ReviewComparisons\Pages;

use App\Connections\LlmDriver;
use App\Enums\ConnectionKind;
use App\Filament\App\Resources\ReviewComparisons\ReviewComparisonResource;
use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Filament\Components\ReviewSettingsForm;
use App\Filament\Concerns\RefreshesOnBroadcast;
use App\Filament\CurrentTenant;
use App\Jobs\AnalyzeReviewComparison;
use App\Models\Connection;
use App\Models\ReviewComparison;
use App\Services\ReviewComparisons;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * @property ReviewComparison $record
 */
class ViewReviewComparison extends ViewRecord
{
    use RefreshesOnBroadcast;

    protected static string $resource = ReviewComparisonResource::class;

    protected string $view = 'filament.app.comparison';

    public function getTitle(): string|Htmlable
    {
        return "Comparison of {$this->record->repository->slug} #{$this->record->pull_request_id}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label('Add setups')
                ->icon(Heroicon::OutlinedPlus)
                ->color('gray')
                ->visible(fn (): bool => $this->record->reviewRun !== null && count($this->record->rows) < ReviewComparison::MAX_ROWS)
                ->modalHeading('Add setups to this comparison')
                ->modalDescription(fn (): string => 'Each row reviews the pull request as it is now, as a trial, and shows as a column of its own. Up to '.(ReviewComparison::MAX_ROWS - count($this->record->rows)).' more. An analysis made before keeps to the reviews it read: analyse again to judge the new ones too.')
                ->schema([
                    ReviewRunResource::ignoreExistingField(),
                    Repeater::make('rows')
                        ->label('Setups')
                        ->schema(ReviewRunResource::trialFields(withExisting: false))
                        ->columns(2)
                        ->default(fn (): array => [[]])
                        ->minItems(1)
                        ->maxItems(fn (): int => max(1, ReviewComparison::MAX_ROWS - count($this->record->rows)))
                        ->addActionLabel('Add a setup'),
                ])
                ->modalSubmitActionLabel('Start')
                ->action(function (array $data, ReviewComparisons $comparisons): void {
                    $added = $comparisons->add($this->record, array_map(ReviewRunResource::trialOptions(...), array_values($data['rows'] ?? [])), (bool) ($data['ignore_existing'] ?? true));
                    $this->record->refresh();

                    Notification::make()->title($added === 1 ? 'One setup added' : "{$added} setups added")->success()->send();
                }),
            Action::make('analyze')
                ->label(fn (): string => $this->record->analysis === null ? 'Analyse' : 'Analyse again')
                ->icon(Heroicon::OutlinedSparkles)
                ->disabled(fn (): bool => ! $this->record->done() || $this->record->analysis_status === ReviewComparison::ANALYSIS_RUNNING || $this->record->active()->filter(fn (array $column): bool => $column['run'] !== null)->isEmpty())
                ->tooltip(fn (): ?string => $this->record->done() ? null : 'Once every review not left out has ended.')
                ->modalHeading('Analyse the reviews together')
                ->modalDescription('One model reads the pull request\'s diff and every review\'s findings, groups the same issues, says which hold and which are false, and who missed what. It costs one request with the whole diff.')
                ->schema([
                    Select::make('connection')
                        ->label('AI provider')
                        ->options(fn (): array => self::connections())
                        ->default(fn (): ?int => array_key_first(self::connections()))
                        ->required(),
                    ReviewSettingsForm::modelField('model', ['smartest' => 'smartest (Laravel AI)', 'default' => 'default (Laravel AI)'] + array_diff_key(ReviewSettingsForm::claudeModels() + ReviewSettingsForm::openAiModels(), array_flip(['smartest', 'default', 'cheapest', 'opus', 'sonnet', 'haiku'])))
                        ->label('Model')
                        ->placeholder('The provider\'s smartest')
                        ->helperText('A tier is read for the provider chosen; a model by name has to be one of that provider.'),
                ])
                ->modalSubmitActionLabel('Analyse')
                ->action(function (array $data): void {
                    $this->record->update(['analysis_status' => ReviewComparison::ANALYSIS_RUNNING, 'analysis_error' => null]);
                    // The reviews it reads are the ones that can be read now.
                    AnalyzeReviewComparison::dispatch($this->record->getKey(), (int) $data['connection'], filled($data['model'] ?? null) ? (string) $data['model'] : null, ReviewComparisons::analysable($this->record));

                    Notification::make()->title('The analysis is started')->body('This page shows it when it is done.')->success()->send();
                }),
        ];
    }

    /**
     * Leaves a column out of the comparison, or brings it back: left out, it moves
     * to the end, faded, and is neither waited for, analysed nor scored.
     */
    public function toggleColumn(string $label): void
    {
        if (! $this->record->columns()->has($label)) {
            return;
        }

        $this->record->toggleExcluded($label);
    }

    /**
     * The tenant's AI providers, by id.
     *
     * @return array<int, string>
     */
    protected static function connections(): array
    {
        return CurrentTenant::get()->connections()->where('kind', ConnectionKind::Llm)->oldest('id')->get()
            ->filter(fn (Connection $connection): bool => rescue(fn (): bool => $connection->driver() instanceof LlmDriver, false, false))
            ->mapWithKeys(fn (Connection $connection): array => [$connection->getKey() => $connection->name])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $comparison = $this->record;

        return [
            'comparison' => $comparison,
            'columns' => $comparison->columns(),
            'scores' => ReviewComparisons::scores($comparison),
            'done' => $comparison->done(),
            'settled' => $comparison->settled(),
            // What the analysis read, against what would be read now.
            // What the saved scores are of, whatever became of a later attempt; an
            // analysis from before labels were kept read every review there was.
            'analysed' => $comparison->analysis === null ? null : ($comparison->analysis['labels'] ?? array_map('strval', $comparison->columns()->filter(fn (array $column): bool => $column['run'] !== null)->keys()->all())),
            'analysable' => ReviewComparisons::analysable($comparison),
        ];
    }
}
