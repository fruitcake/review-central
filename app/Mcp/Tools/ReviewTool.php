<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\ActionRefused;
use App\Exceptions\RepositoryNotFound;
use App\Models\Repository;
use App\Models\User;
use App\Services\RepositoryAccess;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * A tool of the MCP servers: a thin layer that reads its arguments, asks the
 * services as the signed-in user and returns what they give as structured JSON. The work
 * itself is in the services, for the panel and an API to share.
 */
abstract class ReviewTool extends Tool
{
    protected function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ActionRefused('Sign in first.');
    }

    /**
     * The repository the request names, among the user's.
     */
    protected function repository(Request $request): Repository
    {
        return app(RepositoryAccess::class)->find($this->user($request), (string) $request->get('repository'), $request->get('tenant'));
    }

    /**
     * Runs the work, and answers what the services refuse as an error the agent
     * can read and act on.
     *
     * @param  Closure(): (array<string, mixed>|string)  $work
     */
    protected function answer(Closure $work): Response|ResponseFactory
    {
        try {
            $result = $work();
        } catch (RepositoryNotFound|ActionRefused|InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        return is_string($result) ? Response::text($result) : Response::structured($result);
    }

    /**
     * The arguments that name a repository.
     *
     * @return array<string, Type>
     */
    protected function repositorySchema(JsonSchema $schema, bool $required = true): array
    {
        $repository = $schema->string()->description('The repository as workspace/slug, such as fruitcake/review-central. A git remote URL (`git remote get-url origin`) or the address of a pull request works too.');

        return [
            'repository' => $required ? $repository->required() : $repository,
            'tenant' => $schema->string()->description('A tenant\'s slug: narrows a list to that tenant, and is needed when a repository is in more than one of your tenants.'),
        ];
    }

    /**
     * The arguments that name a pull request of the repository.
     *
     * @return array<string, Type>
     */
    protected function pullRequestSchema(JsonSchema $schema): array
    {
        return [
            ...$this->repositorySchema($schema),
            'pull_request_id' => $schema->integer()->description('The pull request number. Leave it out and pass `branch` when you only know what is checked out.'),
            'branch' => $schema->string()->description('The branch of the open pull request, such as the output of `git rev-parse --abbrev-ref HEAD`.'),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function pullRequestRules(): array
    {
        return [
            'repository' => ['required', 'string', 'max:500'],
            'tenant' => ['nullable', 'string', 'max:255'],
            'pull_request_id' => ['nullable', 'integer', 'min:1', 'required_without:branch'],
            'branch' => ['nullable', 'string', 'max:255', 'required_without:pull_request_id'],
        ];
    }
}
