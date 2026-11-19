<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $monthly_budget In the tenant's currency
 * @property string $currency
 * @property string $timezone The zone its times are shown in
 * @property array<int, string>|null $sign_in_domains The organisations' domains whose people may join by signing in
 * @property array<string, mixed>|null $theme_colors Its own ink, accent and button colour, each a hex colour or left out
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'USD',
        'timezone' => 'Europe/Amsterdam',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_budget' => 'decimal:2',
            'theme_colors' => 'array',
            'sign_in_domains' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return HasMany<Connection, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    /**
     * @return HasMany<Repository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    /**
     * @return HasMany<TicketSource, $this>
     */
    public function ticketSources(): HasMany
    {
        return $this->hasMany(TicketSource::class);
    }

    /**
     * @return HasMany<ReviewQuestion, $this>
     */
    public function reviewQuestions(): HasMany
    {
        return $this->hasMany(ReviewQuestion::class);
    }

    /**
     * @return HasMany<ReviewRun, $this>
     */
    public function reviewRuns(): HasMany
    {
        return $this->hasMany(ReviewRun::class);
    }

    /**
     * @return HasMany<ReviewSetting, $this>
     */
    public function reviewSettings(): HasMany
    {
        return $this->hasMany(ReviewSetting::class);
    }

    /**
     * @return HasMany<ReviewInstruction, $this>
     */
    public function reviewInstructions(): HasMany
    {
        return $this->hasMany(ReviewInstruction::class);
    }
}
