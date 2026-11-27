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
 * @property string|null $extra_passes "true" or "false": whether the reviewer runs its CLI's own review pass for candidates: the review-toolkit agents and /code-review for Claude Code, `codex exec review` for Codex. Null leaves it to the reviewer: off for Claude Code, on for Codex
 * @property string|null $ocr "true" or "false": whether OpenCodeReview picks the files and a checklist per file for Claude Code, Codex and Pi; on when null
 * @property string|null $scan "true" or "false": whether secrets and composer audit are checked before the reviewer starts; on when null
 * @property string|null $floor_guard "true" or "false": whether the reviewer runs the floor guard on loosened checks; on when null
 * @property string|null $reach "true" or "false": whether a Laravel review gets REACH, the paths that reach a changed check; on when null
 * @property string|null $code_index "true" or "false": whether REACH follows a code index Mago builds; off when null
 * @property string|null $question_web_search "true" or "false": whether an answer to a question may search the web; off when null
 * @property string|null $turn_notes "true" or "false": whether a small model writes what the one at turn has to do; the team's alone
 * @property string|null $turn_model The model it writes with: a tier or a name; the provider's cheapest when none
 * @property string|null $strategy off, record or apply: whether pull requests are classified, and whether that decides their effort and model
 * @property string|null $strategy_effort the effort a simple pull request runs at; normal and complex ones a step and two higher
 * @property string|null $strategy_model the model tier a simple pull request runs at: cheapest, default or smartest
 * @property int|null $max_rounds Follow-up rounds that may add findings; null inherits, five where nothing sets it
 * @property array<string, string>|null $fail_on The severity from which a finding holds the merge: under `default` for every category, and per category over it. A key left out inherits; see ReviewSettings::failOn()
 * @property string|null $findings_check weekly or monthly: how often the open findings of merged pull requests are checked; the team's alone
 * @property string|null $pull_request_budget What the reviews of one pull request may cost, in the tenant's currency; null inherits, none where nothing sets it. A decimal, read as a string
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
            'max_rounds' => 'integer',
            'fail_on' => 'array',
            'pull_request_budget' => 'decimal:2',
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
