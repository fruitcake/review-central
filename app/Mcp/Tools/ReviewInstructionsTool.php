<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\ReviewInstructions;
use App\Services\ReviewStack;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TXT'
    Returns, as one markdown document, the instructions the AI review of a repository reads: its method, the guide for each stack, the team's own instructions and the team's rules (checks to report and patterns that are not a defect here).

    Read it to review a change the way the pull request's review will, before committing or pushing, so the review finds nothing you could have found yourself. The rules carry the labels (R1, C1) the review cites them by.

    The stacks are the repository's as Review Central knows them; pass `stacks` to choose them yourself, such as ["laravel"].
    TXT)]
class ReviewInstructionsTool extends ReviewTool
{
    protected string $name = 'review-instructions';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'repository' => ['required', 'string', 'max:500'],
            'tenant' => ['nullable', 'string', 'max:255'],
            'connection' => ['nullable', 'integer', 'min:1'],
            'stacks' => ['nullable', 'array'],
            'stacks.*' => ['string', 'max:40'],
        ]);

        return $this->answer(function () use ($request, $validated): string {
            $repository = $this->repository($request);
            $stacks = array_key_exists('stacks', $validated) ? array_values(array_intersect(ReviewStack::known($repository->tenant), $validated['stacks'] ?? [])) : ReviewStack::forRepository($repository);
            $files = ReviewInstructions::forRepository($repository, $stacks)['files'] ?? ReviewInstructions::bundled($stacks);

            return $files === null
                ? 'There are no review instructions for this repository yet.'
                : 'Stacks: '.($stacks === [] ? 'none' : implode(', ', $stacks)).".\n\n".ReviewInstructions::markdown($files);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->repositorySchema($schema),
            'stacks' => $schema->array()->items($schema->string())->description('The stacks to read the guides for, such as ["laravel"]. Leave out for the repository\'s own.'),
        ];
    }
}
