<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\ReviewRuleKind;
use App\Exceptions\ActionRefused;
use App\Services\RepositoryAccess;
use App\Services\ReviewRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TXT'
    Adds a rule the AI review reads on every review from now on: a `check` it reports a change for breaking, or a `convention`, a pattern it stops reporting because it is not a defect in this codebase. Only an admin of the tenant may; `review-rules` says whether you are.

    Only call it after the user agreed to the rule's exact wording in this conversation: a rule changes every later review of the repository, or of all the tenant's with `all_repositories`. Read `review-rules` first so it is not there already.

    Write the title as the rule in one line, and `body` as why and what counts, for the reviewer to judge a case by. Limit it with `paths` (globs, such as app/Models/**) where it holds only there. A rule made from a finding the team judged a false positive is a `convention`; pass that finding as `review_id` and `finding_id`, so the rule records where it came from.
    TXT)]
class AddReviewRuleTool extends ReviewTool
{
    protected string $name = 'add-review-rule';

    public function handle(Request $request, ReviewRules $rules, RepositoryAccess $access): Response|ResponseFactory
    {
        $validated = $request->validate([
            'repository' => ['required', 'string', 'max:500'],
            'tenant' => ['nullable', 'string', 'max:255'],
            'all_repositories' => ['nullable', 'boolean'],
            'kind' => ['required', Rule::enum(ReviewRuleKind::class)],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:5000'],
            'paths' => ['nullable', 'array', 'max:20'],
            'paths.*' => ['string', 'max:255'],
            'severity' => ['nullable', 'string', Rule::in(['HIGH', 'MEDIUM', 'LOW', 'high', 'medium', 'low'])],
            'review_id' => ['nullable', 'string', 'max:64', 'required_with:finding_id'],
            'finding_id' => ['nullable', 'string', 'max:64', 'required_with:review_id'],
        ]);

        return $this->answer(function () use ($request, $validated, $rules, $access): array {
            $user = $this->user($request);
            $repository = $this->repository($request);
            $source = null;

            if (isset($validated['review_id'])) {
                $run = $access->run($user, $validated['review_id']);

                if ($run->repository_id !== $repository->getKey()) {
                    throw new ActionRefused('That review is of another repository.');
                }

                $source = "{$run->uuid}:{$validated['finding_id']}";
            }

            $rule = $rules->add(
                user: $user,
                tenant: $repository->tenant,
                repository: ($validated['all_repositories'] ?? false) ? null : $repository,
                kind: ReviewRuleKind::from($validated['kind']),
                title: $validated['title'],
                body: $validated['body'] ?? null,
                paths: $validated['paths'] ?? null,
                severity: $validated['severity'] ?? null,
                source: $source,
            );

            return [
                'saved' => true,
                'rule' => collect(ReviewRules::described($repository))->firstWhere('id', $rule->getKey()),
                'note' => 'The next review of '.(($validated['all_repositories'] ?? false) ? "every repository of {$repository->tenant->name}" : $repository->fullName()).' reads it.',
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
            'all_repositories' => $schema->boolean()->description('For every repository of the tenant rather than this one alone. Off by default.'),
            'kind' => $schema->string()->enum(['check', 'convention'])->description('`check`: report a change that breaks it. `convention`: not a defect here, do not report it.')->required(),
            'title' => $schema->string()->description('The rule in one line: the heading the review cites.')->required(),
            'body' => $schema->string()->description('Why, and what counts, in a few sentences.'),
            'paths' => $schema->array()->items($schema->string())->description('Globs the rule is limited to, such as ["app/Models/**"]. Leave out for every file.'),
            'severity' => $schema->string()->enum(['HIGH', 'MEDIUM', 'LOW'])->description('For a check: the severity a change that breaks it gets. Leave out for the review to judge.'),
            'review_id' => $schema->string()->description('The review whose finding the rule is made from, as `pull-request-feedback` gives its id.'),
            'finding_id' => $schema->string()->description('That finding\'s id, such as F2.'),
        ];
    }
}
