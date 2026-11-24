<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Broadcasts;
use App\Services\PullRequestTracking;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One run of the AI review for one commit.
 *
 * The row is opened by the precheck step, the moment that step takes the review
 * lock, and closed by the report step. A run that never reports back stays `running`
 * until the hourly sweep decides it cannot still be working.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $repository_id
 * @property string $workspace
 * @property string $slug
 * @property string $branch
 * @property int|null $pull_request_id
 * @property string $commit
 * @property int|null $build_number
 * @property string $status
 * @property string|null $result
 * @property string|null $summary
 * @property array<int, array<string, mixed>>|null $findings
 * @property array<string, mixed>|null $context
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property float|string|null $cost A decimal, read as a string
 * @property bool $cost_estimated
 * @property array<int, string>|null $manual_review What the review left for a person to check
 * @property string|null $model
 * @property string|null $agent
 * @property string|null $provider
 * @property string|null $effort
 * @property string|null $advisor
 * @property array<string, bool>|null $modules Which parts of the review were on, by name: see ReviewSettings::MODULES; null for a run from before, or a reviewer they do not apply to
 * @property string|null $instructions_version
 * @property bool $custom
 * @property string $kind What was reviewed: a pull request, or a branch for its security
 * @property array<string, array<string, int>>|null $usage
 * @property CarbonInterface $started_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface|null $dismissed_at Set aside by someone who looked at a review that did not finish
 * @property CarbonInterface|null $restarted_at
 * @property int|null $restarted_as
 * @property string|null $runner
 * @property string|null $superseded_by
 * @property string|null $fail_on
 * @property int|null $duration_seconds
 * @property int|null $queue_seconds
 * @property int|null $peak_memory_mb
 * @property string|null $memory_limit
 * @property array<string, mixed>|null $metrics What its steps took and what its reviewer used: see App\Services\RunMetrics
 * @property string|null $sandbox
 * @property string|null $sandbox_size
 * @property int|null $sandbox_seconds
 * @property string|null $sandbox_cost
 * @property string|null $input_hash
 * @property string|null $rating_token
 * @property-read Repository $repository
 * @property-read ReviewInstructionVersion|null $instructionsVersion
 * @property-read Collection<int, ReviewRating> $ratings
 * @property-read float|null $ratings_avg_score
 * @property-read int|null $ratings_count
 */
class ReviewRun extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        // Open pages are told when a review starts or ends, where the installation
        // broadcasts; what it cost or used does not change how it reads in a list.
        static::created(fn (self $run) => Broadcasts::runChanged($run));

        static::updated(function (self $run): void {
            if ($run->wasChanged(['status', 'result'])) {
                Broadcasts::runChanged($run);

                // Whose turn the pull request is may have moved with it.
                if ($run->pull_request_id !== null) {
                    $record = OpenPullRequest::query()->with('repository.tenant')
                        ->where('repository_id', $run->repository_id)
                        ->where('pull_request_id', $run->pull_request_id)
                        ->first();

                    if ($record !== null) {
                        rescue(fn () => app(PullRequestTracking::class)->describe($record), null, true);
                    }
                }
            }
        });
    }

    /**
     * Started and not begun: waiting for a worker, or for the review of the commit
     * before it. The review takes this record over when it asks for its context.
     */
    public const STATUS_QUEUED = 'queued';

    /** Started, and expected to report back. */
    public const STATUS_RUNNING = 'running';

    /** Reported a result. */
    public const STATUS_FINISHED = 'finished';

    /** The build ended without a result. */
    public const STATUS_FAILED = 'failed';

    /** Never reported back, and too old to still be working. */
    public const STATUS_TIMEOUT = 'timeout';

    /**
     * Turned away because another run held the lock. It reviewed nothing, and it is
     * kept so the newest one can be started again when the lock frees up.
     */
    public const STATUS_PENDING = 'pending';

    /** Was pending, and a build has since been started in its place. */
    public const STATUS_RESTARTED = 'restarted';

    /**
     * Was pending, and was overtaken: a newer commit was waiting too, and reviewing
     * that one covers this one. Not a failure — nothing went wrong with it.
     */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * Reported a result that was kept off the pull request on purpose: a benchmark
     * or a test of the review. Not `finished`, so the next real run neither skips
     * the commit as answered nor builds on findings nobody saw.
     */
    public const STATUS_UNPUBLISHED = 'unpublished';

    /**
     * Stopped by someone before it finished: the reviewer was cut off, and nothing
     * was posted. Its summary says who stopped it.
     */
    public const STATUS_STOPPED = 'stopped';

    /**
     * How long a run may stay `running` before the sweep gives up on it. The review
     * step's own max-time is 30 minutes, so an hour is comfortably past anything
     * that could still be working.
     */
    public const TIMEOUT_MINUTES = 60;

    /**
     * How long a run may stay `queued`: it can wait behind a whole review and for
     * a worker, so longer than one that is running.
     */
    public const QUEUED_TIMEOUT_MINUTES = 180;

    protected $guarded = [];

    /**
     * Whether it did not finish and nobody has set it aside yet.
     */
    public function canBeDismissed(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_TIMEOUT], true) && $this->dismissed_at === null;
    }

    /**
     * The id stays the key; the uuid is what a run is addressed by.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dismissed_at' => 'datetime',
            'findings' => 'array',
            'context' => 'array',
            'manual_review' => 'array',
            'usage' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'restarted_at' => 'datetime',
            'cost' => 'decimal:4',
            'sandbox_cost' => 'decimal:4',
            'sandbox_seconds' => 'integer',
            'cost_estimated' => 'boolean',
            'custom' => 'boolean',
            'metrics' => 'array',
            'modules' => 'array',
        ];
    }

    /**
     * Whether the review passed and still left something for a person to check:
     * it then neither approves the pull request nor asks for changes.
     */
    public function needsManualReview(): bool
    {
        return strtoupper((string) $this->result) !== 'FAILED' && ($this->manual_review ?? []) !== [];
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

    /**
     * The instructions the run was given, when the server supplied them.
     *
     * @return BelongsTo<ReviewInstructionVersion, $this>
     */
    public function instructionsVersion(): BelongsTo
    {
        return $this->belongsTo(ReviewInstructionVersion::class, 'instructions_version', 'version');
    }

    /**
     * @return HasMany<ReviewRating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(ReviewRating::class);
    }

    /**
     * @param  Builder<ReviewRun>  $query
     * @return Builder<ReviewRun>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RUNNING);
    }

    /**
     * Runs that were turned away and have not been started again.
     *
     * @param  Builder<ReviewRun>  $query
     * @return Builder<ReviewRun>
     */
    public function scopeAwaitingRestart(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)->whereNull('restarted_at');
    }

    /**
     * Records how the run ended, whatever it was.
     *
     * @param  array<int, array<string, mixed>>|null  $findings
     */
    public function finish(
        string $status,
        ?string $result = null,
        ?string $summary = null,
        ?array $findings = null,
        bool $timed = true,
        ?int $reportedDuration = null,
    ): void {
        $this->status = $status;
        $this->result = $result;
        $this->summary = $summary;
        $this->findings = $findings;
        $this->finished_at = now();

        // Three cases, in the order they can be trusted. The server's own clock when it
        // saw the run start; the duration the run measured when it did not; and
        // null rather than zero when neither is available — a review that took eight
        // minutes reported as taking none is worse than one that admits it does not
        // know, and the column is nullable so it can say so.
        $this->duration_seconds = match (true) {
            $timed => (int) $this->started_at->diffInSeconds($this->finished_at),
            $reportedDuration !== null && $reportedDuration >= 0 => $reportedDuration,
            default => null,
        };

        $this->save();
    }

    /**
     * The token types Claude Code reports. Cache reads and cache writes are priced
     * differently from plain input, so they are never folded together.
     *
     * @var array<int, string>
     */
    public const TOKEN_TYPES = ['input', 'output', 'cache_read', 'cache_creation'];

    /**
     * Records what a run used, keyed by model and then by token type:
     *
     *   ['claude-opus-5' => ['input' => 12000, 'output' => 3000, 'cache_read' => 900000]]
     *
     * `input_tokens` and `output_tokens` are kept alongside as sums over every
     * model, because a chart wants one number and a bill wants the breakdown.
     *
     * @param  array<string, array<string, int|float>>  $usage
     */
    public function recordUsage(array $usage, ?string $model = null, ?float $cost = null): void
    {
        $normalised = [];

        foreach ($usage as $usedModel => $types) {
            foreach ($types as $type => $tokens) {
                // The OCR collector called cache writes `cache_write` before it
                // took the name every other collector uses.
                $type = $type === 'cache_write' ? 'cache_creation' : $type;

                if (in_array($type, self::TOKEN_TYPES, true)) {
                    $normalised[$usedModel][$type] = ($normalised[$usedModel][$type] ?? 0) + (int) $tokens;
                }
            }
        }

        $this->usage = $normalised === [] ? null : $normalised;
        $this->input_tokens = $this->sumTokens($normalised, 'input') ?: null;
        $this->output_tokens = $this->sumTokens($normalised, 'output') ?: null;

        // The model that did the work, which is the one worth showing; the rest are
        // in the breakdown. Whichever key happened to come first is not that: a
        // review where Sonnet spent 1.7M tokens and Haiku 66k was headlined
        // "haiku" because Haiku's session was written to the transcript first.
        $this->model = $model ?? self::busiestModel($normalised);
        $this->cost = $cost;
    }

    /**
     * The model that used the most tokens, or null when nothing did.
     *
     * Ties go to the first, which only matters when two models did identical work
     * and either answer is as good as the other.
     *
     * @param  array<string, array<string, int>>  $usage
     */
    protected static function busiestModel(array $usage): ?string
    {
        $busiest = null;
        $most = 0;

        foreach ($usage as $model => $types) {
            $tokens = array_sum($types);

            if ($tokens > $most) {
                $most = $tokens;
                $busiest = $model;
            }
        }

        return $busiest;
    }

    /**
     * @param  array<string, array<string, int>>  $usage
     */
    protected function sumTokens(array $usage, string $type): int
    {
        return collect($usage)->sum(fn (array $types): int => $types[$type] ?? 0);
    }

    /**
     * Every token this run used, whatever the model or the type. The number for
     * "how much work was this", never for "what did it cost".
     */
    public function totalTokens(): int
    {
        return collect($this->usage ?? [])
            ->sum(fn (array $types): int => array_sum($types));
    }

    /**
     * The share of this run's input that came from the prompt cache, or null when
     * it read no input at all.
     *
     * Only input counts: output is never cached, and cache writes are input that
     * missed. A review is many turns over one growing conversation, so most of its
     * input is the same prefix again and again — a low share means something is
     * changing near the front of the prompt between turns, not that the run was
     * short.
     */
    public function cacheHitRate(): ?float
    {
        $usage = $this->usage ?? [];

        $read = $this->sumTokens($usage, 'cache_read');
        $missed = $this->sumTokens($usage, 'input') + $this->sumTokens($usage, 'cache_creation');

        if ($read + $missed === 0) {
            return null;
        }

        return $read / ($read + $missed);
    }

    /**
     * Runs whose tokens are recorded but whose cost is not.
     *
     * Almost always because the model was missing from the price list when the run
     * reported — a model released since the list was last fetched, or one the
     * catalogue does not carry. The daily price sync comes back to these.
     *
     * @param  Builder<ReviewRun>  $query
     * @return Builder<ReviewRun>
     */
    public function scopeUncosted(Builder $query): Builder
    {
        return $query->whereNull('cost')->whereNotNull('usage');
    }

    /**
     * Works the cost out from the tokens and the stored prices, and says whether it
     * managed to.
     *
     * Marked as estimated, because a figure someone might act on should say whether
     * it was measured or derived. All or nothing: ModelPrice returns null when any
     * model in the run has no price, since a cost missing one model's tokens reads
     * as a cheap review rather than an incomplete one.
     */
    public function estimateCost(): bool
    {
        if ($this->cost !== null || empty($this->usage)) {
            return false;
        }

        $estimate = ModelPrice::estimate($this->usage);

        if ($estimate === null) {
            return false;
        }

        $this->cost = $estimate;
        $this->cost_estimated = true;

        return $this->save();
    }

    public function pullRequestUrl(): ?string
    {
        return $this->pull_request_id === null ? null : $this->repository->sourceControl()->pullRequestUrl($this->pull_request_id);
    }

    /**
     * The token in this run's rating link, made the first time the link is.
     */
    public function ratingToken(): string
    {
        if ($this->rating_token === null) {
            $this->rating_token = Str::random(40);
            $this->save();
        }

        return $this->rating_token;
    }

    /**
     * Whether the run reported a finding by this id.
     */
    public function hasFinding(string $id): bool
    {
        return in_array($id, array_map(strval(...), array_column($this->findings ?? [], 'id')), true);
    }

    /**
     * The findings that matter for a graph: how many were open at the end.
     */
    public function openFindings(): int
    {
        return collect($this->findings ?? [])
            ->filter(fn (array $finding): bool => in_array($finding['status'] ?? null, ['new', 'open'], true))
            ->count();
    }
}
