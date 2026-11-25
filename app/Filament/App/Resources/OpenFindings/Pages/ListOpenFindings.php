<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\OpenFindings\Pages;

use App\Filament\App\Resources\OpenFindings\OpenFindingResource;
use App\Filament\Concerns\HasMyRepositoriesTab;
use App\Filament\CurrentTenant;
use App\Models\OpenFinding;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOpenFindings extends ListRecords
{
    use HasMyRepositoriesTab;

    protected static string $resource = OpenFindingResource::class;

    public function getSubheading(): ?string
    {
        return 'Findings a pull request was merged with while its last review still had them open. Review defaults sets how often they are checked against the main branch.';
    }

    /**
     * Counted as the navigation counts them: those still on the backlog.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $views = [
            'all' => ['All repositories', fn (Builder $query): Builder => $query],
            self::MY_REPOSITORIES => ['My repositories', $this->inMyRepositories()],
        ];

        return array_map(fn (array $view): Tab => Tab::make($view[0])
            ->badge($view[1](OpenFinding::query()->where('tenant_id', CurrentTenant::get()->getKey())->active())->count())
            ->modifyQueryUsing($view[1]), $views);
    }
}
