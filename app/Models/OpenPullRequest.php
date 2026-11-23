<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An open pull request, as the webhook last told of it: reviewed by itself or
 * not. Gone once it is merged or declined.
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
    /** How many history entries are kept: the newest. */
    public const HISTORY_LIMIT = 40;

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
            'opened_at' => 'datetime',
            'head_since' => 'datetime',
            'reviewers' => 'array',
            'merge_conflicts' => 'boolean',
            'conflicting_files' => 'array',
            'merge_checked_at' => 'datetime',
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
