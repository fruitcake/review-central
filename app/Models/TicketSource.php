<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketStyle;
use Database\Factories\TicketSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where the tickets a pull request title names are read from: which references
 * count, and which connection answers them.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $connection_id
 * @property int|null $repository_id
 * @property string $name
 * @property TicketStyle $style
 * @property array<int, string>|null $prefixes
 * @property string|null $reference_prefix
 * @property int $position
 * @property bool $readable_over_mcp whether people of the tenant may read its tickets over MCP, with the connection's access
 */
class TicketSource extends Model
{
    /** @use HasFactory<TicketSourceFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'style' => TicketStyle::class,
            'prefixes' => 'array',
            'position' => 'integer',
            'readable_over_mcp' => 'boolean',
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
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        // A removed one too: the source is still only for that one, not for every repository.
        return $this->belongsTo(Repository::class)->withTrashed();
    }
}
