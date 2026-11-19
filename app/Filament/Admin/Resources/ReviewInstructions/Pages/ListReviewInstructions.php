<?php

namespace App\Filament\Admin\Resources\ReviewInstructions\Pages;

use App\Filament\Admin\Resources\ReviewInstructions\ReviewInstructionResource;
use App\Services\ReviewInstructions;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListReviewInstructions extends ListRecords
{
    protected static string $resource = ReviewInstructionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // What a new release changes, shown before it is taken: per file what
            // would change, and whether someone here edited it since.
            Action::make('import')
                ->label(fn (): string => ($count = count(ReviewInstructions::updates())) > 0 ? "Update from this release ({$count})" : 'Update from this release')
                ->color(fn (): string => ReviewInstructions::updates() === [] ? 'gray' : 'primary')
                ->modalHeading('Update the instructions from this release')
                ->modalDescription(fn (): string => ReviewInstructions::updates() === []
                    ? 'Everything here is what this release ships. Nothing to update.'
                    : 'What this release ships differs from what is kept here. Pick what to take; a file someone here edited is not picked, since taking it undoes that edit. A tenant\'s own copy of a file is never touched.')
                ->modalContent(fn () => view('filament.admin.instruction-updates', ['updates' => ReviewInstructions::updates()]))
                ->schema(fn (): array => ReviewInstructions::updates() === [] ? [] : [
                    CheckboxList::make('keys')
                        ->label('Take the shipped text for')
                        ->options(collect(ReviewInstructions::updates())->mapWithKeys(fn (array $update): array => [$update['key'] => $update['title'].' — '.match ($update['state']) {
                            'new' => 'new in this release',
                            'outdated' => 'changed in this release',
                            default => 'changed in this release, and edited here',
                        }])->all())
                        ->default(collect(ReviewInstructions::updates())->where('state', '!=', 'edited')->pluck('key')->all()),
                ])
                ->modalSubmitActionLabel('Update')
                ->modalWidth('5xl')
                ->action(function (array $data): void {
                    $done = ReviewInstructions::applyUpdates(array_values((array) ($data['keys'] ?? [])));

                    Notification::make()
                        ->title($done === [] ? 'Nothing updated' : 'Updated')
                        ->body($done === [] ? null : count($done).' file(s) now have this release\'s text: '.implode(', ', $done).'.')
                        ->success()
                        ->send();
                }),
            Action::make('structure')
                ->label('Change how a review works')
                ->color('gray')
                ->modalDescription('How a review goes about its work and what its report looks like ships with the review, and its scripts read that report. Changing it here replaces it for every tenant, from the next review on. A report the scripts cannot read is a review that fails, so change the wording of what to do, and leave the report\'s fields and the names of the sections as they are. Deleting the copy puts the shipped text back.')
                ->schema([
                    Select::make('key')
                        ->label('Which')
                        ->options([
                            ReviewInstructions::STRUCTURE => 'The review of a pull request: its flow and its report',
                            ReviewInstructions::SECURITY => 'The security review of a branch: its whole prompt',
                        ])
                        ->required(),
                ])
                ->modalSubmitActionLabel('Make a copy to edit')
                ->action(fn (array $data) => redirect()->to(ReviewInstructionResource::getUrl('edit', ['record' => ReviewInstructions::customiseBase($data['key'])]))),
            CreateAction::make(),
        ];
    }
}
