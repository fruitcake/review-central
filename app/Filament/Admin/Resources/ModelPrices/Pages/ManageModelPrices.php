<?php

namespace App\Filament\Admin\Resources\ModelPrices\Pages;

use App\Filament\Admin\Resources\ModelPrices\ModelPriceResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Artisan;

class ManageModelPrices extends ManageRecords
{
    protected static string $resource = ModelPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Sync now')
                ->action(function (): void {
                    $failed = Artisan::call('review:sync-model-prices') !== 0;

                    Notification::make()
                        ->title($failed ? 'The prices could not be synced' : 'Prices synced')
                        ->body(trim(Artisan::output()))
                        ->status($failed ? 'danger' : 'success')
                        ->send();
                }),
        ];
    }
}
