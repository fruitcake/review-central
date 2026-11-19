<?php

namespace Database\Factories;

use App\Enums\TicketStyle;
use App\Models\Connection;
use App\Models\TicketSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketSource>
 */
class TicketSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'connection_id' => Connection::factory()->jira(),
            'tenant_id' => fn (array $attributes) => Connection::query()->whereKey($attributes['connection_id'])->sole()->tenant_id,
            'name' => 'Jira',
            'style' => TicketStyle::Key,
            'prefixes' => [],
        ];
    }
}
