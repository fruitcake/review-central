<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\PullRequests;

use App\Filament\App\Resources\PullRequests\Pages\ListPullRequests;
use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Filament\CurrentTenant;
use App\Filament\RunPresenter;
use App\Models\OpenPullRequest;
use App\Models\ReviewRun;
use App\Services\ReviewRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
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
            // Each row's newest review, in the list's own query.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('repository.integration')->addSelect([
                'latest_review' => ReviewRun::query()->select('uuid')
                    ->whereColumn('repository_id', 'open_pull_requests.repository_id')
                    ->whereColumn('pull_request_id', 'open_pull_requests.pull_request_id')
                    ->latest('started_at')
                    ->limit(1),
            ]))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Pull request')
                    ->wrap()
                    ->placeholder('Untitled')
                    ->description(fn (OpenPullRequest $record): string => $record->repository->slug.' #'.$record->pull_request_id.($record->branch === null ? '' : ' · '.$record->branch))
                    ->searchable(['title', 'branch']),
                TextColumn::make('author')
                    ->label('By')
                    ->placeholder('Someone')
                    ->description(fn (OpenPullRequest $record): ?string => $record->repository->tenant->trusts($record->author_id, $record->author_nickname) ? 'Trusted' : null)
                    ->searchable(['author', 'author_nickname']),
                TextColumn::make('state')
                    ->label('Review')
                    ->state(fn (OpenPullRequest $record): HtmlString => ReviewRunResource::chip(...self::standing($record))),
                TextColumn::make('updated_at')->label('Last change')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('start')
                    ->label(fn (OpenPullRequest $record): string => $record->awaiting_approval ? 'Approve and review' : 'Review')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color(fn (OpenPullRequest $record): string => $record->awaiting_approval ? 'primary' : 'gray')
                    ->visible(fn (OpenPullRequest $record): bool => $record->branch !== null)
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
     * Where the pull request's review stands, in words and a tone.
     *
     * @return array{0: string, 1: string}
     */
    public static function standing(OpenPullRequest $record): array
    {
        $run = $record->latestRun();
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
