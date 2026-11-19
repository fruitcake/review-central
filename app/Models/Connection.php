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
 */
class Connection extends Model
{
    /** @use HasFactory<ConnectionFactory> */
    use HasFactory;

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
