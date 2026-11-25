<?php

namespace App\Filament\App\Resources\ReviewRuns\Pages;

use App\Enums\FindingVerdictKind;
use App\Enums\FindingVerdictReason;
use App\Enums\ReviewEffort;
use App\Enums\ReviewRuleKind;
use App\Events\ReviewLogGrew;
use App\Exceptions\ReviewTrialRefused;
use App\Filament\App\Resources\ReviewComparisons\ReviewComparisonResource;
use App\Filament\App\Resources\ReviewRules\ReviewRuleResource;
use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Filament\Concerns\RefreshesOnBroadcast;
use App\Filament\CurrentTenant;
use App\Filament\RunPresenter;
use App\Filament\TenantRole;
use App\Models\FindingVerdict;
use App\Models\ReviewClassification;
use App\Models\ReviewComparison;
use App\Models\ReviewQuestion;
use App\Models\ReviewRun;
use App\Models\User;
use App\Runners\Container\RunLog;
use App\Runners\DiffRunner;
use App\Runners\Runners;
use App\Services\Broadcasts;
use App\Services\FindingFeedback;
use App\Services\ReviewComparisons;
use App\Services\ReviewCredentials;
use App\Services\ReviewPublisher;
use App\Services\ReviewQuestions;
use App\Services\ReviewRequest;
use App\Services\ReviewStops;
use App\Services\ReviewTrial;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Throwable;

/**
 * @property ReviewRun $record
 */
class ViewReviewRun extends ViewRecord
{
    use RefreshesOnBroadcast { broadcastsToRefreshOn as tenantBroadcasts; }

    protected static string $resource = ReviewRunResource::class;

    /**
     * The tenant's reviews, for how this one ended, and this review's own log.
     *
     * @return array<int, string>
     */
    protected function broadcastsToRefreshOn(): array
    {
        $run = $this->record;

        return [
            ...$this->tenantBroadcasts(),
            ...($run->build_number === null ? [] : [Broadcasts::listener(ReviewLogGrew::channel((int) $run->repository_id, (int) $run->build_number), ReviewLogGrew::NAME)]),
        ];
    }

    protected string $view = 'filament.app.review';

    public function getTitle(): string|Htmlable
    {
        return RunPresenter::title($this->record);
    }

    public function getBreadcrumb(): string
    {
        return $this->record->slug.($this->record->pull_request_id ? " #{$this->record->pull_request_id}" : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            ReviewRunResource::dismissAction(),
            Action::make('stop')
                ->label('Stop')
                ->icon(Heroicon::OutlinedStop)
                ->color('danger')
                ->visible(fn (): bool => ReviewStops::stoppable($this->record))
                ->requiresConfirmation()
                ->modalHeading('Stop this review')
                ->modalDescription(ReviewRunResource::STOP_DESCRIPTION)
                ->modalSubmitActionLabel('Stop')
                ->action(function (ReviewStops $stops): void {
                    ReviewRunResource::stopped($this->record, $stops);
                    $this->record->refresh();
                }),
            Action::make('compare')
                ->label('Compare with')
                ->icon(Heroicon::OutlinedScale)
                ->color('gray')
                ->visible(fn (): bool => TenantRole::isAdmin() && $this->record->pull_request_id !== null)
                ->modalHeading('Compare this review with other setups')
                ->modalDescription('Each row reviews this pull request as a trial: recorded here and kept off the pull request. They show side by side with this review, and one model can then judge their findings together. Each costs what a review costs.')
                ->schema([
                    Toggle::make('at_run_commit')
                        ->label(fn (): string => 'At this review\'s commit, '.substr((string) $this->record->commit, 0, 12))
                        ->default(true)
                        ->helperText('Each setup reviews the code this review read, even when the pull request has moved on since. Off, each reviews the pull request as it is now. An earlier commit is always reviewed as though nobody had, and not from the diff alone.'),
                    ReviewRunResource::ignoreExistingField(),
                    Repeater::make('rows')
                        ->label('Setups')
                        ->schema(ReviewRunResource::trialFields(withExisting: false))
                        ->columns(2)
                        ->default(fn (): array => [[], []])
                        ->minItems(1)
                        ->maxItems(ReviewComparison::MAX_ROWS)
                        ->addActionLabel('Add a setup'),
                ])
                ->modalSubmitActionLabel('Start')
                ->action(function (array $data, ReviewComparisons $comparisons): void {
                    $rows = array_map(ReviewRunResource::trialOptions(...), array_values($data['rows'] ?? []));
                    $user = auth()->user();
                    $comparison = $comparisons->start($this->record, $rows, (bool) ($data['ignore_existing'] ?? true), $user instanceof User ? $user : null, (bool) ($data['at_run_commit'] ?? false));

                    $this->redirect(ReviewComparisonResource::getUrl('view', ['record' => $comparison]));
                }),
            Action::make('rerun')
                ->label('Re-run')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->modalHeading('Re-run this review')
                ->modalDescription(fn (): string => $this->record->pull_request_id === null
                    ? 'Starts a new review of this branch as it is now, and costs what a review costs.'
                    : 'Reviews this pull request again, as it is now, with the setup below: this run\'s, to change what you want to compare. Kept here and off the pull request, unless you post it.')
                ->fillForm(fn (Runners $runners, ReviewCredentials $credentials): array => $this->rerunState($runners, $credentials))
                ->schema(fn (): array => $this->record->pull_request_id === null ? [] : [
                    Toggle::make('post')
                        ->label('Post it on the pull request')
                        ->default(false)
                        ->live()
                        ->helperText('Off, it is a trial: recorded here, under Trials, and nothing is said on the pull request. On, it is the pull request\'s own next review, with the repository\'s settings: comments, findings and the build status.'),
                    Group::make(ReviewRunResource::trialFields())
                        ->visible(fn (Get $get): bool => ! $get('post')),
                ])
                ->modalSubmitActionLabel('Run')
                ->action(function (array $data, ReviewRequest $reviews, ReviewTrial $trials): void {
                    $run = $this->record;

                    try {
                        if ($run->pull_request_id === null || ($data['post'] ?? false)) {
                            ReviewRunResource::started($reviews->start($run->repository, $run->branch, strict: true));

                            return;
                        }

                        $trials->start(CurrentTenant::get(), $run->repository->sourceControl()->pullRequestUrl($run->pull_request_id), ReviewRunResource::trialOptions($data));

                        Notification::make()->title('The re-run is started')->body('It shows under Trials, and here as one of this pull request\'s runs.')->success()->send();
                    } catch (ReviewTrialRefused $exception) {
                        Notification::make()->title('This pull request cannot be re-run')->body($exception->getMessage())->danger()->persistent()->send();
                    } catch (Throwable $exception) {
                        Notification::make()->title('The review could not be started')->body($exception->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    /**
     * The re-run's form, set up as this run was: its runner, reviewer, model and
     * effort, where they can still be chosen.
     *
     * @return array<string, mixed>
     */
    protected function rerunState(Runners $runners, ReviewCredentials $credentials): array
    {
        $run = $this->record;
        $runner = array_key_exists((string) $run->runner, $runners->options()) ? $run->runner : null;
        $reviewer = $runner !== DiffRunner::NAME && array_key_exists((string) $run->agent, $credentials->trialReviewers(CurrentTenant::get())) ? $run->agent : null;

        return [
            'post' => false,
            'ignore_existing' => true,
            'runner' => $runner,
            'provider' => $reviewer,
            'model' => $run->model,
            'effort' => in_array($run->effort, array_column(ReviewEffort::cases(), 'value'), true) ? $run->effort : null,
            // The parts it ran with, so a re-run is the same review again.
            'modules' => array_map(fn (bool $on): string => $on ? 'true' : 'false', $run->modules ?? []),
        ];
    }

    /**
     * What the signed-in user thinks of a finding; the same answer twice takes it back.
     */
    public function judge(string $finding, string $verdict, FindingFeedback $feedback): void
    {
        $kind = FindingVerdictKind::tryFrom($verdict);
        $user = auth()->user();

        if ($kind === null || ! $user instanceof User) {
            return;
        }

        try {
            $feedback->give($this->record, $user, $finding, $kind);
        } catch (InvalidArgumentException) {
            // A finding this run does not have: nothing to store.
        }
    }

    /**
     * Why the signed-in user dismissed a finding; empty takes the reason back.
     */
    public function explain(string $finding, string $reason, FindingFeedback $feedback): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $feedback->explain($this->record, $user, $finding, FindingVerdictReason::tryFrom($reason));
        }
    }

    /**
     * Makes a rule of a finding the team judged not to be one, so the reviewer
     * stops raising it: "not a defect here", for this repository and the
     * finding's file, for an admin to widen before saving.
     */
    public function makeRuleAction(): Action
    {
        return Action::make('makeRule')
            ->label('Make it a rule')
            ->link()
            ->size('sm')
            ->visible(fn (): bool => TenantRole::isAdmin())
            ->modalHeading('Make it a rule')
            ->modalDescription('The reviewer reads the team\'s rules on every review. A rule saved as "Not a defect here" stops it raising this again.')
            ->fillForm(function (array $arguments): array {
                $finding = $this->finding((string) ($arguments['finding'] ?? ''));

                return [
                    'kind' => ReviewRuleKind::Convention->value,
                    'title' => $finding['title'] ?? '',
                    'body' => $finding === null ? null : trim('Raised as '.($finding['id'] ?? '?').' in the review of '.RunPresenter::title($this->record).' and judged not to be a defect.'),
                    'repository_id' => $this->record->repository_id,
                    'paths' => filled($finding['path'] ?? null) ? [(string) $finding['path']] : [],
                    'enabled' => true,
                ];
            })
            ->schema(ReviewRuleResource::fields())
            ->action(function (array $data, array $arguments): void {
                $finding = $this->finding((string) ($arguments['finding'] ?? ''));

                CurrentTenant::get()->reviewRules()->create($data + [
                    'source' => $finding === null ? null : "{$this->record->uuid}:{$finding['id']}",
                ]);

                Notification::make()->title('The rule is saved')->body('The next review reads it.')->success()->send();
            });
    }

    /**
     * One of this run's findings, by its id.
     *
     * @return array<string, mixed>|null
     */
    protected function finding(string $id): ?array
    {
        return collect($this->record->findings ?? [])->first(fn (array $finding): bool => $id !== '' && (string) ($finding['id'] ?? '') === $id);
    }

    /** A question for the reviewer, typed on the page. */
    public string $question = '';

    /**
     * Asks the reviewer about this pull request, answered on this page alone.
     */
    public function ask(ReviewQuestions $questions): void
    {
        $this->validate(['question' => ['required', 'string', 'max:4000']]);
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $asked = $questions->askInPanel($this->record, $user, $this->question);

        if (is_string($asked)) {
            Notification::make()->title('The reviewer cannot be asked here')->body($asked)->warning()->send();

            return;
        }

        $this->question = '';

        Notification::make()->title('Asked')->body('The answer appears here once the reviewer has looked, usually within a few minutes.')->success()->send();
    }

    /**
     * The signed-in user's own rating of the review, one per user.
     */
    public function rate(int $score): void
    {
        $user = auth()->user();

        if ($score < 1 || $score > 5 || ! $user instanceof User) {
            return;
        }

        $this->record->ratings()->updateOrCreate(['user_id' => $user->getKey()], ['score' => $score, 'name' => $user->name]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $run = $this->record;

        // Every review of the same pull request, oldest first: the story this run is a part of.
        $siblings = $run->pull_request_id === null
            ? collect([$run])
            : ReviewRun::query()
                ->where('repository_id', $run->repository_id)
                ->where('pull_request_id', $run->pull_request_id)
                ->orderBy('started_at')
                ->get();

        return [
            'run' => $run,
            'findings' => $this->findings($run),
            'siblings' => $siblings,
            // What the commit was classified as before its review, when it was.
            'classification' => ReviewClassification::query()->where('repository_id', $run->repository_id)->where('commit', $run->commit)->first(),
            'ratings' => $run->ratings()->latest()->get(),
            // What people asked the reviewer on this pull request, newest first.
            'questions' => $run->pull_request_id === null ? collect() : ReviewQuestion::query()
                ->where('repository_id', $run->repository_id)
                ->where('pull_request_id', $run->pull_request_id)
                // Not the replies that turned out to be for someone else.
                ->where('status', '!=', ReviewQuestion::STATUS_IGNORED)
                ->latest()
                ->limit(20)
                ->get(),
            'verdicts' => auth()->user() instanceof User ? app(FindingFeedback::class)->of($run, auth()->user()) : [],
            // A finding's file on the host, as it was at the commit this run reviewed;
            // the host's client made once for all of them, or none when it cannot be.
            'fileUrl' => (function () use ($run): Closure {
                $host = rescue(fn () => $run->repository->sourceControl(), null, false);

                return fn (string $path, mixed $line): ?string => $host?->fileUrl($run->commit, $path, is_numeric($line) ? (int) $line : null);
            })(),
            'reasons' => auth()->user() instanceof User ? app(FindingFeedback::class)->reasons($run, auth()->user()) : [],
            // Why people in the team dismissed each finding, notes given from an AI assistant included.
            'explanations' => FindingVerdict::query()->with('user')->where('review_run_id', $run->getKey())
                ->where(fn ($query) => $query->whereNotNull('reason')->orWhereNotNull('note'))
                ->get()
                ->groupBy('finding_id')
                ->map(fn ($verdicts): array => $verdicts->map->explanation()->all())
                ->all(),
            // Anyone in the team judging a finding not to be one is enough to make a rule of it.
            'dismissed' => FindingVerdict::query()
                ->where('review_run_id', $run->getKey())
                ->whereIn('verdict', [FindingVerdictKind::NotAProblem, FindingVerdictKind::Wrong])
                ->distinct()
                ->pluck('finding_id')
                ->all(),
            'ownScore' => $run->ratings()->where('user_id', auth()->id())->value('score'),
            // What a container review printed so far; null when there is no log of it.
            'log' => app(RunLog::class)->tail($run),
            'live' => RunPresenter::live($run),
            // With a broadcaster the page is told when to look. It still asks now and
            // then: a broadcast can be missed, and the last lines before a reviewer
            // goes quiet are not told of until it prints again.
            'poll' => Broadcasts::enabled() ? '10s' : '3s',
        ];
    }

    /**
     * The run's findings in the order someone reads them for: what holds the merge
     * first, then what else is open, then what is settled.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function findings(ReviewRun $run): array
    {
        // The threshold this run's result was worked out with, so the page and the
        // result never disagree about what holds the merge.
        $failOn = ReviewPublisher::failOnSpec($run->fail_on);

        $findings = array_map(fn (array $finding): array => $finding + [
            'blocks' => ReviewPublisher::blocks($finding, $failOn),
            'open' => in_array(strtolower((string) ($finding['status'] ?? 'new')), ['new', 'open'], true),
        ], array_values(array_filter($run->findings ?? [], is_array(...))));

        // Stable: within a group the reviewer's own order stands.
        return collect($findings)->sortBy(fn (array $finding): int => $finding['blocks'] ? 0 : ($finding['open'] ? 1 : 2))->values()->all();
    }
}
