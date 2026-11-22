<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\ActionRefused;
use App\Services\PullRequestFeedback;
use App\Services\RepositoryAccess;
use App\Services\ReviewQuestions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TXT'
    Asks the AI reviewer a question about a review of a pull request, in the session of that pull request's last review, so it knows the code and its findings. Nothing is said on the pull request: the answer is kept with the review, under the user's name, and shows on its page.

    Use it when a finding is unclear: what exactly it means, where it comes from, or whether a fix you have in mind would settle it. It costs a model call, so ask what you could not find out by reading the code.

    The answer takes a minute or a few. Read it with `reviewer-answer` and the question's id.
    TXT)]
class AskReviewerTool extends ReviewTool
{
    protected string $name = 'ask-reviewer';

    public function handle(Request $request, RepositoryAccess $access, ReviewQuestions $questions): Response|ResponseFactory
    {
        $validated = $request->validate([
            'review_id' => ['required', 'string', 'max:64'],
            'question' => ['required', 'string', 'max:4000'],
        ]);

        return $this->answer(function () use ($request, $validated, $access, $questions): array {
            $asked = $questions->askInPanel($access->run($this->user($request), $validated['review_id']), $this->user($request), $validated['question']);

            if (is_string($asked)) {
                throw new ActionRefused($asked);
            }

            return [...PullRequestFeedback::question($asked), 'next' => 'Read the answer with `reviewer-answer` in a minute or two.'];
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'review_id' => $schema->string()->description('The review\'s id, from `pull-request-feedback`.')->required(),
            'question' => $schema->string()->description('The question, in plain words. Name the finding (such as F2) when it is about one.')->required(),
        ];
    }
}
