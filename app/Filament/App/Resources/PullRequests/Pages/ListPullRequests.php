<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\PullRequests\Pages;

use App\Filament\App\Resources\PullRequests\PullRequestResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListPullRequests extends ListRecords
{
    protected static string $resource = PullRequestResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'The open pull requests of this team\'s repositories, as their webhooks told of them since. One a repository does not review by itself, from a fork or from an account this team does not trust, waits here for someone to start it.';
    }

    public function getTabs(): array
    {
        $views = [
            'all' => ['All', fn (Builder $query): Builder => $query],
            'waiting' => ['Waiting for approval', fn (Builder $query): Builder => $query->where('awaiting_approval', true)],
        ];

        return array_map(fn (array $view): Tab => Tab::make($view[0])
            ->badge($view[1](PullRequestResource::getEloquentQuery())->count())
            ->modifyQueryUsing($view[1]), $views);
    }
}
