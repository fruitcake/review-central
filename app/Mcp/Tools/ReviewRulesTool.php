<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\RepositoryAccess;
use App\Services\ReviewRules;
use App\Services\RuleProposals;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TXT'
    Lists the team's own rules the AI review of a repository reads, as JSON: checks it reports a change for breaking (labelled R1, R2 …) and conventions it does not report because the team judged them not to be a defect here (C1, C2 …), each with why, the paths it is limited to and the severity a broken check gets. Also says whether you may add rules.

    Read it before writing code in the repository, and before calling `add-review-rule`, so a rule is not added twice.

    With `proposals`, also rules the team's verdicts suggest: findings judged not a problem or wrong, with the reason, that no rule covers yet. A proposal is for the user to word; never a rule by itself.

    To move rules into the repository, only after the user agreed: write each as a Laravel Boost project rule, `.ai/rules/<slug>.md`, with its paths as `paths:` in the frontmatter (`**` for every file), the title as heading, whether breaking it is a defect (a check, with its severity) or not a defect here (a convention), and the why. Leave out a rule for every repository, which holds for the others too, unless the user wants it here as well. Run `php artisan boost:index-rules` when the repository has Laravel Boost, commit on a new branch, push and open the pull request with `create-pull-request`. Once it is merged, the review turns off the rules it finds in those files by itself, so it does not read them twice.
    TXT)]
class ReviewRulesTool extends ReviewTool
{
    protected string $name = 'review-rules';

    public function handle(Request $request, RepositoryAccess $access, RuleProposals $proposals): Response|ResponseFactory
    {
        $request->validate(['repository' => ['required', 'string', 'max:500'], 'tenant' => ['nullable', 'string', 'max:255'], 'connection' => ['nullable', 'integer', 'min:1'], 'proposals' => ['nullable', 'boolean']]);

        return $this->answer(function () use ($request, $access, $proposals): array {
            $repository = $this->repository($request);

            return [
                'repository' => $repository->fullName(),
                'you_may_add_rules' => $access->manages($this->user($request), $repository->tenant),
                'rules' => ReviewRules::described($repository),
            ] + ($request->boolean('proposals') ? ['proposals' => $proposals->for($repository)] : []);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->repositorySchema($schema) + [
            'proposals' => $schema->boolean()->description('Also rules the team\'s verdicts on findings suggest, for the user to word.'),
        ];
    }
}
