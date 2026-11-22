<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\FindingVerdictKind;
use App\Services\FindingFeedback;
use App\Services\RepositoryAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description(<<<'TXT'
    Records what the user thinks of one finding of a review, under their own name, as on the review's page: `valid` (right, and it will be fixed), `not_a_problem` (true, but not worth changing here) or `wrong` (factually wrong). `none` takes their verdict back.

    The team's verdicts measure how good the review is, and a finding judged not a problem or wrong can be made a rule. So record the user's judgement, not your own: when they decided to fix a finding, or told you why it does not hold. Saying the same again changes nothing.

    Take `review_id` and `finding_id` from `pull-request-feedback`.
    TXT)]
class JudgeFindingTool extends ReviewTool
{
    protected string $name = 'judge-finding';

    public function handle(Request $request, RepositoryAccess $access, FindingFeedback $feedback): Response|ResponseFactory
    {
        $validated = $request->validate([
            'review_id' => ['required', 'string', 'max:64'],
            'finding_id' => ['required', 'string', 'max:64'],
            'verdict' => ['required', Rule::in([...array_column(FindingVerdictKind::cases(), 'value'), 'none'])],
        ]);

        return $this->answer(function () use ($request, $validated, $access, $feedback): array {
            $verdict = FindingVerdictKind::tryFrom($validated['verdict']);
            $feedback->set($access->run($this->user($request), $validated['review_id']), $this->user($request), $validated['finding_id'], $verdict);

            return ['finding_id' => $validated['finding_id'], 'your_verdict' => $verdict?->value, 'meaning' => $verdict?->text()];
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'review_id' => $schema->string()->description('The review\'s id, from `pull-request-feedback`.')->required(),
            'finding_id' => $schema->string()->description('The finding\'s id, such as F1.')->required(),
            'verdict' => $schema->string()->enum(['valid', 'not_a_problem', 'wrong', 'none'])->description('valid, not_a_problem, wrong, or none to take it back.')->required(),
        ];
    }
}
