<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\TicketLookup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TXT'
    Reads a ticket as the AI review reads it, from the repository's ticket sources (Jira, Asana, GitHub Issues): its title, description, acceptance criteria and latest comments as markdown, with its address.

    The review grades a pull request against the ticket its title names, so read it before building or pushing a change: list each acceptance criterion and where the change meets it.

    Pass `reference` (PROJ-123, #1234) for a ticket by itself, before there is a pull request, or a pull request (`pull_request_id` or `branch`) for the ticket its title names now.
    TXT)]
class TicketTool extends ReviewTool
{
    protected string $name = 'ticket';

    public function handle(Request $request, TicketLookup $tickets): Response|ResponseFactory
    {
        $validated = $request->validate([
            'repository' => ['required', 'string', 'max:500'],
            'tenant' => ['nullable', 'string', 'max:255'],
            'connection' => ['nullable', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:100', 'required_without_all:pull_request_id,branch'],
            'pull_request_id' => ['nullable', 'integer', 'min:1'],
            'branch' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->answer(fn (): array => filled($validated['reference'] ?? null)
            ? $tickets->byReference($this->repository($request), (string) $validated['reference'])
            : $tickets->forPullRequest(
                $this->repository($request),
                isset($validated['pull_request_id']) ? (int) $validated['pull_request_id'] : null,
                $validated['branch'] ?? null,
            ));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->pullRequestSchema($schema),
            'reference' => $schema->string()->description('The ticket, as a pull request title would name it: PROJ-123, or #1234. Leave it out for the ticket of the pull request.'),
        ];
    }
}
