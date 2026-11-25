<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\RuleFiles;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TXT'
    The team's review rules for a repository as files for the repository itself, in Laravel Boost's project-rules format: one Markdown file per rule under .ai/rules, with the globs it applies to as `paths` in its frontmatter. In the repository every agent tool reads them, people review them like code, and the AI review reads the ones that apply to a change from the destination branch. With `proposals`, also rules the team's own verdicts suggest: findings judged not a problem or wrong, with the reason, for the user to word.

    To move them into the repository, only after the user agreed: write each file in the checkout (leave out one the user does not want; a proposal is reworded first), run `php artisan boost:index-rules` when the repository has Laravel Boost, commit on a new branch, push, and open the pull request with `create-pull-request`. Once it is merged, an admin turns the rules off in Review Central, so the review does not read them twice.
    TXT)]
class RuleFilesTool extends ReviewTool
{
    protected string $name = 'rules-as-files';

    public function handle(Request $request, RuleFiles $files): Response|ResponseFactory
    {
        $request->validate([
            'repository' => ['required', 'string', 'max:500'],
            'tenant' => ['nullable', 'string', 'max:255'],
            'connection' => ['nullable', 'integer', 'min:1'],
            'proposals' => ['nullable', 'boolean'],
        ]);

        return $this->answer(function () use ($request, $files): array {
            $repository = $this->repository($request);

            return [
                'repository' => $repository->fullName(),
                'directory' => RuleFiles::DIRECTORY,
                'files' => $files->rules($repository),
                'proposals' => $request->boolean('proposals') ? $files->proposals($repository) : [],
            ];
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
