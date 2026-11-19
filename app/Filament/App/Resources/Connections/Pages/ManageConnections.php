<?php

namespace App\Filament\App\Resources\Connections\Pages;

use App\Filament\App\Resources\Connections\ConnectionResource;
use App\Filament\CurrentTenant;
use App\Models\Connection;
use App\Services\Connections;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageConnections extends ManageRecords
{
    protected static string $resource = ConnectionResource::class;

    protected function getHeaderActions(): array
    {
        // What is left here is only to look at, or remove.
        if (static::getResource()::kind() === null) {
            return [];
        }

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
