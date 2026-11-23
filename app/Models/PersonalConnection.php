<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's own account at a host, connected so that what they write there through
 * Review Central is written as them. Only ever used for that user.
 *
 * @property int $id
 * @property int $user_id
 * @property string $host
 * @property string $account_id
 * @property string|null $nickname
 * @property string $access_token
 * @property string|null $refresh_token
 * @property CarbonInterface|null $expires_at
 * @property array<int, string>|null $scopes
 * @property-read User $user
 */
class PersonalConnection extends Model
{
    protected $guarded = [];

    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    /**
     * Whether its access token has run out, or is about to.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isBefore(now()->addMinute());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
