<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\AiProviders\Pages;

use App\Filament\App\Resources\AiProviders\AiProviderResource;
use App\Filament\App\Resources\Connections\Pages\ManageConnections;
use App\Filament\CurrentTenant;
use App\Models\Connection;
use App\Services\Connections;
use Filament\Actions\CreateAction;
use Illuminate\Support\Facades\DB;

class ManageAiProviders extends ManageConnections
{
    protected static string $resource = AiProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data, Connections $connections): Connection {
                    $defaults = (array) ($data['defaults'] ?? []);
                    unset($data['defaults']);

                    $connection = new Connection($data);
                    $connection->tenant()->associate(CurrentTenant::get());
                    $connections->prepare($connection);

                    // What was left empty keeps what the tenant set before: a second key
                    // for the same provider does not wipe its reviewer's settings.
                    $defaults = array_filter($defaults, fn (mixed $value): bool => filled($value)) + AiProviderResource::defaultsFor($connection->driver);

                    DB::transaction(function () use ($connection, $defaults): void {
                        $connection->save();
                        AiProviderResource::saveDefaults($connection->driver, $defaults);
                    });

                    return $connection;
                }),
        ];
    }
}
