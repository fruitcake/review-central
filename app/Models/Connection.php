<?php

declare(strict_types=1);

namespace App\Models;

use App\Connections\ConnectionDriver;
use App\Connections\Drivers;
use App\Enums\ConnectionKind;
use Carbon\CarbonInterface;
use Database\Factories\ConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service a tenant connected, with its own credentials.
 *
 * @property int $id
 * @property int $tenant_id
 * @property ConnectionKind $kind
 * @property string $driver
 * @property string $name
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property string|null $webhook_key
 * @property string|null $webhook_secret
 * @property CarbonInterface|null $webhook_received_at
 * @property CarbonInterface|null $deleted_at
 */
class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use HasFactory;

    // A removed source control connection is kept, without its secrets, for the
    // history of the repositories that were on it. Others are deleted outright:
    // see remove().
    use SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['credentials', 'webhook_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ConnectionKind::class,
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'webhook_secret' => 'encrypted',
            'webhook_received_at' => 'datetime',
        ];
    }

    /**
     * Removes the connection: a source control connection is kept out of sight,
     * its credentials and webhook gone, so nothing reaches the host with it any
     * more; any other is deleted.
     */
    public function remove(): void
    {
        if ($this->kind !== ConnectionKind::SourceControl) {
            $this->forceDelete();

            return;
        }

        $this->forceFill(['credentials' => null, 'webhook_key' => null, 'webhook_secret' => null])->save();
        $this->delete();
    }

    /**
     * Whether a repository can move between this connection and another: both
     * reach the same host, as GitHub with a token and as the GitHub App do.
     */
    public function sameHostAs(self $other): bool
    {
        return $this->kind === ConnectionKind::SourceControl && $other->kind === ConnectionKind::SourceControl
            && self::host($this->driver) === self::host($other->driver);
    }

    /**
     * Whether a repository of this owner can be on this connection: one set up
     * for an owner, as a GitHub App's installation is, reaches only theirs.
     */
    /**
     * Whether a repository its webhook tells of for the first time is added, to be
     * listed and have its pull requests kept, without reviewing them by itself.
     */
    public function addsRepositories(): bool
    {
        return ($this->settings['add_repositories'] ?? false) === true;
    }

    public function reachesOwner(string $workspace): bool
    {
        $owner = $this->setting('workspace');

        return blank($owner) || mb_strtolower((string) $owner) === mb_strtolower($workspace);
    }

    protected static function host(string $driver): string
    {
        return $driver === 'github-app' ? 'github' : $driver;
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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

    public function driver(): ConnectionDriver
    {
        return app(Drivers::class)->get($this->kind, $this->driver);
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function setting(string $key): ?string
    {
        $value = $this->settings[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
