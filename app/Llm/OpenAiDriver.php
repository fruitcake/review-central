<?php

declare(strict_types=1);

namespace App\Llm;

use App\Connections\Fields\Secret;
use App\Connections\LlmDriver;
use App\Enums\ReviewProvider;
use App\Exceptions\ConnectionFailed;
use App\Exceptions\ReviewerFailed;
use App\Llm\Agents\DiffReviewer;
use App\Models\Connection;
use App\Runners\Diff\Answer;
use App\Runners\Diff\ReportSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\FinishReason;
use Throwable;

class OpenAiDriver implements LlmDriver
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
        $model = filled($model) ? (string) $model : (string) config('review.diff.openai_model');

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

    /**
     * @return array<string, mixed>
     */
    protected function config(Connection $connection): array
    {
        return ['driver' => 'openai', 'key' => (string) $connection->credential('api_key')];
    }
}
