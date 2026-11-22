<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\RepositoryAccess;
use App\Services\ReviewRules;
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
    TXT)]
class ReviewRulesTool extends ReviewTool
{
    protected string $name = 'review-rules';

    public function handle(Request $request, RepositoryAccess $access): Response|ResponseFactory
    {
        $request->validate(['repository' => ['required', 'string', 'max:500'], 'tenant' => ['nullable', 'string', 'max:255'], 'connection' => ['nullable', 'integer', 'min:1']]);

        return $this->answer(function () use ($request, $access): array {
            $repository = $this->repository($request);

            return [
                'repository' => $repository->fullName(),
                'you_may_add_rules' => $access->manages($this->user($request), $repository->tenant),
                'rules' => ReviewRules::described($repository),
            ];
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->repositorySchema($schema);
    }
}
