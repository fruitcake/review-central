<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Whether an open pull request is a draft, as the workspace webhook last said.
 *
 * @property int $id
 * @property int $repository_id
 * @property int $pull_request_id
 * @property bool $draft
 * @property string|null $bitbucket_updated_on
 */
class PullRequestDraft extends Model
{
    protected $fillable = [
        'repository_id',
        'pull_request_id',
    ];

    protected $attributes = [
        'draft' => false,
    ];

    protected function casts(): array
    {
        return [
            'draft' => 'boolean',
        ];
    }
}
