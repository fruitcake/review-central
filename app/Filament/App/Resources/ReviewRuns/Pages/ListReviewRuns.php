<?php

namespace App\Filament\App\Resources\ReviewRuns\Pages;

use App\Exceptions\ReviewTrialRefused;
use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Filament\Concerns\RefreshesOnBroadcast;
use App\Filament\CurrentTenant;
use App\Models\ReviewRun;
use App\Runners\Runners;
use App\Runners\Security\SecurityReview;
use App\Services\ReviewRequest;
use App\Services\ReviewTrial;
use App\Services\TenantOverview;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ListReviewRuns extends ListRecords
{
    use RefreshesOnBroadcast;

    protected static string $resource = ReviewRunResource::class;

    protected static ?string $title = 'Reviews';

    public function getSubheading(): string|Htmlable|null
    {
        $runs = CurrentTenant::get()->reviewRuns()->whereIn('status', TenantOverview::REVIEWS);
        $recent = (clone $runs)->where('started_at', '>=', now()->subDays(30))->count();
        $running = (clone $runs)->where('status', ReviewRun::STATUS_RUNNING)->count();
        $queued = CurrentTenant::get()->reviewRuns()->where('status', ReviewRun::STATUS_QUEUED)->count();

        return "{$recent} ".($recent === 1 ? 'review' : 'reviews')." in the last 30 days · {$running} running now".($queued === 0 ? '' : " · {$queued} queued");
    }

    /**
     * The views of the list someone opens it for: what is waiting for them, what
     * is busy, and what went wrong.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $count = fn (callable $scope): int => $scope(CurrentTenant::get()->reviewRuns()->getQuery())->count();

        $views = [
            'all' => ['All', fn (Builder $query): Builder => $query->whereIn('status', [...TenantOverview::REVIEWS, ReviewRun::STATUS_QUEUED])],
            'action' => ['Needs action', fn (Builder $query): Builder => $query->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereIn('status', [ReviewRun::STATUS_FAILED, ReviewRun::STATUS_TIMEOUT])->whereNull('dismissed_at'))
                ->orWhere(fn (Builder $query) => $query->where('status', ReviewRun::STATUS_FINISHED)->where('result', 'FAILED')))],
            'running' => ['Queued or running', fn (Builder $query): Builder => $query->whereIn('status', [ReviewRun::STATUS_QUEUED, ReviewRun::STATUS_RUNNING])],
            // What someone set aside is still under All.
            'failed' => ['Did not finish', fn (Builder $query): Builder => $query->whereIn('status', [ReviewRun::STATUS_FAILED, ReviewRun::STATUS_TIMEOUT])->whereNull('dismissed_at')],
            'other' => ['Skipped or waiting', fn (Builder $query): Builder => $query->whereNotIn('status', [...TenantOverview::REVIEWS, ReviewRun::STATUS_QUEUED])->where('custom', false)],
            // Kept off the pull request, so they are nowhere else.
            'trials' => ['Trials', fn (Builder $query): Builder => $query->where('custom', true)->where('kind', '!=', SecurityReview::KIND)],
            'security' => ['Security', fn (Builder $query): Builder => $query->where('kind', SecurityReview::KIND)],
        ];

        return array_map(fn (array $view): Tab => Tab::make($view[0])
            ->badge($count($view[1]))
            ->modifyQueryUsing($view[1]), $views);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('review')
                ->label('Review a pull request')
                ->icon(Heroicon::OutlinedPlay)
                ->modalDescription('Starts a review of the open pull request on a branch, as a /review comment on it would. The review is posted on the pull request, and costs what a review costs.')
                ->schema([
                    Select::make('repository_id')
                        ->label('Repository')
                        ->options(fn (): array => CurrentTenant::get()->repositories()->pluck('slug', 'id')->all())
                        ->required(),
                    TextInput::make('branch')->required()->maxLength(255)->helperText('The branch the pull request comes from. For one from a fork on GitHub: pull/<number>/head.'),
                ])
                ->action(function (array $data, ReviewRequest $reviews): void {
                    $repository = CurrentTenant::get()->repositories()->whereKey($data['repository_id'])->firstOrFail();

                    try {
                        $reviews->start($repository, $data['branch'], strict: true);

                        Notification::make()->title('The review is started')->body("{$repository->slug} · {$data['branch']}. It is in the list as queued until a worker begins it.")->success()->send();
                    } catch (Throwable $exception) {
                        Notification::make()->title('The review could not be started')->body($exception->getMessage())->danger()->send();
                    }
                }),
            Action::make('security')
                ->label('Security review')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('gray')
                ->visible(fn (Runners $runners): bool => $runners->containerFor()->available())
                ->modalDescription('Reviews a whole branch for security problems, as it stands: no pull request, and nothing is posted. The report appears here, under Security. It reads far more than a pull request\'s review, so it takes longer and costs more.')
                ->schema([
                    Select::make('repository_id')
                        ->label('Repository')
                        ->options(fn (): array => CurrentTenant::get()->repositories()->pluck('slug', 'id')->all())
                        ->required(),
                    TextInput::make('branch')->required()->maxLength(255)->regex('/^[\\w.\\/-]+$/')->placeholder('main')->helperText('The branch to review, such as the default branch.'),
                ])
                ->action(function (array $data, Runners $runners): void {
                    $repository = CurrentTenant::get()->repositories()->whereKey($data['repository_id'])->firstOrFail();

                    // Where the repository's reviewers run, or the platform's for one that reviews from the diff.
                    $runners->containerFor($repository->runnerName())->startSecurityReview($repository, $data['branch']);

                    Notification::make()->title('The security review is started')->body("{$repository->slug} · {$data['branch']}. It shows under Security once a worker has cloned the branch.")->success()->send();
                }),
            Action::make('try')
                ->label('Try without posting')
                ->icon(Heroicon::OutlinedBeaker)
                ->color('gray')
                ->modalDescription('Reviews a pull request without posting anything on it, to compare reviewers, models or instructions on the same work. It may be merged already, and its repository need not be set up here. The result appears under Trials.')
                ->schema([
                    TextInput::make('url')
                        ->label('Pull request')
                        ->url()
                        ->required()
                        ->maxLength(500)
                        ->placeholder('https://bitbucket.org/workspace/repository/pull-requests/12'),
                    ...ReviewRunResource::trialFields(),
                ])
                ->action(function (array $data, ReviewTrial $trials): void {
                    try {
                        $repository = $trials->start(CurrentTenant::get(), $data['url'], ReviewRunResource::trialOptions($data));

                        Notification::make()->title('The trial is started')->body("{$repository->fullName()}. It shows under Trials once the reviewer has begun.")->success()->send();
                    } catch (ReviewTrialRefused $exception) {
                        Notification::make()->title('This pull request cannot be tried')->body($exception->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }
}
