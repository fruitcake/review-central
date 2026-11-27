<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\PullRequestAuthoring;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TXT'
    Opens a pull request for a pushed branch under the user's own name, with their own Bitbucket account (and the repository's default reviewers), or their GitHub account through the GitHub App. It needs that account connected on their Review Central profile; the error says how when it is not. The repository need not be added to Review Central yet: one in a workspace that one of the user's tenants has a connection for works too.

    Push the branch first. The review grades the pull request against its title and description, so write them for that reader: what changes and why, what to look at first, the risk, the decisions that look like mistakes, and what you noticed but did not change. Scale it to the change: a small fix gets one to three sentences. Put the ticket reference in the title, such as "CRM-123 Guard the scheduler", because that is how the review finds the ticket.

    Only call it after the user has seen the title and description and said yes in this conversation: the pull request is posted under their name, and a review starts on it.

    A branch that already has an open pull request gets an error with its link instead of a second one: use `update-pull-request` for that one.
    TXT)]
class CreatePullRequestTool extends ReviewTool
{
    protected string $name = 'create-pull-request';

    public function handle(Request $request, PullRequestAuthoring $authoring): Response|ResponseFactory
    {
        $request->validate([
            'repository' => ['required', 'string', 'max:500'],
            'tenant' => ['nullable', 'string', 'max:255'],
            'connection' => ['nullable', 'integer', 'min:1'],
            'branch' => ['required', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:30000'],
            'draft' => ['nullable', 'boolean'],
        ]);

        return $this->answer(function () use ($request, $authoring): array {
            $repository = $this->repositoryToAuthor($request);
            $opened = $authoring->create(
                $this->user($request),
                $repository,
                (string) $request->get('branch'),
                filled($request->get('destination')) ? (string) $request->get('destination') : null,
                (string) $request->get('title'),
                (string) $request->get('description'),
                $request->boolean('draft'),
            );

            return [
                'opened' => true,
                'repository' => $repository->fullName(),
                'pull_request_id' => $opened['id'],
                'url' => $opened['url'],
                'next' => 'The AI review starts by itself and takes a few minutes; read it with `pull-request-feedback`.',
            ];
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->repositorySchema($schema),
            'branch' => $schema->string()->description('The pushed branch to merge, such as the output of `git rev-parse --abbrev-ref HEAD`.')->required(),
            'destination' => $schema->string()->description('The branch to merge into. Leave it out for the repository\'s main branch.'),
            'title' => $schema->string()->description('The title, starting with the ticket reference, such as "CRM-123 Guard the scheduler".')->required(),
            'description' => $schema->string()->description('The description, as markdown, written as the user: it is posted under their name.')->required(),
            'draft' => $schema->boolean()->description('Open it as a draft, for work that is not ready to merge yet.'),
        ];
    }
}
