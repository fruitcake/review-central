<?php

declare(strict_types=1);

namespace App\Llm;

use App\Connections\Fields\Secret;
use App\Connections\LlmDriver;
use App\Exceptions\ConnectionFailed;
use App\Exceptions\ReviewerFailed;
use App\Llm\Agents\Decider;
use App\Llm\Agents\DiffReviewer;
use App\Models\Connection;
use App\Runners\Diff\Answer;
use App\Runners\Diff\ReportSchema;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use Throwable;

/**
 * An AI provider that is a key and a model: one the Laravel AI SDK can ask from
 * this server, and Pi can review with in a container.
 *
 * The key reaches a reviewer as the provider's own variable, and the model as
 * `PI_REVIEW_MODEL_<PROVIDER>`, which run-pi.sh reads when a review names none.
 * Claude Code, Codex and OpenCodeReview do not review with these; Pi does, and so
 * does the review from the diff.
 */
abstract class ApiKeyDriver implements LlmDriver
{
    use AsksProviders;

    /** The provider as the Laravel AI SDK names its driver. */
    abstract protected function sdk(): string;

    /** The provider as Pi names it. */
    abstract protected function pi(): string;

    /** The variable its key is read from, by Pi and by anything else that speaks to it. */
    abstract protected function variable(): string;

    /** A cheap request that only a working key gets an answer to. */
    abstract protected function ask(Connection $connection): Response;

    public function credentialFields(): array
    {
        return [
            Secret::make('api_key')->label('API key'),
        ];
    }

    public function settingFields(): array
    {
        return [
            TextInput::make('model')->required()->maxLength(120)->rule('regex:/^[A-Za-z0-9._:\/@-]+$/')
                ->helperText("The model to review with, as {$this->label()} names it. A trial can name another."),
        ];
    }

    public function test(Connection $connection): void
    {
        $response = $this->ask($connection);

        if (! $response->successful()) {
            throw new ConnectionFailed("{$this->label()} answered {$response->status()}: check the API key.");
        }
    }

    public function environment(Connection $connection): array
    {
        return array_filter([
            $this->variable() => $connection->credential('api_key'),
            'PI_REVIEW_MODEL_'.strtoupper($this->pi()) => $connection->setting('model'),
        ]);
    }

    /**
     * None of the reviewers a repository can be set to: Pi, which a trial asks for.
     */
    public function reviewers(): array
    {
        return [];
    }

    public function review(Connection $connection, string $instructions, string $material, ?string $model = null, ?string $effort = null): Answer
    {
        // The repository's setting names a model of Claude's or Codex's; this
        // provider reviews with its own.
        $model = (string) $connection->setting('model');

        // No two of these take a schema the same way, so the report is asked for
        // in words and held to the schema's shape by what reads it.
        $agent = new DiffReviewer($instructions."\n\n## The answer\n\nAnswer with one JSON object and nothing else: no words before or after it, no code fence. It has to be valid against this JSON Schema:\n\n".json_encode(ReportSchema::closed(), JSON_UNESCAPED_SLASHES), []);

        try {
            $response = $agent->prompt($material, provider: $this->provider($connection, ['driver' => $this->sdk(), 'key' => (string) $connection->credential('api_key')]), model: $model, timeout: (int) config('review.diff.timeout'));
        } catch (Throwable $exception) {
            throw $this->failure($exception);
        }

        $model = $response->meta->model ?: $model;
        $stop = $response->steps->last()?->finishReason;

        if ($stop === FinishReason::ContentFilter) {
            throw new ReviewerFailed("{$model} declined to review this pull request.");
        }

        if ($stop === FinishReason::Length) {
            throw new ReviewerFailed("{$model} ran out of room before the report was complete.");
        }

        // A model that fences its answer anyway is taken at its JSON.
        $text = (string) preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/', '', $response->text);
        $report = json_decode($text, true);

        if (! is_array($report) || array_is_list($report) || ! is_array($report['findings'] ?? null)) {
            throw new ReviewerFailed("{$model} answered with something that is not a report.");
        }

        return new Answer(ReportSchema::withoutNulls($report), $model, $this->usage($response->usage));
    }

    public function decides(Connection $connection, string $question, string $text): ?bool
    {
        $answer = (new Decider($question))->prompt(
            $text,
            provider: $this->provider($connection, ['driver' => $this->sdk(), 'key' => (string) $connection->credential('api_key')]),
            model: (string) $connection->setting('model'),
            timeout: 30,
        );

        return is_bool($answer['answer'] ?? null) ? $answer['answer'] : null;
    }

    /**
     * A request with the key as a bearer token, which is how most of them take it.
     */
    protected function bearer(Connection $connection, string $url): Response
    {
        return Http::withToken((string) $connection->credential('api_key'))->acceptJson()->timeout(15)->get($url);
    }
}
