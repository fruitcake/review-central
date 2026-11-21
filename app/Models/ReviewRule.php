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

    public const SEVERITIES = ['HIGH' => 'High', 'MEDIUM' => 'Medium', 'LOW' => 'Low'];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReviewRuleKind::class,
            'paths' => 'array',
            'enabled' => 'boolean',
        ];
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
        return $this->belongsTo(Repository::class);
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
