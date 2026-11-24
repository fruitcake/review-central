<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\PullRequests\Pages;

use App\Filament\App\Resources\PullRequests\PullRequestResource;
use App\Models\OpenPullRequest;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListPullRequests extends ListRecords
{
    protected static string $resource = PullRequestResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'The open pull requests of this team\'s repositories, as their webhooks told of them since, with whose turn each one is. One a repository does not review by itself, from a fork or from an account this team does not trust, waits here for someone to start it. "With me" goes by the accounts on your profile. Merged and declined ones stay '.OpenPullRequest::CLOSED_DAYS.' days.';
    }

    public function getTabs(): array
    {
        $user = auth()->user();
        $mine = fn (): array => once(fn (): array => PullRequestResource::withUser($user instanceof User ? $user : null));

        $views = [
            'all' => ['All', fn (Builder $query): Builder => $query],
            // Whose turn is read from the reviewers and the AI review, not a column.
            'mine' => ['With me', fn (Builder $query): Builder => $query->whereKey($mine())],
            'waiting' => ['Waiting for approval', fn (Builder $query): Builder => $query->where('awaiting_approval', true)],
            'draft' => ['Draft', fn (Builder $query): Builder => $query->where('draft', true)],
            // The closed ones, which the list leaves out otherwise.
            'merged' => ['Merged', fn (Builder $query): Builder => $query->withoutGlobalScope(OpenPullRequest::OPEN)->where('closed_as', OpenPullRequest::MERGED)],
            'declined' => ['Declined', fn (Builder $query): Builder => $query->withoutGlobalScope(OpenPullRequest::OPEN)->where('closed_as', OpenPullRequest::DECLINED)],
        ];

        return array_map(fn (array $view): Tab => Tab::make($view[0])
            ->badge($view[1](PullRequestResource::getEloquentQuery())->count())
            ->modifyQueryUsing($view[1]), $views);
    }
}
