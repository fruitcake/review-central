<?php

namespace App\Filament\App\Resources\ReviewRules\Pages;

use App\Exceptions\ActionRefused;
use App\Filament\App\Resources\ReviewRules\ReviewRuleResource;
use App\Filament\Concerns\HasMyRepositoriesTab;
use App\Filament\CurrentTenant;
use App\Filament\TenantRole;
use App\Models\User;
use App\Services\ReviewRuleArchive;
use App\Services\ReviewRules;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ManageReviewRules extends ManageRecords
{
    use HasMyRepositoriesTab;

    protected static string $resource = ReviewRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')
                ->label(__('Import CSV'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->visible(fn (): bool => TenantRole::isAdmin())
                ->modalHeading(__('Import rules from a CSV'))
                ->modalDescription(__('The first row names the columns: kind (check or convention) and title, and optionally body, paths (globs separated by ;), severity (HIGH, MEDIUM or LOW) and repository (workspace/name, or empty for every repository). A rule already here with the same kind, title and repository is skipped.'))
                ->schema([
                    FileUpload::make('file')
                        ->label(__('CSV file'))
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->maxSize(5120)
                        ->storeFiles(false)
                        ->required(),
                ])
                ->modalSubmitActionLabel(__('Import'))
                ->action(function (array $data, ReviewRules $rules): void {
                    $file = $data['file'] ?? null;
                    $user = auth()->user();

                    if (! $file instanceof TemporaryUploadedFile || ! $user instanceof User) {
                        return;
                    }

                    try {
                        $result = $rules->import($user, CurrentTenant::get(), (string) $file->get());
                    } catch (ActionRefused $exception) {
                        Notification::make()->title(__('The rules could not be imported'))->body($exception->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    $skipped = count($result['skipped']);

                    $notification = Notification::make()
                        ->title($result['created'] === 1 ? __('One rule imported') : __(':count rules imported', ['count' => $result['created']]))
                        ->status($skipped === 0 ? 'success' : 'warning');

                    if ($skipped > 0) {
                        $notification->body(__(':count skipped: :reasons', ['count' => $skipped, 'reasons' => Str::limit(implode(' ', array_slice($result['skipped'], 0, 5)), 600)]));
                    }

                    // What was skipped stays until it is read.
                    ($skipped === 0 ? $notification : $notification->persistent())->send();
                }),
            Action::make('checkRepository')
                ->label(__('Check the repository'))
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->visible(fn (): bool => TenantRole::isAdmin())
                ->modalHeading(__('Check for rules the repository holds itself'))
                ->modalDescription(__('Reads the rule files on the repository\'s main branch (.ai/rules, .claude/rules, .github/instructions, .cursor/rules) and turns off each of its own rules found there, since the review reads those files already. Rules for every repository stay on. This runs by itself after each merge as well.'))
                ->schema([
                    Select::make('repository_id')
                        ->label(__('Repository'))
                        ->options(fn (): array => ReviewRuleResource::repositoryOptions())
                        ->required(),
                ])
                ->modalSubmitActionLabel(__('Check'))
                ->action(function (array $data, ReviewRuleArchive $archive): void {
                    $repository = CurrentTenant::get()->repositories()->whereKey((int) $data['repository_id'])->first();

                    if ($repository === null) {
                        return;
                    }

                    $result = $archive->check($repository, always: true);

                    Notification::make()
                        ->title(match (count($result['archived'])) {
                            0 => __('No rule turned off'),
                            1 => __('One rule turned off'),
                            default => __(':count rules turned off', ['count' => count($result['archived'])]),
                        })
                        ->body($result['archived'] === [] ? $result['note'] : Str::limit(implode(' ', array_map(fn (array $rule): string => __('":title" is in :file.', ['title' => $rule['title'], 'file' => $rule['file']]), $result['archived'])), 600))
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }

    /**
     * A rule for every repository holds for the user's as well.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $views = [
            'all' => [__('All repositories'), fn (Builder $query): Builder => $query],
            self::MY_REPOSITORIES => [__('My repositories'), fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $this->inMyRepositories()($query)->orWhereNull('repository_id'))],
        ];

        return array_map(fn (array $view): Tab => Tab::make($view[0])
            ->badge($view[1](CurrentTenant::get()->reviewRules()->getQuery())->count())
            ->modifyQueryUsing($view[1]), $views);
    }
}
