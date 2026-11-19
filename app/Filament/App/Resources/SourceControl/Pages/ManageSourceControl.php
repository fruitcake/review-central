<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\SourceControl\Pages;

use App\Filament\App\Resources\Connections\Pages\ManageConnections;
use App\Filament\App\Resources\SourceControl\SourceControlResource;

class ManageSourceControl extends ManageConnections
{
    protected static string $resource = SourceControlResource::class;
}
