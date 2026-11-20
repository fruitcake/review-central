<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An app this installation, or one tenant, is registered as at a service: what
 * a person signs in there through, instead of a pasted token. One of the
 * platform's is for every tenant or for those listed; a tenant's own is for that
 * tenant alone.
 *
 * @property int $id
 * @property int|null $tenant_id The tenant that added it for itself; null for the platform's
 * @property string $type One of App\Connections\Integrations::TYPES
 * @property string $name
 * @property array<string, string> $credentials
 * @property bool $for_all_tenants
 * @property-read Tenant|null $tenant
 * @property-read Collection<int, Tenant> $tenants
 */
class IntegrationApp extends Model
{
    protected $fillable = ['tenant_id', 'type', 'name', 'credentials', 'for_all_tenants'];

    protected $hidden = ['credentials'];

    protected $attributes = ['for_all_tenants' => false];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'for_all_tenants' => 'boolean',
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
     * The tenants one of the platform's apps is for, where it is not for all.
     *
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class);
    }

    /**
     * The apps a tenant may sign in through: its own, and the platform's that are
     * for every tenant or list it.
     *
     * @param  Builder<IntegrationApp>  $query
     * @return Builder<IntegrationApp>
     */
    public function scopeFor(Builder $query, Tenant $tenant): Builder
    {
        return $query->where(fn (Builder $query): Builder => $query
            ->where('tenant_id', $tenant->getKey())
            ->orWhere(fn (Builder $query): Builder => $query->whereNull('tenant_id')->where(fn (Builder $query): Builder => $query
                ->where('for_all_tenants', true)
                ->orWhereHas('tenants', fn (Builder $query): Builder => $query->whereKey($tenant->getKey())))));
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
