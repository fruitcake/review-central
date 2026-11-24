<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\PullRequests;

use App\Filament\App\Resources\PullRequests\Pages\ListPullRequests;
use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Filament\CurrentTenant;
use App\Filament\RunPresenter;
use App\Models\OpenPullRequest;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\User;
use App\Services\Currency;
use App\Services\MergeState;
use App\Services\PullRequestTurn;
use App\Services\ReviewRequest;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every open pull request of the team's repositories, as their webhooks told of
 * them: reviewed by itself or not. One that is not, a fork's or an untrusted
 * account's, waits here for someone to start its review.
 */
class PullRequestResource extends Resource
{
    protected static ?string $model = OpenPullRequest::class;

    protected static ?string $navigationLabel = 'Pull requests';

    protected static ?string $modelLabel = 'pull request';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 11;

    // Through its repository: an open pull request has no tenant of its own.
    protected static bool $isScopedToTenant = false;

    /**
     * Runs that were never a review of their own: skipped, started again, or not begun.
     */
    private const array NOT_A_REVIEW = [ReviewRun::STATUS_SKIPPED, ReviewRun::STATUS_RESTARTED, ReviewRun::STATUS_PENDING];

    /**
     * What whose turn it is and where the review stands are read from of a run:
     * see PullRequestTurn::of(), standing() and RunPresenter::outcome().
     */
    private const array TURN_COLUMNS = ['id', 'repository_id', 'pull_request_id', 'kind', 'status', 'result', 'commit', 'findings', 'manual_review', 'started_at'];

    /**
     * The relation a row of the list carries its newest run in, null where it has none.
     */
    private const string NEWEST_RUN = 'newestRun';

    /**
     * The runs of the list's row, for a subquery of the list.
     *
     * @return Builder<ReviewRun>
     */
    private static function runsOf(): Builder
    {
        return ReviewRun::query()
            ->whereColumn('repository_id', 'open_pull_requests.repository_id')
            ->whereColumn('pull_request_id', 'open_pull_requests.pull_request_id');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('repository', fn (Builder $query): Builder => $query->where('tenant_id', CurrentTenant::get()->getKey()));
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = rescue(fn (): int => self::getEloquentQuery()->where('awaiting_approval', true)->count(), 0, false);

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Waiting for someone to start the review';
    }

    public static function table(Table $table): Table
    {
        return $table
            // Each row's newest review, how many it had and what they cost, in the list's own query.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('repository.integration')->addSelect([
                'latest_review' => self::runsOf()->select('uuid')->latest('started_at')->limit(1),
                'reviews_count' => self::runsOf()->whereNotIn('status', self::NOT_A_REVIEW)->selectRaw('count(*)'),
                'reviews_cost' => self::runsOf()->selectRaw('sum(cost)'),
            ])->afterQuery(function (SupportCollection $records): void {
                // The page's rows, not a plucked column: their newest runs in one query rather than two per row.
                $rows = $records->filter(fn (mixed $record): bool => $record instanceof OpenPullRequest);
                $runs = self::latestRuns(array_values(array_map(fn (OpenPullRequest $record): int => $record->id, $rows->all())));
                $rows->each(fn (OpenPullRequest $record): OpenPullRequest => $record->setRelation(self::NEWEST_RUN, $runs->get(self::pairOf($record))));
            }))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Pull request')
                    ->wrap()
                    ->placeholder('Untitled')
                    ->description(fn (OpenPullRequest $record): string => $record->repository->slug.' #'.$record->pull_request_id.($record->branch === null ? '' : ' · '.$record->branch).(MergeState::current($record) === true ? ' · Merge conflicts' : ''))
                    ->searchable(['title', 'branch']),
                TextColumn::make('author')
                    ->label('By')
                    ->placeholder('Someone')
                    ->description(fn (OpenPullRequest $record): ?string => $record->repository->tenant->trusts($record->author_id, $record->author_nickname) ? 'Trusted' : null)
                    ->searchable(['author', 'author_nickname']),
                TextColumn::make('turn')
                    ->label('Whose turn')
                    ->wrap()
                    ->state(fn (OpenPullRequest $record): HtmlString => self::turnCell($record)),
                TextColumn::make('state')
                    ->label('AI review')
                    ->state(fn (OpenPullRequest $record): HtmlString => ReviewRunResource::chip(...self::standing($record))),
                TextColumn::make('reviews_count')->label('Reviews')->numeric(),
                TextColumn::make('reviews_cost')->label('Cost')->formatStateUsing(fn (mixed $state): string => Currency::show($state))->placeholder('—'),
                TextColumn::make('updated_at')->label('Last change')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('repository_id')
                    ->label('Repository')
                    ->options(fn (): array => CurrentTenant::get()->repositories()->get()->mapWithKeys(fn (Repository $repository): array => [$repository->getKey() => $repository->fullName()])->all()),
            ])
            ->recordAction('details')
            ->recordActions([
                Action::make('details')
                    ->label('Details')
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->iconButton()
                    ->tooltip('Whose turn, and what happened so far')
                    ->color('gray')
                    ->slideOver()
                    ->modalHeading(fn (OpenPullRequest $record): string => $record->title ?? 'Untitled')
                    ->modalDescription(fn (OpenPullRequest $record): string => $record->repository->slug.' #'.$record->pull_request_id)
                    ->modalContent(fn (OpenPullRequest $record): View => view('filament.app.pull-request', self::details($record)))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('start')
                    ->label(fn (OpenPullRequest $record): string => $record->awaiting_approval ? 'Approve and review' : 'Review')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color(fn (OpenPullRequest $record): string => $record->awaiting_approval ? 'primary' : 'gray')
                    // Not a closed one, whose branch may be another pull request's by now; nor a fork's
                    // whose branch its host keeps nowhere here, as on Bitbucket: it can only be tried from the diff.
                    // Checked again when it is pressed, as Filament runs no hidden action.
                    ->visible(fn (OpenPullRequest $record): bool => $record->closed_as === null && $record->branch !== null && ReviewRequest::reviewable(['from_fork' => $record->from_fork, 'source' => ['branch' => ['name' => $record->branch]]]))
                    ->requiresConfirmation()
                    ->modalHeading(fn (OpenPullRequest $record): string => "Review #{$record->pull_request_id}?")
                    ->modalDescription(fn (OpenPullRequest $record): string => ($record->from_fork || ! $record->repository->tenant->trusts($record->author_id, $record->author_nickname)
                        ? 'Its code is not from an account this team trusts: the reviewer reads it in the sandbox, with this team\'s AI key and on its budget. '
                        : '').'The review is posted on the pull request, and costs what a review costs.')
                    ->action(function (OpenPullRequest $record, ReviewRequest $reviews): void {
                        try {
                            $reviews->start($record->repository, $record->branch, strict: true);

                            Notification::make()->title('The review is started')->body("{$record->repository->slug} #{$record->pull_request_id}. It is under Reviews as queued until a worker begins it.")->success()->send();
                        } catch (Throwable $exception) {
                            Notification::make()->title('The review could not be started')->body($exception->getMessage())->danger()->send();
                        }
                    }),
                Action::make('pullRequest')
                    ->label('Open on the pull request')
                    ->tooltip('Open on the pull request')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->iconButton()
                    ->color('gray')
                    ->url(fn (OpenPullRequest $record): ?string => rescue(fn (): string => $record->repository->sourceControl()->pullRequestUrl($record->pull_request_id), null, false), shouldOpenInNewTab: true),
                Action::make('review')
                    ->label('Last review')
                    ->color('gray')
                    ->url(fn (OpenPullRequest $record): ?string => filled($record->getAttribute('latest_review')) ? ReviewRunResource::getUrl('view', ['record' => $record->getAttribute('latest_review')]) : null)
                    ->visible(fn (OpenPullRequest $record): bool => filled($record->getAttribute('latest_review'))),
            ]);
    }

    /**
     * Who is at turn, what they have to do, and why: the AI's words where they are
     * written for this state, the fixed rules' where not.
     */
    public static function turnCell(OpenPullRequest $record): HtmlString
    {
        if ($record->closed_as !== null) {
            return new HtmlString('<span class="rc-muted">'.e(self::closedText($record)).'</span>');
        }

        $turn = PullRequestTurn::of($record, self::newestRun($record), lookUp: false);
        $note = $turn->note($record);
        $user = auth()->user();
        $mine = $user instanceof User && $turn->isFor($user);

        return new HtmlString(
            '<div class="rc-turn">'
            .'<span class="rc-turn-who">'.e($turn->who()).($mine ? ' <span class="rc-chip rc-chip-primary">You</span>' : '').'</span>'
            .'<span class="rc-muted">'.e($note === null ? "{$turn->action}: {$turn->reason}" : Str::limit($note['next_step'], 220)).'</span>'
            .'</div>'
        );
    }

    /**
     * How and when a closed pull request was closed: "Merged 3 days ago".
     */
    public static function closedText(OpenPullRequest $record): string
    {
        return ($record->closed_as === OpenPullRequest::MERGED ? 'Merged' : 'Declined').($record->closed_at === null ? '' : ' '.$record->closed_at->diffForHumans());
    }

    /**
     * What the details show of a pull request.
     *
     * @return array<string, mixed>
     */
    public static function details(OpenPullRequest $record): array
    {
        $run = $record->latestRun();
        $turn = PullRequestTurn::of($record, $run);
        $user = auth()->user();

        $runs = ReviewRun::query()
            ->where('repository_id', $record->repository_id)
            ->where('pull_request_id', $record->pull_request_id)
            ->whereNotIn('status', self::NOT_A_REVIEW)
            ->oldest('started_at')
            ->get();

        // What happened, oldest first: the pull request's own history and its AI reviews.
        $history = collect($record->history ?? [])
            ->map(fn (array $entry): array => ['at' => Carbon::parse($entry['at']), 'text' => $entry['text'], 'kind' => $entry['kind'], 'url' => null, 'tone' => null])
            ->merge($runs->map(fn (ReviewRun $sibling): array => [
                'at' => $sibling->finished_at ?? $sibling->started_at,
                'text' => 'AI review of '.substr($sibling->commit, 0, 7).': '.Str::lower(RunPresenter::outcome($sibling)['label']),
                'kind' => 'ai',
                'url' => ReviewRunResource::getUrl('view', ['record' => $sibling]),
                'tone' => RunPresenter::outcome($sibling)['tone'],
            ]))
            ->sortBy(fn (array $entry): int => $entry['at']->getTimestamp())
            ->values();

        return [
            'record' => $record,
            'turn' => $turn,
            'note' => $turn->note($record),
            'mine' => $record->closed_as === null && $user instanceof User && $turn->isFor($user),
            'closed' => $record->closed_as === null ? null : self::closedText($record),
            'run' => $run,
            'standing' => self::standing($record),
            'history' => $history,
            // As the newest review read it: the ticket is read when a review runs, not here.
            'ticket' => $runs->reverse()->map(fn (ReviewRun $sibling): mixed => $sibling->context['ticket'] ?? null)->first(fn (mixed $ticket): bool => is_array($ticket) && filled($ticket['reference'] ?? null)),
            'url' => rescue(fn (): string => $record->repository->sourceControl()->pullRequestUrl($record->pull_request_id), null, false),
        ];
    }

    /**
     * The pull requests whose turn is this user's.
     *
     * @return array<int, int>
     */
    public static function withUser(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $records = self::getEloquentQuery()->with('repository.tenant')->get();
        $runs = self::latestRuns($records->modelKeys());

        return $records
            ->filter(fn (Model $record): bool => $record instanceof OpenPullRequest
                && PullRequestTurn::of($record, $runs->get(self::pairOf($record)), lookUp: false)->isFor($user))
            ->modelKeys();
    }

    /**
     * The newest run of each of these pull requests, by "repository id:pull request id",
     * with only the columns of TURN_COLUMNS: one query, for these pull requests only.
     *
     * @param  array<int, int|string>  $ids  of the open pull requests
     * @return SupportCollection<string, ReviewRun>
     */
    private static function latestRuns(array $ids): SupportCollection
    {
        if ($ids === []) {
            return collect();
        }

        $samePullRequest = fn (string $table): Closure => fn (JoinClause $join): JoinClause => $join
            ->on("{$table}.repository_id", '=', 'review_runs.repository_id')
            ->on("{$table}.pull_request_id", '=', 'review_runs.pull_request_id');

        $newest = ReviewRun::query()
            ->join('open_pull_requests', $samePullRequest('open_pull_requests'))
            ->whereIntegerInRaw('open_pull_requests.id', $ids)
            ->groupBy('review_runs.repository_id', 'review_runs.pull_request_id')
            ->select(['review_runs.repository_id', 'review_runs.pull_request_id'])
            ->selectRaw('max(review_runs.started_at) as newest_started_at');

        return ReviewRun::query()
            ->select(array_map(fn (string $column): string => "review_runs.{$column}", self::TURN_COLUMNS))
            ->joinSub($newest, 'newest', fn (JoinClause $join): JoinClause => $samePullRequest('newest')($join)->on('newest.newest_started_at', '=', 'review_runs.started_at'))
            // Of two that started in the same second, keyBy() keeps the one created last.
            ->orderBy('review_runs.id')
            ->get()
            ->keyBy(fn (ReviewRun $run): string => $run->repository_id.':'.$run->pull_request_id);
    }

    private static function pairOf(OpenPullRequest $record): string
    {
        return $record->repository_id.':'.$record->pull_request_id;
    }

    /**
     * The newest run of the pull request: the one the list fetched with its row, or read now.
     */
    private static function newestRun(OpenPullRequest $record): ?ReviewRun
    {
        if ($record->relationLoaded(self::NEWEST_RUN)) {
            $run = $record->getRelation(self::NEWEST_RUN);

            return $run instanceof ReviewRun ? $run : null;
        }

        return $record->latestRun();
    }

    /**
     * Where the pull request's review stands, in words and a tone.
     *
     * @return array{0: string, 1: string}
     */
    public static function standing(OpenPullRequest $record): array
    {
        $run = self::newestRun($record);
        $current = $run !== null && $record->commit !== null && str_starts_with($record->commit, substr($run->commit, 0, 7));

        return match (true) {
            $record->awaiting_approval => ['Waiting for approval', 'manual'],
            $run !== null && RunPresenter::live($run) => ['Reviewing…', 'running'],
            $record->draft => ['Draft', 'neutral'],
            ! $record->repository->reviews_enabled && $run === null => ['Not reviewed by itself', 'neutral'],
            $run === null => ['Not reviewed', 'neutral'],
            ! $current => ['New commits since the review', 'neutral'],
            default => [RunPresenter::outcome($run)['label'], RunPresenter::outcome($run)['tone']],
        };
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPullRequests::route('/'),
        ];
    }
}
