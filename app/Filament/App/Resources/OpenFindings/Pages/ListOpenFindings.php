<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\OpenFindings\Pages;

use App\Filament\App\Resources\OpenFindings\OpenFindingResource;
use Filament\Resources\Pages\ListRecords;

class ListOpenFindings extends ListRecords
{
    protected static string $resource = OpenFindingResource::class;

    public function getSubheading(): ?string
    {
        return 'Findings a pull request was merged with while its last review still had them open. Review defaults sets how often they are checked against the main branch.';
    }
}
