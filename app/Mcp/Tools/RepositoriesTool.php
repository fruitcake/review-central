<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\PullRequestOverview;
use App\Services\RepositoryAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TXT'
    Lists your tenants and their repositories in Review Central, as JSON: for each repository its host, whether it is reviewed by itself, how many pull requests are open and its last review. Use it to find a repository's exact name before calling the other tools.
    TXT)]
class RepositoriesTool extends ReviewTool
{
    protected string $name = 'repositories';

    public function handle(Request $request, RepositoryAccess $access, PullRequestOverview $overview): Response|ResponseFactory
    {
        $validated = $request->validate(['tenant' => ['nullable', 'string', 'max:255']]);

        return $this->answer(function () use ($request, $validated, $access, $overview): array {
            $user = $this->user($request);
            $tenants = $access->tenants($user)->when(filled($validated['tenant'] ?? null), fn ($tenants) => $tenants->where('slug', $validated['tenant']));

            return [
                'tenants' => $tenants->map(fn ($tenant): array => [
                    'slug' => $tenant->slug,
                    'name' => $tenant->name,
                    'you_are_admin' => $access->manages($user, $tenant),
                ])->values()->all(),
                'repositories' => $overview->repositories($access->repositories($user)->whereIn('tenant_id', $tenants->modelKeys())),
            ];
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tenant' => $schema->string()->description('Only this tenant, by its slug.'),
        ];
    }
}
