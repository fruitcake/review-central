<?php

namespace App\Filament\App\Resources\ReviewRules\Pages;

use App\Filament\App\Resources\ReviewRules\ReviewRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageReviewRules extends ManageRecords
{
    protected static string $resource = ReviewRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
