<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pull request, as the webhook last told of it: reviewed by itself or not.
 * Only the open ones, unless asked otherwise: one that was merged or declined is
 * kept with how and when, for the list's tabs, and gone after CLOSED_DAYS.
 *
 * A reviewer is kept as the host names them, with what they last did and when:
 * name, account_id, nickname, state (approved, changes_requested or null for not
 * yet) and at. A history entry is at, kind, who and text, oldest first.
 *
 * @property int $id
 * @property int $repository_id
 * @property int $pull_request_id
 * @property string|null $title
 * @property string|null $author
 * @property string|null $author_id
 * @property string|null $author_nickname
 * @property string|null $branch
 * @property string|null $destination_branch
 * @property string|null $commit
 * @property bool $draft
 * @property bool $from_fork
 * @property bool $awaiting_approval Not started by itself, for someone in the team to start
 * @property CarbonInterface|null $review_requested_at When someone last started its review by hand
 * @property string|null $closed_as merged or declined; null while it is open
 * @property CarbonInterface|null $closed_at
 * @property string|null $source_updated_on
 * @property CarbonInterface|null $opened_at
 * @property CarbonInterface|null $head_since When the current head was first seen
 * @property array<int, array{name: string|null, account_id: string|null, nickname: string|null, state: string|null, at: string|null}>|null $reviewers
 * @property array<int, array{at: string, kind: string, who: string|null, text: string}>|null $history
 * @property array{key: string, next_step: string, summary: string, written_at: string}|null $note The AI's words on whose turn it is, for the state its key names
 * @property bool|null $merge_conflicts whether it conflicts with its destination, as its host said when last asked
 * @property array<int, string>|null $conflicting_files
 * @property CarbonInterface|null $merge_checked_at
 * @property string|null $merge_checked_commit the head it was asked for
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Repository $repository
 */
class OpenPullRequest extends Model
{
    use Prunable;

    /** How many history entries are kept: the newest. */
    public const HISTORY_LIMIT = 40;

    public const MERGED = 'merged';

    public const DECLINED = 'declined';

    /** The global scope that leaves out the closed ones. */
    public const OPEN = 'open';

    /** How long one that was closed stays listed. */
    public const CLOSED_DAYS = 90;

    protected static function booted(): void
    {
        static::addGlobalScope(self::OPEN, fn (Builder $query): Builder => $query->whereNull($query->qualifyColumn('closed_as')));
    }

    /**
     * Those closed longer ago than CLOSED_DAYS.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return $this->newQueryWithoutScope(self::OPEN)->whereNotNull('closed_as')->where('closed_at', '<', now()->subDays(self::CLOSED_DAYS));
    }

    /**
     * Keeps a pull request as closed, merged or declined, rather than removing it:
     * also one already closed here, as declined, that a newer snapshot says was
     * merged since. A snapshot older than the one kept says nothing, and merged
     * is for good. One not listed here stays unlisted.
     *
     * @param  string|null  $updatedOn  when the host says it last changed, as source_updated_on keeps it
     */
    public static function close(int $repositoryId, int $pullRequestId, bool $merged, ?string $updatedOn = null): void
    {
        $record = static::withoutGlobalScope(self::OPEN)->where(['repository_id' => $repositoryId, 'pull_request_id' => $pullRequestId])->first();
        $closedAs = $merged ? self::MERGED : self::DECLINED;

        if ($record === null || $record->closed_as === self::MERGED || $record->closed_as === $closedAs) {
            return;
        }

        if ($updatedOn !== null && $record->source_updated_on !== null && $updatedOn < $record->source_updated_on) {
            return;
        }

        $record->closed_as = $closedAs;
        $record->closed_at = now();
        $record->awaiting_approval = false;
        $record->source_updated_on = $updatedOn ?? $record->source_updated_on;
        $record->record($closedAs, $merged ? 'Merged' : 'Declined');
        $record->save();
    }

    /**
     * Whether a delivery that says the pull request is open finds it closed here
     * for good: merged, or declined after the delivery's snapshot, which then
     * came late. A declined one the host changed since is open again, as
     * GitHub's can be, and listed as open.
     *
     * @param  array{repository_id: int, pull_request_id: int}  $key
     */
    public static function staysClosed(array $key, ?string $updatedOn): bool
    {
        $closed = static::withoutGlobalScope(self::OPEN)->where($key)->whereNotNull('closed_as')->first();

        if ($closed === null) {
            return false;
        }

        if ($closed->closed_as === self::MERGED || $updatedOn === null || ($closed->source_updated_on !== null && $updatedOn <= $closed->source_updated_on)) {
            return true;
        }

        $closed->closed_as = null;
        $closed->closed_at = null;
        $closed->record('reopened', 'Reopened');
        $closed->save();

        return false;
    }

    protected $fillable = [
        'repository_id',
        'pull_request_id',
    ];

    protected $attributes = [
        'draft' => false,
        'from_fork' => false,
        'awaiting_approval' => false,
    ];

    protected function casts(): array
    {
        return [
            'draft' => 'boolean',
            'from_fork' => 'boolean',
            'awaiting_approval' => 'boolean',
            'review_requested_at' => 'datetime',
            'opened_at' => 'datetime',
            'head_since' => 'datetime',
            'reviewers' => 'array',
            'merge_conflicts' => 'boolean',
            'conflicting_files' => 'array',
            'merge_checked_at' => 'datetime',
            'closed_at' => 'datetime',
            'history' => 'array',
            'note' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        // Also a removed one, whose history this is.
        return $this->belongsTo(Repository::class)->withTrashed();
    }

    /**
     * The newest review of this pull request, if there is one.
     */
    public function latestRun(): ?ReviewRun
    {
        return ReviewRun::query()
            ->where('repository_id', $this->repository_id)
            ->where('pull_request_id', $this->pull_request_id)
            ->latest('started_at')
            ->first();
    }

    /**
     * Adds to the history, keeping the newest entries.
     */
    public function record(string $kind, string $text, ?string $who = null, ?CarbonInterface $at = null): void
    {
        $history = $this->history ?? [];
        $history[] = ['at' => ($at ?? now())->toIso8601String(), 'kind' => $kind, 'who' => $who, 'text' => $text];

        $this->history = array_slice($history, -self::HISTORY_LIMIT);
    }

    /**
     * The author as a person on the host.
     *
     * @return array{name: string|null, account_id: string|null, nickname: string|null}
     */
    public function authorPerson(): array
    {
        return ['name' => $this->author, 'account_id' => $this->author_id, 'nickname' => $this->author_nickname];
    }
}
