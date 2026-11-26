<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\LocalTime;
use App\Filament\RunPresenter;
use App\Models\OpenPullRequest;
use App\Models\ReviewRun;
use App\Models\User;
use App\SourceControl\Reviewers;
use Illuminate\Support\Carbon;

/**
 * Whose turn an open pull request is, and what they have to do: read from its
 * reviewers, its newest commits and its AI review, by fixed rules. The AI's note
 * says it in more words, for the state the key names.
 */
final readonly class PullRequestTurn
{
    public const AUTHOR = 'author';

    public const REVIEWERS = 'reviewers';

    /** Someone in the team: to start the review of code this team does not trust. */
    public const TEAM = 'team';

    public const AI = 'ai';

    /** Nobody yet: no reviewer is asked. */
    public const NOBODY = 'nobody';

    /**
     * @param  string  $role  one of the constants
     * @param  array<int, array{name: string|null, account_id: string|null, nickname: string|null}>  $people  who is at turn, where it is someone
     * @param  string  $action  what they have to do, in a few words
     * @param  string  $reason  why, in a sentence
     * @param  string  $key  the state this was read from, which the AI's note is written for
     */
    public function __construct(
        public string $role,
        public array $people,
        public string $action,
        public string $reason,
        public string $key,
    ) {}

    /**
     * @param  bool  $lookUp  read its newest review when none is given; false when the caller already knows it has none
     */
    public static function of(OpenPullRequest $record, ?ReviewRun $run = null, bool $lookUp = true): self
    {
        $run ??= $lookUp ? $record->latestRun() : null;
        $author = $record->authorPerson();
        $authorName = $record->author ?? __('The author');
        $reviewers = collect($record->reviewers ?? []);
        $since = $record->head_since;
        $before = fn (array $reviewer): bool => $since !== null && $reviewer['at'] !== null && Carbon::parse($reviewer['at'])->lt($since);
        $names = fn ($people): string => self::names($people->pluck('name')->all());
        $current = $run !== null && $record->commit !== null && ReviewContext::isSameCommit($run->commit, $record->commit);

        $changes = $reviewers->where('state', Reviewers::CHANGES_REQUESTED);
        $staleChanges = $changes->filter($before);
        $approved = $reviewers->where('state', Reviewers::APPROVED);
        $staleApprovals = $approved->filter($before);
        $pending = $reviewers->whereNull('state');
        $manual = $current && $run->status === ReviewRun::STATUS_FINISHED && $run->needsManualReview()
            ? ' '.__('The AI review left points for a person to check.')
            : '';
        $openFindings = $run?->openFindings() ?? 0;
        $approvedAt = $staleApprovals->first()['at'] ?? null;

        [$rule, $role, $people, $action, $reason] = match (true) {
            $record->draft => ['draft', self::AUTHOR, [$author], __('Finish the draft'), __('It is a draft: :author marks it ready for review when it is.', ['author' => $authorName])],
            $record->awaiting_approval => ['approval', self::TEAM, [], __('Start the review'), __('Its code is from a fork or from an account this team does not trust: someone in the team starts the review here.')],
            $run !== null && RunPresenter::live($run) => ['ai', self::AI, [], __('AI review running'), __('The AI review of the newest commits is running.')],
            MergeState::current($record) === true => ['conflicts', self::AUTHOR, [$author], __('Resolve the merge conflicts'), $record->destination_branch === null
                ? __('It does not merge into its destination as it stands: :author merges that in first.', ['author' => $authorName])
                : __('It does not merge into :branch as it stands: :author merges that in first.', ['branch' => $record->destination_branch, 'author' => $authorName])],
            $staleChanges->isNotEmpty() => ['re-review-changes', self::REVIEWERS, $staleChanges->all(), __('Review again'), __(':names asked for changes, and there are new commits since.', ['names' => $names($staleChanges)])],
            $changes->isNotEmpty() => ['changes', self::AUTHOR, [$author], __('Address the requested changes'), __(':names asked for changes.', ['names' => $names($changes)])],
            $current && $run->status === ReviewRun::STATUS_FINISHED && strtoupper((string) $run->result) === 'FAILED' => ['ai-changes', self::AUTHOR, [$author], __('Address the AI review'), match (true) {
                $openFindings === 0 => __('The AI review asked for changes.'),
                $openFindings === 1 => __('The AI review asked for changes: :count open finding.', ['count' => $openFindings]),
                default => __('The AI review asked for changes: :count open findings.', ['count' => $openFindings]),
            }],
            $reviewers->isEmpty() => ['unassigned', self::NOBODY, [], __('Assign a reviewer'), __('Nobody is asked to review it yet.').$manual],
            $staleApprovals->isNotEmpty() => ['re-review', self::REVIEWERS, $staleApprovals->all(), __('Review again'), ($approvedAt === null
                ? __(':names approved earlier, before the newest commits.', ['names' => $names($staleApprovals)])
                : __(':names approved on :date, before the newest commits.', ['names' => $names($staleApprovals), 'date' => LocalTime::of(Carbon::parse($approvedAt))->translatedFormat('j M')])).$manual],
            $pending->isNotEmpty() => ['review', self::REVIEWERS, $pending->all(), __('Review'), __('Waiting for a review from :names.', ['names' => $names($pending)]).$manual],
            default => ['merge', self::AUTHOR, [$author], __('Merge'), __('Approved by :names: :author can merge.', ['names' => $names($approved), 'author' => $authorName]).$manual],
        };

        $people = array_values(array_map(fn (array $person): array => [
            'name' => $person['name'] ?? null,
            'account_id' => $person['account_id'] ?? null,
            'nickname' => $person['nickname'] ?? null,
        ], $people));

        $key = sha1((string) json_encode([
            $rule,
            $record->commit,
            $run?->getKey(),
            $run?->status,
            $reviewers->map(fn (array $reviewer): string => Reviewers::key($reviewer).':'.$reviewer['state'])->sort()->values()->all(),
        ]));

        return new self($role, $people, $action, $reason, $key);
    }

    /**
     * Whether it is this user's turn.
     */
    public function isFor(User $user): bool
    {
        return collect($this->people)->contains(fn (array $person): bool => $user->isOnHost($person));
    }

    /**
     * Who is at turn, in words.
     */
    public function who(): string
    {
        return match ($this->role) {
            self::TEAM => __('Someone in the team'),
            self::AI => __('The AI review'),
            self::NOBODY => __('Nobody yet'),
            default => self::names(array_column($this->people, 'name')) ?: __('Someone'),
        };
    }

    /**
     * The AI's note, when it was written for this state.
     *
     * @return array{next_step: string, summary: string}|null
     */
    public function note(OpenPullRequest $record): ?array
    {
        $note = $record->note;

        return $note !== null && $note['key'] === $this->key && filled($note['next_step'])
            ? ['next_step' => $note['next_step'], 'summary' => $note['summary']]
            : null;
    }

    /**
     * @param  array<int, string|null>  $names
     */
    public static function names(array $names): string
    {
        $names = array_values(array_filter($names, 'filled'));

        return match (count($names)) {
            0 => '',
            1 => (string) $names[0],
            default => __(':names and :last', ['names' => implode(', ', array_slice($names, 0, -1)), 'last' => end($names)]),
        };
    }
}
