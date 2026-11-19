<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewCommandAccess;
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
 * @property ReviewCommandAccess $review_command Who may start a review by commenting /review
 * @property array<int, string>|null $review_command_accounts The source control accounts that may, by nickname or account ID
 * @property bool $review_forks Whether a pull request from a fork is reviewed on a push and on a comment
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'currency' => 'USD',
        'timezone' => 'UTC',
        'review_command' => 'anyone',
        'review_forks' => false,
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
            'review_command' => ReviewCommandAccess::class,
            'review_command_accounts' => 'array',
            'review_forks' => 'boolean',
        ];
    }

    /**
     * Whether the author of a comment may start a review with `/review`. An account
     * is matched by its account ID, or by its nickname whatever its case; a display
     * name is not, since anyone can take any.
     */
    public function allowsReviewCommandFrom(?string $accountId, ?string $nickname): bool
    {
        return match ($this->review_command) {
            ReviewCommandAccess::Anyone => true,
            ReviewCommandAccess::Nobody => false,
            ReviewCommandAccess::Listed => collect($this->review_command_accounts ?? [])
                ->map(fn (mixed $account): string => mb_strtolower(trim((string) $account)))
                ->filter()
                ->intersect(array_filter([
                    $accountId === null ? null : mb_strtolower($accountId),
                    $nickname === null ? null : mb_strtolower($nickname),
                ]))
                ->isNotEmpty(),
        };
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
