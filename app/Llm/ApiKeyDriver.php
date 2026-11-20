<?php

declare(strict_types=1);

namespace App\Llm;

use App\Connections\ExplainsSetup;
use App\Connections\Fields\Secret;
use App\Connections\LlmDriver;
use App\Exceptions\ConnectionFailed;
use App\Exceptions\ReviewerFailed;
use App\Filament\Components\ReviewSettingsForm;
use App\Llm\Agents\Classifier;
use App\Llm\Agents\Decider;
use App\Llm\Agents\Describer;
use App\Llm\Agents\DiffReviewer;
use App\Llm\Agents\FindingChecker;
use App\Models\Connection;
use App\Runners\Diff\Answer;
use App\Runners\Diff\ReportSchema;
use App\Services\ReviewSettings;
use App\Support\SetupGuide;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\AgentResponse;
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
abstract class ApiKeyDriver implements ExplainsSetup, LlmDriver
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

    /** The page of the provider's console where a key is made. */
    abstract protected function keyPage(): string;

    /**
     * What is worth knowing about this provider's keys besides the steps, such
     * as a key that can be limited to some endpoints.
     *
     * @return list<string>
     */
    protected function keyNotes(): array
    {
        return [];
    }

    /**
     * The same few steps for each: a key is all these providers ask for, and
     * none of them has scopes beyond the ones keyNotes() names. The key lists
     * the models, which is what "Test" asks, and asks one for an answer.
     */
    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            title: "A {$this->label()} API key",
            url: $this->keyPage(),
            linkLabel: "API keys in the {$this->label()} console",
            steps: [
                "Sign in at {$this->label()} and open the link. A new account may first need a payment method or credits before its keys work.",
                'Create a key and name it after this installation, so it can be told apart and revoked later.',
                'Copy the key, which is shown once, and paste it here.',
                "Under Settings, fill in the model to review with, as {$this->label()} names it.",
            ],
            notes: [
                ...$this->keyNotes(),
                "The review from the diff runs on this server with the key, and Pi in a container gets it as `{$this->variable()}`.",
            ],
        );
    }

    public function credentialFields(): array
    {
        return [
            Secret::make('api_key')->label('API key'),
        ];
    }

    public function settingFields(): array
    {
        return [
            ReviewSettingsForm::modelField('model', $this->modelChoices())
                ->label('Model')
                ->required()
                ->helperText("The model to review with: one {$this->label()} names, or its smartest, default or cheapest as the Laravel AI SDK names it when the review runs. A trial can name another."),
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
            'PI_REVIEW_MODEL_'.strtoupper($this->pi()) => $this->model($connection),
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
        $model = $this->model($connection);

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

    /**
     * The model the connection names: a tier as the SDK reads it now.
     */
    protected function model(Connection $connection): string
    {
        return ReviewSettings::model($this->sdk(), (string) $connection->setting('model'));
    }

    /**
     * The tiers, as they follow the SDK, then the models each is now.
     *
     * @return array<string, string>
     */
    protected function modelChoices(): array
    {
        $models = ReviewSettings::tierModels($this->sdk());
        $choices = [];

        foreach ($models as $tier => $model) {
            $choices[$tier] = "{$tier} (Laravel AI): now {$model}";
        }

        foreach ($models as $tier => $model) {
            $choices[$model] ??= "{$model} ({$tier})";
        }

        return $choices;
    }

    public function decides(Connection $connection, string $question, string $text): ?bool
    {
        $answer = (new Decider($question))->prompt(
            $text,
            provider: $this->provider($connection, ['driver' => $this->sdk(), 'key' => (string) $connection->credential('api_key')]),
            model: $this->model($connection),
            timeout: 30,
        );

        return is_bool($answer['answer'] ?? null) ? $answer['answer'] : null;
    }

    public function describes(Connection $connection, string $instructions, string $text, ?string $model = null): ?array
    {
        $answer = (new Describer($instructions))->prompt(
            $text,
            provider: $this->provider($connection, ['driver' => $this->sdk(), 'key' => (string) $connection->credential('api_key')]),
            model: $model === null ? $this->model($connection) : ReviewSettings::model($this->sdk(), $model),
            timeout: 60,
        );

        return Describer::read($answer);
    }

    public function classifies(Connection $connection, string $text): AgentResponse
    {
        return (new Classifier)->prompt(
            $text,
            provider: $this->provider($connection, ['driver' => $this->sdk(), 'key' => (string) $connection->credential('api_key')]),
            model: $this->model($connection),
            timeout: 30,
        );
    }

    public function checks(Connection $connection, string $text): AgentResponse
    {
        return (new FindingChecker)->prompt(
            $text,
            provider: $this->provider($connection, ['driver' => $this->sdk(), 'key' => (string) $connection->credential('api_key')]),
            model: $this->model($connection),
            timeout: 60,
        );
    }

    /**
     * A request with the key as a bearer token, which is how most of them take it.
     */
    protected function bearer(Connection $connection, string $url): Response
    {
        return Http::withToken((string) $connection->credential('api_key'))->acceptJson()->timeout(15)->get($url);
    }
}
