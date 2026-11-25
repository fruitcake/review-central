<?php

namespace App\Filament\App\Resources\Repositories\Pages;

use App\Filament\App\Resources\Repositories\RepositoryResource;
use App\Filament\Concerns\HasMyRepositoriesTab;
use App\Filament\CurrentTenant;
use App\Models\Repository;
use App\Services\RepositorySetup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageRepositories extends ManageRecords
{
    use HasMyRepositoriesTab;

    protected static string $resource = RepositoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                // The form asks for the connection and the repository only. The runner
                // field is hidden when one runner is allowed, and a hidden field stores
                // nothing: the repository would keep the column's own default, and move
                // to it the day the setting is removed. One that was removed comes back.
                ->using(fn (array $data, RepositorySetup $setup): Repository => $setup->add(
                    CurrentTenant::get(),
                    CurrentTenant::get()->connections()->whereKey($data['connection_id'])->firstOrFail(),
                    $data,
                )),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $views = [
            'all' => ['All', fn (Builder $query): Builder => $query],
            self::MY_REPOSITORIES => ['Mine', $this->inMyRepositories('id')],
        ];

        return array_map(fn (array $view): Tab => Tab::make($view[0])
            ->badge($view[1](CurrentTenant::get()->repositories()->getQuery())->count())
            ->modifyQueryUsing($view[1]), $views);
    }
}
