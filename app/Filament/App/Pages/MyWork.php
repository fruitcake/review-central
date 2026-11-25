<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\OpenFindings\OpenFindingResource;
use App\Filament\App\Resources\PullRequests\Pages\ListPullRequests;
use App\Filament\App\Resources\PullRequests\PullRequestResource;
use App\Filament\App\Resources\Repositories\RepositoryResource;
use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Filament\Concerns\RefreshesOnBroadcast;
use App\Filament\CurrentTenant;
use App\Models\OpenFinding;
use App\Models\OpenPullRequest;
use App\Models\ReviewRun;
use App\Models\User;
use App\Services\RelevantRepositories;
use App\Services\TenantOverview;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * What waits for the signed-in user: the pull requests whose turn is theirs,
 * those they opened, and the repositories they work on with their latest
 * reviews. The team's figures are under Overview.
 */
class MyWork extends Page
{
    use RefreshesOnBroadcast;

    /** How many of the latest reviews of their repositories it lists. */
    public const REVIEWS = 8;

    protected static ?string $title = 'My work';

    protected static ?string $slug = 'my-work';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.app.my-work';

    public function getSubheading(): string|Htmlable|null
    {
        return 'What waits for you in '.CurrentTenant::get()->name.'. Your pull requests and reviews are found by the accounts on your profile.';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();
        $tenant = CurrentTenant::get();

        if (! $user instanceof User) {
            return ['turn' => collect(), 'opened' => collect(), 'repositories' => collect(), 'reviews' => collect(), 'targets' => $this->targets()];
        }

        $relevant = app(RelevantRepositories::class);
        $repositoryIds = $relevant->for($user, $tenant);

        return [
            'turn' => OpenPullRequest::query()->whereKey(PullRequestResource::withUser($user))->with('repository.tenant')->latest('updated_at')->get(),
            'opened' => $relevant->openedBy($user, $tenant),
            'repositories' => $tenant->repositories()
                ->whereKey($repositoryIds)
                ->withCount([
                    'openPullRequests as open_count' => fn ($query) => $query->where('draft', false),
                    'openFindings as findings_count' => fn ($query) => $query->whereIn('status', OpenFinding::ACTIVE),
                ])
                ->orderBy('slug')
                ->get(),
            'reviews' => ReviewRun::query()
                ->where('tenant_id', $tenant->getKey())
                ->whereIn('repository_id', $repositoryIds)
                ->whereIn('status', TenantOverview::REVIEWS)
                ->latest('started_at')
                ->limit(self::REVIEWS)
                ->get(),
            'targets' => $this->targets(),
        ];
    }

    /**
     * Where its cards lead: the lists, on the tab of the user's repositories.
     *
     * @return array<string, string>
     */
    protected function targets(): array
    {
        // The same key on every list that has the tab.
        $mine = ['tab' => ListPullRequests::MY_REPOSITORIES];

        return [
            'turn' => PullRequestResource::getUrl('index', ['tab' => 'mine']),
            'pullRequests' => PullRequestResource::getUrl('index', $mine),
            'repositories' => RepositoryResource::getUrl('index', $mine),
            'findings' => OpenFindingResource::getUrl('index', $mine),
            'reviews' => ReviewRunResource::getUrl('index', $mine),
        ];
    }
}
