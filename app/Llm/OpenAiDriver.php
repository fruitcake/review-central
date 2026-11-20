<?php

declare(strict_types=1);

namespace App\Llm;

use App\Connections\ExplainsSetup;
use App\Connections\Fields\Secret;
use App\Connections\LlmDriver;
use App\Enums\ReviewProvider;
use App\Exceptions\ConnectionFailed;
use App\Exceptions\ReviewerFailed;
use App\Llm\Agents\Classifier;
use App\Llm\Agents\Describer;
use App\Llm\Agents\DiffReviewer;
use App\Models\Connection;
use App\Runners\Diff\Answer;
use App\Runners\Diff\ReportSchema;
use App\Services\ReviewSettings;
use App\Support\SetupGuide;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\FinishReason;
use Throwable;

class OpenAiDriver implements ExplainsSetup, LlmDriver
{
    use AsksProviders;

    public function label(): string
    {
        return 'OpenAI';
    }

    public function credentialFields(): array
    {
        return [
            Secret::make('api_key')->label('API key'),
        ];
    }

    public function settingFields(): array
    {
        return [];
    }

    /**
     * An OpenAI key may be restricted. Codex and the review from the diff call
     * the Responses API, and "Test" lists the models, so a restricted key needs
     * both of those.
     */
    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            title: 'An OpenAI API key',
            url: 'https://platform.openai.com/api-keys',
            linkLabel: 'API keys on the OpenAI platform',
            steps: [
                'Sign in to the OpenAI platform and open the link. The organisation needs credits or a payment method under **Billing** before a key works.',
                'Choose **Create new secret key**, pick the project the reviews should be billed to, and name it after this installation.',
                'Leave the permissions at **All**, or choose **Restricted** and set the permissions below.',
                'Copy the key, which is shown once, and paste it here.',
            ],
            permissions: [
                'Models: Read' => 'lists the models, which is what **Test** asks.',
                'Model capabilities: Write' => 'asks a model for an answer.',
                'Responses API: Write' => 'what Codex and the review from the diff review through, where the console lists it apart.',
            ],
            notes: [
                'A project of its own for the reviews, with a budget, keeps them apart from the rest of what the organisation spends.',
            ],
        );
    }

    public function test(Connection $connection): void
    {
        $response = Http::withToken((string) $connection->credential('api_key'))->timeout(15)->get('https://api.openai.com/v1/models');

        if (! $response->successful()) {
            throw new ConnectionFailed("OpenAI answered {$response->status()}: check the API key.");
        }
    }

    public function environment(Connection $connection): array
    {
        return array_filter(['OPENAI_API_KEY' => $connection->credential('api_key')]);
    }

    public function reviewers(): array
    {
        return [ReviewProvider::CodexCli, ReviewProvider::Ocr];
    }

    public function review(Connection $connection, string $instructions, string $material, ?string $model = null, ?string $effort = null): Answer
    {
        // As set, else what .env names, else the SDK's default, which follows the SDK.
        $model = filled($model) ? (string) $model : (string) (config('review.diff.openai_model') ?: ReviewSettings::tierModels('openai')['default'] ?? 'gpt-6.1-sol');

        $agent = new DiffReviewer($instructions, array_filter([
            // Every property required and every object closed, as strict structured output wants it.
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'review', 'strict' => true, 'schema' => ReportSchema::strict()]],
            // The API knows no effort above high.
            'reasoning' => $effort === null ? null : ['effort' => $effort === 'xhigh' ? 'high' : $effort],
        ]));

        try {
            $response = $agent->prompt($material, provider: $this->provider($connection, $this->config($connection)), model: $model, timeout: (int) config('review.diff.timeout'));
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

        $report = json_decode($response->text, true);

        if (! is_array($report) || array_is_list($report)) {
            throw new ReviewerFailed("{$model} answered with something that is not a report.");
        }

        return new Answer(ReportSchema::withoutNulls($report), $model, $this->usage($response->usage));
    }

    /**
     * A yes or a no to one question about a text, from the SDK's classification,
     * which answers with how likely a yes is.
     */
    public function decides(Connection $connection, string $question, string $text): ?bool
    {
        $answer = Classification::of($text)
            ->question('answer', new Boolean($question))
            ->classify($this->provider($connection, $this->config($connection)))
            ->answer('answer');

        return $answer instanceof BooleanAnswer ? $answer->isTrue() : null;
    }

    public function describes(Connection $connection, string $instructions, string $text, ?string $model = null): ?array
    {
        $answer = (new Describer($instructions))->prompt($text, provider: $this->provider($connection, $this->config($connection)), model: $model !== null ? ReviewSettings::model('openai', $model) : (string) (config('review.turn.openai_model') ?: ReviewSettings::tierModels('openai')['cheapest'] ?? 'gpt-6-luna'), timeout: 60);

        return Describer::read($answer);
    }

    public function classifies(Connection $connection, string $text): AgentResponse
    {
        return (new Classifier)->prompt($text, provider: $this->provider($connection, $this->config($connection)), model: (string) (config('review.turn.openai_model') ?: ReviewSettings::tierModels('openai')['cheapest'] ?? 'gpt-6-luna'), timeout: 30);
    }

    /**
     * @return array<string, mixed>
     */
    protected function config(Connection $connection): array
    {
        return ['driver' => 'openai', 'key' => (string) $connection->credential('api_key')];
    }
}
