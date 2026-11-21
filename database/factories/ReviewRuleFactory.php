<?php

namespace Database\Factories;

use App\Enums\ReviewRuleKind;
use App\Models\ReviewRule;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewRule>
 */
class ReviewRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'kind' => ReviewRuleKind::Check,
            'title' => fake()->sentence(6),
            'body' => fake()->sentence(12),
            'enabled' => true,
        ];
    }

    public function convention(): static
    {
        return $this->state(['kind' => ReviewRuleKind::Convention]);
    }
}
