<?php

namespace App\Filament\App\Resources\Repositories\Pages;

use App\Filament\App\Resources\Repositories\RepositoryResource;
use App\Filament\CurrentTenant;
use App\Services\RepositorySetup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRepositories extends ManageRecords
{
    protected static string $resource = RepositoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                // The form asks for the connection and the repository only. The runner
                // field is hidden when one runner is allowed, and a hidden field stores
                // nothing: the repository would keep the column's own default, and move
                // to it the day the setting is removed.
                ->mutateDataUsing(fn (array $data, RepositorySetup $setup): array => $setup->complete(
                    $data,
                    CurrentTenant::get()->connections()->whereKey($data['connection_id'])->firstOrFail(),
                )),
        ];
    }
}
