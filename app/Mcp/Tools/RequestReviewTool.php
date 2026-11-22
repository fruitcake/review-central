<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\ActionRefused;
use App\Services\PullRequestFeedback;
use App\Services\ReviewRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use RuntimeException;
use Throwable;

#[Description(<<<'TXT'
    Starts the AI review of a pull request again, as it is now, without a push and without a comment on the pull request. It is the pull request's own next review: it reads the replies left on its findings since the last one, and posts its result as a push would.

    A push already starts a review. This is for when nothing was pushed: the user replied to findings, or settled them, and the review should take that in now. Only call it after the user said yes to it in this conversation: a review costs money and posts on the pull request.

    It takes a few minutes. Read the result with `pull-request-feedback` once its `review.status` is finished.
    TXT)]
class RequestReviewTool extends ReviewTool
{
    protected string $name = 'request-review';

    public function handle(Request $request, PullRequestFeedback $feedback, ReviewRequest $reviews): Response|ResponseFactory
    {
        $request->validate($this->pullRequestRules());

        return $this->answer(function () use ($request, $feedback, $reviews): array {
            $repository = $this->repository($request);
            $pullRequest = $feedback->resolve($repository, $request->integer('pull_request_id') ?: null, $request->get('branch'));

            if ($pullRequest['record'] === null || blank($pullRequest['branch'])) {
                throw new ActionRefused("Pull request #{$pullRequest['id']} is not open, as far as Review Central knows: only an open pull request is reviewed.");
            }

            try {
                $reviews->start($repository, $pullRequest['branch'], strict: true);
            } catch (RuntimeException $exception) {
                throw new ActionRefused($exception->getMessage());
            } catch (Throwable $exception) {
                report($exception);

                throw new ActionRefused('The review could not be started: '.$exception->getMessage());
            }

            return [
                'started' => true,
                'repository' => $repository->fullName(),
                'pull_request_id' => $pullRequest['id'],
                'branch' => $pullRequest['branch'],
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
