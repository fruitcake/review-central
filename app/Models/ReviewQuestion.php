<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question someone asked the reviewer: in a comment on a pull request, or on a
 * review's page, where it is answered alone.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $repository_id
 * @property int $pull_request_id
 * @property string $branch
 * @property int|null $comment_id null for a question asked on the review's page, which is answered there alone
 * @property int|null $user_id who asked on the review's page
 * @property int|null $answer_comment_id
 * @property int|null $follows_id
 * @property string|null $author
 * @property string $question
 * @property string|null $about the finding it was asked under, as the review posted it
 * @property string|null $answer
 * @property string $status
 * @property bool $resumed
 * @property string|null $model
 * @property string|null $cost
 * @property CarbonInterface|null $answered_at
 * @property CarbonInterface $created_at
 * @property-read Repository $repository
 * @property-read ReviewQuestion|null $follows
 */
class ReviewQuestion extends Model
{
    /** Asked, and a job is on its way to answer it. */
    public const STATUS_ASKED = 'asked';

    /** A reply under one of the reviewer's answers or findings that names nobody: a job first asks whether it is for the reviewer. */
    public const STATUS_UNSURE = 'unsure';

    /** A reply under an answer that was not for the reviewer; nothing was said to it. */
    public const STATUS_IGNORED = 'ignored';

    public const STATUS_ANSWERED = 'answered';

    /** The reviewer gave no answer; a question on the pull request was told so there. */
    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resumed' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function follows(): BelongsTo
    {
        return $this->belongsTo(self::class, 'follows_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Whether it waits to be answered: queued, being decided, or with the reviewer.
     */
    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_ASKED, self::STATUS_UNSURE], true);
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        // Also a removed one, whose history this is.
        return $this->belongsTo(Repository::class)->withTrashed();
    }
}
