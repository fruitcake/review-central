<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewEffort;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which reviewer the AI review runs, and at what model and effort. Without a
 * repository it is the default for every repository; with one, a null field
 * inherits that default. See ReviewSettings for how they combine.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $repository_id
 * @property array<int, array{provider: string, share: int, budget?: float|int|string|null}>|null $providers The reviewers in order, each with its relative share of pull requests and what it may cost in a month, in the tenant's currency.
 * @property string|null $claude_model
 * @property ReviewEffort|null $claude_effort
 * @property string|null $claude_advisor
 * @property string|null $claude_max_budget Dollars, or "none" for no cap.
 * @property string|null $codex_model
 * @property ReviewEffort|null $codex_effort
 * @property string|null $ocr_model
 * @property ReviewEffort|null $ocr_effort
 * @property string|null $verify "true" or "false": whether a second check runs on each finding that would block.
 * @property string|null $language the code of the language the review writes for people in; English when none
 * @property string|null $ticket_grading "true" or "false": whether the summary grades the change against the ticket's acceptance criteria
 * @property string|null $extra_passes "true" or "false": whether Claude Code runs the review-toolkit agents and /code-review as well
 * @property string|null $turn_notes "true" or "false": whether a small model writes what the one at turn has to do; the team's alone
 * @property string|null $turn_model The model it writes with: a tier or a name; the provider's cheapest when none
 * @property int|null $follow_ups Reviews a container job carries on with after its own; null inherits.
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Repository|null $repository
 */
class ReviewSetting extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'providers' => 'array',
            'follow_ups' => 'integer',
            'claude_effort' => ReviewEffort::class,
            'codex_effort' => ReviewEffort::class,
            'ocr_effort' => ReviewEffort::class,
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
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
