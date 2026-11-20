<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An open pull request, as the webhook last told of it: reviewed by itself or
 * not. Gone once it is merged or declined.
 *
 * @property int $id
 * @property int $repository_id
 * @property int $pull_request_id
 * @property string|null $title
 * @property string|null $author
 * @property string|null $author_id
 * @property string|null $author_nickname
 * @property string|null $branch
 * @property string|null $commit
 * @property bool $draft
 * @property bool $from_fork
 * @property bool $awaiting_approval Not started by itself, for someone in the team to start
 * @property string|null $source_updated_on
 * @property-read Repository $repository
 */
class OpenPullRequest extends Model
{
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
        ];
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
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
}
