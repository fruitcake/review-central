<?php

namespace Database\Factories;

use App\Models\Connection;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'connection_id' => Connection::factory(),
            'tenant_id' => fn (array $attributes) => Connection::query()->whereKey($attributes['connection_id'])->sole()->tenant_id,
            'workspace' => 'acme',
            'slug' => fake()->unique()->slug(2),
        ];
    }
}
