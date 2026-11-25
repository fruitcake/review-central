<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\ActionRefused;
use App\Models\OpenFinding;
use App\Services\OpenFindings;
use App\Services\RepositoryAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TXT'
    Settles findings on the open findings backlog of a repository, as the buttons on its page do. Only an admin of the tenant may.

    `check`: a small model reads each finding's file on the main branch and resolves the finding when the code is gone or fixed. Use it once a fix is on the main branch; it runs in the background, and `open-findings` shows the outcome after.
    `close`: closes the findings under the user's name, with a `reason`, such as "Fixed in pull request 123" or "Accepted: the export stays synchronous". A check never opens them again. Only after the user agreed in this conversation.

    Pass the findings by the `id` that `open-findings` gives. Findings that are already resolved or closed are left as they are.
    TXT)]
class SettleOpenFindingsTool extends ReviewTool
{
    protected string $name = 'settle-open-findings';

    public function handle(Request $request, RepositoryAccess $access, OpenFindings $backlog): Response|ResponseFactory
    {
        $validated = $request->validate([
            'repository' => ['required', 'string', 'max:500'],
            'tenant' => ['nullable', 'string', 'max:255'],
            'connection' => ['nullable', 'integer', 'min:1'],
            'action' => ['required', 'string', 'in:check,close'],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'min:1'],
            'reason' => ['required_if:action,close', 'nullable', 'string', 'max:500'],
        ]);

        return $this->answer(function () use ($request, $validated, $access, $backlog): array {
            $user = $this->user($request);
            $repository = $this->repository($request);
            $access->mustManage($user, $repository->tenant);

            $ids = array_values(array_unique(array_map(intval(...), $validated['ids'])));
            $findings = OpenFinding::query()->where('repository_id', $repository->getKey())->whereKey($ids)->get();
            $unknown = array_values(array_diff($ids, $findings->modelKeys()));

            if ($unknown !== []) {
                throw new ActionRefused("{$repository->fullName()} has no open finding ".implode(', ', $unknown).'; `open-findings` lists its findings with their id.');
            }

            if ($validated['action'] === 'close') {
                $closed = $backlog->close($findings, $validated['reason'], $user->name);

                return ['closed' => $closed, 'left' => $findings->count() - $closed, 'note' => $closed === $findings->count() ? 'Closed.' : 'Those already resolved or closed were left as they are.'];
            }

            $queued = $backlog->queueCheck($findings);

            return ['checking' => $queued, 'left' => $findings->count() - $queued, 'note' => $queued === 0
                ? 'Nothing to check: only an active finding in a file can be checked.'
                : 'Checking against the main branch, in the background: `open-findings` shows the outcome when it is done.'];
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->repositorySchema($schema),
            'action' => $schema->string()->enum(['check', 'close'])->description('`check`: have each checked against the main branch. `close`: close each, with a reason.')->required(),
            'ids' => $schema->array()->items($schema->integer())->description('The findings, by the `id` that `open-findings` gives.')->required(),
            'reason' => $schema->string()->description('For `close`: why, in a line. Shown on the backlog beside the user\'s name.'),
        ];
    }
}
