<?php

namespace App\Filament\App\Resources\ReviewRules\Pages;

use App\Filament\App\Resources\ReviewRules\ReviewRuleResource;
use App\Filament\Concerns\HasMyRepositoriesTab;
use App\Filament\CurrentTenant;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageReviewRules extends ManageRecords
{
    use HasMyRepositoriesTab;

    protected static string $resource = ReviewRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * A rule for every repository holds for the user's as well.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $views = [
            'all' => ['All repositories', fn (Builder $query): Builder => $query],
            self::MY_REPOSITORIES => ['My repositories', fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $this->inMyRepositories()($query)->orWhereNull('repository_id'))],
        ];

        return array_map(fn (array $view): Tab => Tab::make($view[0])
            ->badge($view[1](CurrentTenant::get()->reviewRules()->getQuery())->count())
            ->modifyQueryUsing($view[1]), $views);
    }
}
