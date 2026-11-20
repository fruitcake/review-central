<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonInterface|null $email_verified_at
 * @property string $password
 * @property bool $is_admin
 * @property array<int, string>|null $source_accounts Who they are on the hosts: a nickname, login or account ID each
 * @property string|null $remember_token
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'is_admin', 'source_accounts'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_admin' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'source_accounts' => 'array',
        ];
    }

    /**
     * Whether a person on a host is this user: by an account they listed as theirs,
     * or, while they listed none, by their name as the host shows it.
     *
     * @param  array{name?: string|null, account_id?: string|null, nickname?: string|null}  $person
     */
    public function isOnHost(array $person): bool
    {
        $accounts = collect($this->source_accounts ?? [])->map(fn (mixed $account): string => mb_strtolower(trim((string) $account)))->filter();

        if ($accounts->isNotEmpty()) {
            return $accounts->intersect(array_filter([
                mb_strtolower((string) ($person['account_id'] ?? '')),
                mb_strtolower((string) ($person['nickname'] ?? '')),
            ]))->isNotEmpty();
        }

        return filled($person['name'] ?? null) && mb_strtolower(trim((string) $person['name'])) === mb_strtolower(trim($this->name));
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * Who this user is at the providers they sign in with.
     *
     * @return HasMany<UserIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)->withTimestamps();
    }

    /**
     * The admin panel is for administrators; the tenant panel for anyone who is in
     * a tenant, and for an administrator, who may open every tenant.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() !== 'admin' || $this->is_admin;
    }

    /**
     * Where this user signs in, and so where they choose a password.
     */
    public function panel(): Panel
    {
        return Filament::getPanel($this->is_admin ? 'admin' : 'app');
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->is_admin ? Tenant::query()->orderBy('name')->get() : $this->tenants()->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->is_admin || $this->tenants()->whereKey($tenant->getKey())->exists();
    }
}
