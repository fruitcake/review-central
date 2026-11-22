<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\ReviewRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TXT'
    Starts the AI review of a pull request again, as it is now, without a push and without a comment on the pull request. It is the pull request's own next review: it reads the replies left under its findings since the last one, and posts its result as a push would.

    A push already starts a review. This is for when nothing was pushed but the user replied under findings, and the review should read those replies now. It is refused when the newest review covers the head and nobody commented since: a thread that was only resolved is read at the next push. Only call it after the user said yes to it in this conversation: a review costs money and posts on the pull request.

    It takes a few minutes. Read the result with `pull-request-feedback` once its `review` is the new one.
    TXT)]
class RequestReviewTool extends ReviewTool
{
    protected string $name = 'request-review';

    public function handle(Request $request, ReviewRequest $reviews): Response|ResponseFactory
    {
        $request->validate($this->pullRequestRules());

        return $this->answer(function () use ($request, $reviews): array {
            $repository = $this->repository($request);
            $started = $reviews->request($repository, $request->integer('pull_request_id') ?: null, $request->get('branch'));

            return [
                'started' => true,
                'repository' => $repository->fullName(),
                'pull_request_id' => $started['id'],
                'branch' => $started['branch'],
                'next' => 'Read the result with `pull-request-feedback` in a few minutes.',
            ];
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->pullRequestSchema($schema);
    }
}
