<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\PullRequestOverview;
use App\Services\RepositoryAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TXT'
    Lists the open pull requests of your repositories, as JSON, newest activity first: each with whose turn it is (the author, the reviewers, the team, the AI review or nobody), what they have to do and why, and its newest AI review.

    Use it for "what should I look at", "which of my pull requests are waiting" or "what is open on this repository". `only: "my-turn"` gives those waiting for you, `only: "mine"` those you opened. Who you are on the hosts is read from your profile in Review Central.
    TXT)]
class PullRequestsTool extends ReviewTool
{
    protected string $name = 'pull-requests';

    public function handle(Request $request, RepositoryAccess $access, PullRequestOverview $overview): Response|ResponseFactory
    {
        $validated = $request->validate([
            'repository' => ['nullable', 'string', 'max:500'],
            'tenant' => ['nullable', 'string', 'max:255'],
            'connection' => ['nullable', 'integer', 'min:1'],
            'only' => ['nullable', Rule::in([PullRequestOverview::MINE, PullRequestOverview::MY_TURN])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->answer(function () use ($request, $validated, $access, $overview): array {
            $user = $this->user($request);
            $repositories = $access->scoped($user, $validated['repository'] ?? null, $validated['tenant'] ?? null, $this->connection($request));

            return ['pull_requests' => $overview->list($user, $repositories, $validated['only'] ?? null, (int) ($validated['limit'] ?? 50))];
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->repositorySchema($schema, required: false),
            'only' => $schema->string()->enum([PullRequestOverview::MINE, PullRequestOverview::MY_TURN])->description('`my-turn`: waiting for you. `mine`: opened by you. Leave out for all.'),
            'limit' => $schema->integer()->description('At most this many. 50 by default.'),
        ];
    }
}
