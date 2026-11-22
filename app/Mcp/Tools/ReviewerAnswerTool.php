<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\RepositoryNotFound;
use App\Models\ReviewQuestion;
use App\Services\PullRequestFeedback;
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
    Reads a question asked of the AI reviewer, with its status and, once it is answered, the answer. `asked` and `unsure` mean it is still being answered: wait a minute and read it again. `failed` means the reviewer gave none.
    TXT)]
class ReviewerAnswerTool extends ReviewTool
{
    protected string $name = 'reviewer-answer';

    public function handle(Request $request, RepositoryAccess $access): Response|ResponseFactory
    {
        $validated = $request->validate(['question_id' => ['required', 'integer', 'min:1']]);

        return $this->answer(function () use ($request, $validated, $access): array {
            $question = ReviewQuestion::query()
                ->whereKey($validated['question_id'])
                ->whereIn('repository_id', $access->repositories($this->user($request))->withTrashed()->select('id'))
                ->first();

            return PullRequestFeedback::question($question ?? throw new RepositoryNotFound("There is no question {$validated['question_id']} among yours."));
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'question_id' => $schema->integer()->description('The question\'s id, as `ask-reviewer` or `pull-request-feedback` gave it.')->required(),
        ];
    }
}
