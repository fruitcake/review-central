<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\OpenFindings;
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
    Lists the open findings backlog, as JSON: findings a pull request was merged with while its review still had them open, kept until a check finds them resolved on the main branch or someone closes them. Each has its file and line, severity, what it says, the pull request it came from and what the last check said (`present`: still there, `unclear`, or `open`: not checked yet).

    Use it to pick up technical debt, or to see whether the code you are about to change already has a known finding: narrow it with `path`.
    TXT)]
class OpenFindingsTool extends ReviewTool
{
    protected string $name = 'open-findings';

    public function handle(Request $request, RepositoryAccess $access, OpenFindings $findings): Response|ResponseFactory
    {
        $validated = $request->validate([
            'repository' => ['nullable', 'string', 'max:500'],
            'tenant' => ['nullable', 'string', 'max:255'],
            'connection' => ['nullable', 'integer', 'min:1'],
            'path' => ['nullable', 'string', 'max:500'],
            'closed' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->answer(function () use ($request, $validated, $access, $findings): array {
            $repositories = $access->scoped($this->user($request), $validated['repository'] ?? null, $validated['tenant'] ?? null, $this->connection($request));

            return ['findings' => $findings->listed($repositories, (bool) ($validated['closed'] ?? false), $validated['path'] ?? null, (int) ($validated['limit'] ?? 50))];
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->repositorySchema($schema, required: false),
            'path' => $schema->string()->description('Only findings in files under this path, such as app/Services/.'),
            'closed' => $schema->boolean()->description('Also those resolved or closed. Off by default.'),
            'limit' => $schema->integer()->description('At most this many, newest first. 50 by default.'),
        ];
    }
}
