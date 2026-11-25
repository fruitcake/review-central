<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OpenPullRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The repositories of a tenant someone works on: those with a pull request they
 * opened or were asked to review, open or closed in the last CLOSED_DAYS, as the
 * webhooks told of them. Who someone is on the host goes by User::isOnHost().
 */
class RelevantRepositories
{
    /**
     * The ids of the tenant's repositories the user works on.
     *
     * @return array<int, int>
     */
    public function for(User $user, Tenant $tenant): array
    {
        return $this->pullRequests($tenant)
            ->filter(fn (OpenPullRequest $record): bool => $this->involves($user, $record))
            ->pluck('repository_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The open pull requests of the tenant the user opened.
     *
     * @return Collection<int, OpenPullRequest>
     */
    public function openedBy(User $user, Tenant $tenant): Collection
    {
        return OpenPullRequest::query()
            ->whereIn('repository_id', $tenant->repositories()->select('id'))
            ->with('repository.tenant')
            ->latest('updated_at')
            ->get()
            ->filter(fn (OpenPullRequest $record): bool => $user->isOnHost($record->authorPerson()))
            ->values();
    }

    /**
     * Whether the user opened the pull request or was asked to review it.
     */
    public function involves(User $user, OpenPullRequest $record): bool
    {
        return $user->isOnHost($record->authorPerson())
            || collect($record->reviewers ?? [])->contains(fn (array $reviewer): bool => $user->isOnHost($reviewer));
    }

    /**
     * Every pull request of the tenant's repositories that is still kept, also a
     * closed one, with only what tells who took part.
     *
     * @return Collection<int, OpenPullRequest>
     */
    protected function pullRequests(Tenant $tenant): Collection
    {
        return OpenPullRequest::query()
            ->withoutGlobalScope(OpenPullRequest::OPEN)
            ->whereIn('repository_id', $tenant->repositories()->select('id'))
            ->get(['repository_id', 'author', 'author_id', 'author_nickname', 'reviewers']);
    }
}
