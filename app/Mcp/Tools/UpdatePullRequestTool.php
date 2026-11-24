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
    Rewrites the title and description of one of the user's own pull requests: one their own Bitbucket or GitHub account opened, here or on the host. It refuses someone else's: those words are not the agent's to replace, so suggest the change to the user instead.

    Use it when the change moved on after the pull request was opened, so the description no longer says what the branch does. Write the whole description again, not only what changed, as for `create-pull-request`. Leave the title out to keep it. The AI review's own section in the description is kept as it is; leave it out of what you write.

    Only call it after the user has seen the new title and description and said yes in this conversation: the pull request is under their name.
    TXT)]
class UpdatePullRequestTool extends ReviewTool
{
    protected string $name = 'update-pull-request';

    public function handle(Request $request, PullRequestAuthoring $authoring): Response|ResponseFactory
    {
        $request->validate([
            ...$this->pullRequestRules(),
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:30000'],
        ]);

        return $this->answer(function () use ($request, $authoring): array {
            $repository = $this->repository($request);
            $updated = $authoring->update(
                $this->user($request),
                $repository,
                $request->integer('pull_request_id') ?: null,
                filled($request->get('branch')) ? (string) $request->get('branch') : null,
                filled($request->get('title')) ? (string) $request->get('title') : null,
                (string) $request->get('description'),
            );

            return ['updated' => true, 'repository' => $repository->fullName(), 'pull_request_id' => $updated['id'], 'url' => $updated['url']];
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->pullRequestSchema($schema),
            'title' => $schema->string()->description('The new title, starting with the ticket reference. Leave it out to keep the current one.'),
            'description' => $schema->string()->description('The whole new description, as markdown, without the AI review\'s section. Written as the user: it is under their name.')->required(),
        ];
    }
}
