<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ReviewQuestions;

use App\Filament\App\Resources\ReviewQuestions\Pages\ListReviewQuestions;
use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Filament\CurrentTenant;
use App\Models\ReviewQuestion;
use App\Models\ReviewRun;
use App\Services\Currency;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Every question asked of the reviewer, on a pull request or on a review's page:
 * what is being answered now, what was, and what got no answer.
 */
class ReviewQuestionResource extends Resource
{
    protected static ?string $model = ReviewQuestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 12;

    /**
     * What a status reads as, and its tone.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function statuses(): array
    {
        return [
            ReviewQuestion::STATUS_ASKED => [__('Being answered'), 'neutral'],
            ReviewQuestion::STATUS_UNSURE => [__('Deciding whether it is for the reviewer'), 'neutral'],
            ReviewQuestion::STATUS_ANSWERED => [__('Answered'), 'approved'],
            ReviewQuestion::STATUS_FAILED => [__('No answer'), 'changes'],
            ReviewQuestion::STATUS_IGNORED => [__('Not for the reviewer'), 'low'],
        ];
    }

    public static function getModelLabel(): string
    {
        return __('question');
    }

    public static function getPluralModelLabel(): string
    {
        return __('questions');
    }

    public static function getNavigationLabel(): string
    {
        return __('Questions');
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = rescue(fn (): int => self::pending()->count(), 0, false);

        return $pending > 0 ? (string) $pending : null;
    }

    /**
     * The open tenant's questions that wait for an answer.
     *
     * @return Builder<ReviewQuestion>
     */
    public static function pending(): Builder
    {
        return CurrentTenant::get()->reviewQuestions()->getQuery()->whereIn('status', [ReviewQuestion::STATUS_ASKED, ReviewQuestion::STATUS_UNSURE]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Each row's host and the newest review of its pull request, in the list's own query.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('repository.integration')->addSelect([
                'latest_review' => ReviewRun::query()->select('uuid')
                    ->whereColumn('repository_id', 'review_questions.repository_id')
                    ->whereColumn('pull_request_id', 'review_questions.pull_request_id')
                    ->latest('started_at')
                    ->limit(1),
            ]))
            ->defaultSort('created_at', 'desc')
            // Looks again while an answer is on its way.
            ->poll(fn () => self::pending()->exists() ? '10s' : null)
            ->columns([
                TextColumn::make('question')
                    ->label(__('Question'))
                    ->wrap()
                    ->formatStateUsing(fn (string $state): string => Str::limit($state, 160))
                    ->description(fn (ReviewQuestion $record): string => $record->repository->slug.' #'.$record->pull_request_id
                        .($record->about !== null ? ' · '.__('under a finding') : '')
                        .' · '.($record->comment_id === null ? __('asked here') : __('on the pull request')))
                    ->searchable(),
                TextColumn::make('author')->label(__('Asked by'))->placeholder(__('Someone')),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->formatStateUsing(fn (string $state): HtmlString => ReviewRunResource::chip(...(self::statuses()[$state] ?? [ucfirst($state), 'neutral']))),
                TextColumn::make('created_at')->label(__('Asked'))->since()->sortable(),
                TextColumn::make('cost')->label(__('Cost'))->formatStateUsing(fn (mixed $state): string => Currency::show($state))->placeholder('—'),
            ])
            ->recordActions([
                Action::make('answer')
                    ->label(__('Answer'))
                    ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
                    ->color('gray')
                    ->visible(fn (ReviewQuestion $record): bool => $record->status === ReviewQuestion::STATUS_ANSWERED)
                    ->modalHeading(fn (ReviewQuestion $record): string => Str::limit($record->question, 120))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->schema(fn (ReviewQuestion $record): array => [
                        TextEntry::make('about')->label(__('Under the finding'))->state($record->about)->visible($record->about !== null)->markdown(),
                        TextEntry::make('answer')->hiddenLabel()->state($record->answer)->markdown(),
                    ]),
                Action::make('pullRequest')
                    ->label(__('Open on the pull request'))
                    ->tooltip(__('Open on the pull request'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->iconButton()
                    ->color('gray')
                    ->url(fn (ReviewQuestion $record): ?string => rescue(fn (): string => $record->repository->sourceControl()->pullRequestUrl($record->pull_request_id), null, false), shouldOpenInNewTab: true),
                Action::make('review')
                    ->label(__('Review'))
                    ->color('gray')
                    ->url(fn (ReviewQuestion $record): ?string => filled($record->getAttribute('latest_review')) ? ReviewRunResource::getUrl('view', ['record' => $record->getAttribute('latest_review')]) : null)
                    ->visible(fn (ReviewQuestion $record): bool => filled($record->getAttribute('latest_review'))),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviewQuestions::route('/'),
        ];
    }
}
