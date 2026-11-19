<?php

namespace Database\Factories;

use App\Enums\ConnectionKind;
use App\Models\Connection;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    /**
     * A Bitbucket connection, which is what a repository needs.
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'kind' => ConnectionKind::SourceControl,
            'driver' => 'bitbucket',
            'name' => 'Bitbucket',
            'credentials' => ['access_token' => 'bitbucket-token'],
            'settings' => ['workspace' => 'acme'],
        ];
    }

    public function anthropic(string $key = 'sk-ant-test'): static
    {
        return $this->state([
            'kind' => ConnectionKind::Llm,
            'driver' => 'anthropic',
            'name' => 'Anthropic',
            'credentials' => ['api_key' => $key],
            'settings' => [],
        ]);
    }

    public function openai(string $key = 'sk-openai-test'): static
    {
        return $this->state([
            'kind' => ConnectionKind::Llm,
            'driver' => 'openai',
            'name' => 'OpenAI',
            'credentials' => ['api_key' => $key],
            'settings' => [],
        ]);
    }

    public function jira(): static
    {
        return $this->state([
            'kind' => ConnectionKind::Tickets,
            'driver' => 'jira',
            'name' => 'Jira',
            'credentials' => ['api_token' => 'jira-token'],
            'settings' => ['site' => 'acme.atlassian.net', 'email' => 'dev@example.com'],
        ]);
    }

    public function asana(): static
    {
        return $this->state([
            'kind' => ConnectionKind::Tickets,
            'driver' => 'asana',
            'name' => 'Asana',
            'credentials' => ['access_token' => 'asana-token'],
            'settings' => ['workspace' => '1200000000000000'],
        ]);
    }

    public function github(): static
    {
        return $this->state([
            'kind' => ConnectionKind::Tickets,
            'driver' => 'github',
            'name' => 'GitHub',
            'credentials' => ['token' => 'github-token'],
            'settings' => [],
        ]);
    }
}
