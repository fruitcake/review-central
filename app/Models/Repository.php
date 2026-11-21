<?php

declare(strict_types=1);

namespace App\Models;

use App\Connections\SourceControlDriver;
use App\Enums\ReviewsFrom;
use App\Runners\ReviewRunner;
use App\Runners\Runners;
use App\SourceControl\FiltersSecrets;
use App\SourceControl\ReadOnlyClient;
use App\SourceControl\SourceControl;
use App\Support\SecretFilter;
use Carbon\CarbonInterface;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $connection_id
 * @property string $workspace
 * @property string $slug
 * @property string|null $name
 * @property string|null $review_runner
 * @property bool $reviews_enabled
 * @property ReviewsFrom $reviews_from Whose pull requests are reviewed by themselves, while reviews_enabled
 * @property array<int, string>|null $review_stack
 * @property array<int, string>|null $review_available_providers
 * @property CarbonInterface|null $review_available_at
 * @property CarbonInterface|null $webhook_received_at
 * @property CarbonInterface|null $deleted_at
 */
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    // Removed, it is kept with its history, and comes back when added again.
    use SoftDeletes;

    /** The tenant's secrets, read once for every client of this repository. */
    protected ?SecretFilter $secretFilter = null;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'reviews_from' => 'everyone',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reviews_enabled' => 'boolean',
            'reviews_from' => ReviewsFrom::class,
            'review_stack' => 'array',
            'review_available_providers' => 'array',
            'review_available_at' => 'datetime',
            'webhook_received_at' => 'datetime',
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
     * @return BelongsTo<Connection, $this>
     */
    public function integration(): BelongsTo
    {
        // Also a removed one: a removed repository's history still names its host.
        return $this->belongsTo(Connection::class, 'connection_id')->withTrashed();
    }

    /**
     * @return HasOne<ReviewSetting, $this>
     */
    public function reviewSetting(): HasOne
    {
        return $this->hasOne(ReviewSetting::class);
    }

    /**
     * @return HasMany<ReviewRun, $this>
     */
    public function reviewRuns(): HasMany
    {
        return $this->hasMany(ReviewRun::class);
    }

    /**
     * @return HasMany<ReviewRule, $this>
     */
    public function reviewRules(): HasMany
    {
        return $this->hasMany(ReviewRule::class);
    }

    public function fullName(): string
    {
        return "{$this->workspace}/{$this->slug}";
    }

    public function driver(): SourceControlDriver
    {
        /** @var SourceControlDriver $driver */
        $driver = $this->integration->driver();

        return $driver;
    }

    public function runner(): ReviewRunner
    {
        return app(Runners::class)->get($this->runnerName());
    }

    /**
     * The runner this repository's reviews run on: its own choice, unless the
     * installation allows only others.
     */
    public function runnerName(): string
    {
        return app(Runners::class)->nameFor($this->review_runner);
    }

    public function sourceControl(): SourceControl
    {
        $client = $this->driver()->client($this);

        // Whatever the review writes on the host passes the tenant's secrets first:
        // it reads what anyone wrote on the pull request, and can be talked into
        // writing a key it can reach.
        // One not saved yet, as when its stack is looked up, is its connection's tenant's.
        $tenant = $this->getAttribute('tenant_id') ?? $this->integration->tenant_id;

        if ($client instanceof FiltersSecrets) {
            $this->secretFilter ??= SecretFilter::forTenant((int) $tenant);
            $client->keepingOut($this->secretFilter);
        }

        // A connection set to keep its reviews here reads through its client and
        // writes nothing, whatever its token may do.
        return $this->integration?->setting(ReadOnlyClient::SETTING) === ReadOnlyClient::READ ? new ReadOnlyClient($client) : $client;
    }
}
