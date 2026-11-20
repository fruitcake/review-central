<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ReviewComparisons\Pages;

use App\Filament\App\Resources\ReviewComparisons\ReviewComparisonResource;
use Filament\Resources\Pages\ListRecords;

class ListReviewComparisons extends ListRecords
{
    protected static string $resource = ReviewComparisonResource::class;

    public function getSubheading(): ?string
    {
        return 'Start one from a review of a pull request: Compare with, then a row for each setup to try.';
    }
}
