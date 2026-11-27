<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\ReviewRunDetails;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TXT'
    Looks into one AI review run, for an admin of the tenant, to see why it found what it found. Read-only. As JSON:
    - the run: reviewer, model, effort, the parts of the review it ran with, the instructions' version, result, summary, findings, what it left for a person, tokens, cost, time and its steps' metrics;
    - what the team judged of its findings, with the reasons;
    - the repository's review settings as a review would start now (models, effort, parts, threshold, follow-up rounds; never a key);
    - the end of its log, where each step of the reviewer is: what it read and ran, and what it concluded;
    - the other runs of the same pull request or branch, by their `run` id.
    Name the run by its id or the address of its page, or name a pull request or branch for its newest run. `log_match` keeps only the log lines that contain a text, such as `-> Bash` for the reviewer's commands. `context` adds the context the run was opened with: the pull request, its comments and the earlier findings, which a trial reviewed as though nobody had did not get.
    TXT)]
class ReviewRunTool extends ReviewTool
{
    protected string $name = 'review-run';

    public function handle(Request $request, ReviewRunDetails $details): Response|ResponseFactory
    {
        $validated = $request->validate([
            'repository' => ['required', 'string', 'max:500'],
            'tenant' => ['nullable', 'string', 'max:255'],
            'connection' => ['nullable', 'integer', 'min:1'],
            'run' => ['nullable', 'string', 'max:500', 'required_without_all:pull_request_id,branch'],
            'pull_request_id' => ['nullable', 'integer', 'min:1'],
            'branch' => ['nullable', 'string', 'max:255'],
            'log_lines' => ['nullable', 'integer', 'min:0', 'max:'.ReviewRunDetails::MAX_LOG_LINES],
            'log_match' => ['nullable', 'string', 'max:200'],
            'context' => ['nullable', 'boolean'],
        ]);

        return $this->answer(fn (): array => $details->of(
            $this->user($request),
            $this->repository($request),
            $validated['run'] ?? null,
            isset($validated['pull_request_id']) ? (int) $validated['pull_request_id'] : null,
            $validated['branch'] ?? null,
            (int) ($validated['log_lines'] ?? 300),
            $validated['log_match'] ?? null,
            (bool) ($validated['context'] ?? false),
        ));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->repositorySchema($schema),
            'run' => $schema->string()->description('The run\'s id, or the address of its page. Leave it out for the newest run of the pull request or branch.'),
            'pull_request_id' => $schema->integer()->description('The pull request whose newest run to read, when no run is named.'),
            'branch' => $schema->string()->description('The branch whose newest run to read, when no run or pull request is named.'),
            'log_lines' => $schema->integer()->description('How many of the log\'s last lines, 300 unless set; 0 for none, at most 5000.'),
            'log_match' => $schema->string()->description('Only the log lines that contain this, case-insensitively, such as `-> Bash` or `phase`.'),
            'context' => $schema->boolean()->description('Also the context the run was opened with. Off by default: it is long.'),
        ];
    }
}
