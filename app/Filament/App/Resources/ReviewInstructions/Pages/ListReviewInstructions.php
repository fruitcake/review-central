<?php

namespace App\Filament\App\Resources\ReviewInstructions\Pages;

use App\Filament\App\Resources\ReviewInstructions\ReviewInstructionResource;
use App\Filament\CurrentTenant;
use App\Services\ReviewInstructions;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;

class ListReviewInstructions extends ListRecords
{
    protected static string $resource = ReviewInstructionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fromDefault')
                ->label('Start from a default')
                ->color('gray')
                ->modalDescription('Copies a default instruction into your own, unchanged, so you can edit it. From then on your repositories read your copy and no longer follow changes to the default.')
                ->schema([
                    Select::make('key')
                        ->label('Default instruction')
                        ->options(fn (): array => ReviewInstructions::defaultsWithoutCopy(CurrentTenant::get())->pluck('title', 'key')->all())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $copy = ReviewInstructions::copyDefault(CurrentTenant::get(), $data['key']);

                    $this->redirect(ReviewInstructionResource::getUrl('edit', ['record' => $copy]));
                }),
            CreateAction::make(),
        ];
    }
}
