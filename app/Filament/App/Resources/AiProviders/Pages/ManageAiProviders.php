<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\AiProviders\Pages;

use App\Filament\App\Resources\AiProviders\AiProviderResource;
use App\Filament\App\Resources\Connections\Pages\ManageConnections;
use App\Filament\CurrentTenant;
use App\Models\Connection;
use App\Services\Connections;
use Filament\Actions\CreateAction;

class ManageAiProviders extends ManageConnections
{
    protected static string $resource = AiProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data, Connections $connections): Connection {
                    $connection = new Connection($data);
                    $connection->tenant()->associate(CurrentTenant::get());
                    $connections->prepare($connection);
                    $connection->save();

                    return $connection;
                }),
        ];
    }
}
