<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\RepositoryAccess;
use App\Services\ReviewRuleArchive;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TXT'
    Turns off the team's rules for a repository that its main branch now holds in rule files of its own (.ai/rules, .claude/rules, .github/instructions, .cursor/rules), where the review reads them already. A small model reads those files and the rules, and matches them by what they say. Only an admin of the tenant may. A rule for every repository of the tenant is left on.

    It runs by itself after each merge into the repository; use it when the user wants to know now, such as after moving rules into the repository. It says which rules it turned off and in which file it found each; they stay listed, turned off, and turning one on again keeps it on.
    TXT)]
class CheckReviewRulesTool extends ReviewTool
{
    protected string $name = 'check-review-rules';

    public function handle(Request $request, RepositoryAccess $access, ReviewRuleArchive $archive): Response|ResponseFactory
    {
        $request->validate([
            'repository' => ['required', 'string', 'max:500'],
            'tenant' => ['nullable', 'string', 'max:255'],
            'connection' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->answer(function () use ($request, $access, $archive): array {
            $repository = $this->repository($request);
            $access->mustManage($this->user($request), $repository->tenant);

            return ['repository' => $repository->fullName()] + $archive->check($repository, always: true);
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
