<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewRuleKind;
use Carbon\CarbonInterface;
use Database\Factories\ReviewRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team's own rule for its reviews, in plain language: one to check, or a pattern
 * that is not a defect here. For one repository, or for all of the tenant's. The
 * reviewer reads them as review-rules.md, next to the instructions.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $repository_id null for every repository of the tenant
 * @property ReviewRuleKind $kind
 * @property string $title the rule in one line
 * @property string|null $body why, and what counts
 * @property array<int, string>|null $paths globs the rule is limited to; null for every file
 * @property string|null $severity HIGH, MEDIUM or LOW for a broken check; null leaves it to the reviewer
 * @property bool $enabled
 * @property CarbonInterface|null $archived_at when the check found the rule in a rule file on the repository's main branch, which turned it off
 * @property string|null $archived_to that file; kept when a person turns the rule on again, which the check then leaves on
 * @property string|null $archived_note what the check saw, in a sentence
 * @property string|null $source where a rule made from a finding came from: "<run uuid>:<finding id>"
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Repository|null $repository
 */
class ReviewRule extends Model
{
    /** @use HasFactory<ReviewRuleFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * The severities a rule can set, as the user reads them.
     *
     * @return array<string, string>
     */
    public static function severities(): array
    {
        return ['HIGH' => __('High'), 'MEDIUM' => __('Medium'), 'LOW' => __('Low')];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReviewRuleKind::class,
            'paths' => 'array',
            'enabled' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Turned on again by a person: it is the team's rule here again. The file it
        // was found in stays, and keeps the check from turning it off once more.
        static::saving(function (self $rule): void {
            if ($rule->isDirty('enabled') && $rule->enabled) {
                $rule->archived_at = null;
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        // Also a removed one: its rules stay its own, and come back with it.
        return $this->belongsTo(Repository::class)->withTrashed();
    }

    /**
     * The rules a review of this repository reads: its own and the tenant's for all.
     *
     * @param  Builder<self>  $query
     */
    public function scopeFor(Builder $query, Repository $repository): void
    {
        $query->where('tenant_id', $repository->tenant_id)
            ->where(fn (Builder $scope) => $scope->whereNull('repository_id')->orWhere('repository_id', $repository->getKey()));
    }
}
