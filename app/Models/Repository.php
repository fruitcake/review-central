<?php

declare(strict_types=1);

namespace App\Models;

use App\Connections\SourceControlDriver;
use App\Enums\ReviewsFrom;
use App\Runners\ReviewRunner;
use App\Runners\Runners;
use App\SourceControl\SourceControl;
use Carbon\CarbonInterface;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
 */
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

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
        return $this->belongsTo(Connection::class, 'connection_id');
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
        return $this->driver()->client($this);
    }
}
