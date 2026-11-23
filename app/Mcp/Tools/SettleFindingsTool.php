<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\FindingVerdictReason;
use App\Services\FindingSettlement;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TXT'
    Settles findings of a pull request's newest review in one call, under the user's own name, as on the review's page. For each finding: the `verdict` (`valid`: right, and it will be or was fixed; `not_a_problem`: true, but not worth changing here; `wrong`: factually wrong; `none` takes the verdict back), for a dismissed one the `reason` and a short `note`, and where the review should hear why, a `reply`.

    Reasons: nitpick (too small to matter), out_of_scope (not for this change), intended (the behaviour or trade-off is deliberate), handled_elsewhere (a guard, caller or test the reviewer did not see), misread (the reviewer misread the code or the change), duplicate (the same as another finding), other (say why in the note).

    The verdicts and reasons are the team's statistics of how good the review is, so give the user's judgement, not your own. A reply is posted in the finding's thread on the pull request: on Bitbucket as the user, where they connected their own account on their profile, else by Review Central with the user's name above it; each finding's `replied_as` says which. The next review reads it. A finding that was fixed needs no reply: the next review sees the fix. Reply to a finding that is wrong or not worth it, with the evidence (file:line) in one to three sentences.

    All verdicts are checked first: one unknown finding or bad reason records nothing. A reply that cannot be posted is reported per finding; the rest still are. Take `review_id` and the finding ids from `pull-request-feedback`.
    TXT)]
class SettleFindingsTool extends ReviewTool
{
    protected string $name = 'settle-findings';

    public function handle(Request $request, FindingSettlement $settlement): Response|ResponseFactory
    {
        $validated = $request->validate([
            ...$this->pullRequestRules(),
            'review_id' => ['required', 'string', 'max:64'],
            'findings' => ['required', 'array', 'min:1', 'max:50'],
            'findings.*.finding_id' => ['required', 'string', 'max:64', 'distinct'],
            'findings.*.verdict' => ['required', Rule::in(['valid', 'not_a_problem', 'wrong', 'none'])],
            'findings.*.reason' => ['nullable', Rule::in(array_column(FindingVerdictReason::cases(), 'value'))],
            'findings.*.note' => ['nullable', 'string', 'max:1000'],
            'findings.*.reply' => ['nullable', 'string', 'max:4000'],
        ]);

        return $this->answer(fn (): array => $settlement->settle(
            $this->user($request),
            $this->repository($request),
            isset($validated['pull_request_id']) ? (int) $validated['pull_request_id'] : null,
            $validated['branch'] ?? null,
            (string) $validated['review_id'],
            array_values($validated['findings']),
        ));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->pullRequestSchema($schema),
            'review_id' => $schema->string()->description('The id of the review the findings were read from, `review.id` in `pull-request-feedback`. Refused when a newer review has come since.')->required(),
            'findings' => $schema->array()->items($schema->object([
                'finding_id' => $schema->string()->description('The finding\'s id, such as F1.')->required(),
                'verdict' => $schema->string()->enum(['valid', 'not_a_problem', 'wrong', 'none'])->required(),
                'reason' => $schema->string()->enum(array_column(FindingVerdictReason::cases(), 'value'))->description('Why it was dismissed; only with not_a_problem or wrong.'),
                'note' => $schema->string()->description('A sentence on why it was dismissed, kept with the verdict for the team.'),
                'reply' => $schema->string()->description('A reply in the finding\'s thread on the pull request, which the next review reads. Leave out for a finding that was fixed.'),
            ]))->description('The findings to settle, each once.')->required(),
        ];
    }
}
