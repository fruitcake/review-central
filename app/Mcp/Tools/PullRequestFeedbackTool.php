<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\PullRequestFeedback;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TXT'
    Reads a pull request as its AI review left it, as JSON: whose turn it is and why, the newest review (its summary, result, whether it reviewed the current head, and what it left for a person to check), every finding with its id, severity, status, file and line, whether it holds the merge, what people in the team judged it, and the replies in its thread on the pull request; then the questions asked of the reviewer with their answers, and the earlier reviews.

    Use it before addressing review findings, for "what is still open on this PR", and after a review you asked for to see its result. Findings are ordered as they should be worked on: those that hold the merge first, then other open ones, then settled ones.

    Pass `comments: true` to also get every comment people left on the pull request.
    TXT)]
class PullRequestFeedbackTool extends ReviewTool
{
    protected string $name = 'pull-request-feedback';

    public function handle(Request $request, PullRequestFeedback $feedback): Response|ResponseFactory
    {
        $validated = $request->validate([...$this->pullRequestRules(), 'comments' => ['nullable', 'boolean']]);

        return $this->answer(fn (): array => $feedback->of(
            $this->user($request),
            $this->repository($request),
            isset($validated['pull_request_id']) ? (int) $validated['pull_request_id'] : null,
            $validated['branch'] ?? null,
            (bool) ($validated['comments'] ?? false),
        ));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->pullRequestSchema($schema),
            'comments' => $schema->boolean()->description('Also every comment people left on the pull request, besides the replies to findings. Off by default.'),
        ];
    }
}
