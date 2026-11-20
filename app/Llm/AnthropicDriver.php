<?php

declare(strict_types=1);

namespace App\Llm;

use App\Connections\ExplainsSetup;
use App\Connections\Fields\Secret;
use App\Connections\LlmDriver;
use App\Enums\ReviewProvider;
use App\Exceptions\ConnectionFailed;
use App\Exceptions\ReviewerFailed;
use App\Llm\Agents\Decider;
use App\Llm\Agents\Describer;
use App\Llm\Agents\DiffReviewer;
use App\Models\Connection;
use App\Rules\PublicUrl;
use App\Runners\Diff\Answer;
use App\Runners\Diff\ReportSchema;
use App\Services\ReviewSettings;
use App\Support\SetupGuide;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\Error as StreamError;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Throwable;

class AnthropicDriver implements ExplainsSetup, LlmDriver
{
    use AsksProviders;

    /**
     * The model a review through the API runs on when the repository names none:
     * the SDK's smartest, so it follows the SDK; this where the SDK names none.
     */
    public const DEFAULT_MODEL = 'claude-opus-5-5';

    /** The model for a yes or a no and a short note, which need no more: the SDK's cheapest; this where it names none. */
    public const SMALL_MODEL = 'claude-haiku-5-5';

    public static function defaultModel(): string
    {
        return ReviewSettings::tierModels('anthropic')['smartest'] ?? self::DEFAULT_MODEL;
    }

    public static function smallModel(): string
    {
        return ReviewSettings::tierModels('anthropic')['cheapest'] ?? self::SMALL_MODEL;
    }

    protected const API = 'https://api.anthropic.com';

    /** The version of the API the requests are written for. */
    protected const VERSION = '2026-10-21';

    /**
     * The models whose requests the API can run again on another model when a
     * safety classifier declines one, which a diff full of security code can set off.
     */
    protected const FALLBACK_MODELS = '/^claude-(opus-5|opus-5-5|sonnet-5-5|fable-5-1|fable-5-2)$/';

    public function label(): string
    {
        return 'Anthropic';
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
            TextInput::make('base_url')->label('Base URL')->url()->rule(new PublicUrl)->placeholder('https://api.anthropic.com')
                ->helperText('Only when the API is reached through a gateway.'),
        ];
    }

    /**
     * An Anthropic key has no scopes: it reaches every model of its workspace.
     * Claude Code reviews with it as ANTHROPIC_API_KEY, and the review from the
     * diff and the questions on a pull request use it from this server.
     */
    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            title: 'An Anthropic API key',
            url: 'https://platform.claude.com/settings/keys',
            linkLabel: 'API keys in the Claude Console',
            steps: [
                'Sign in to the Claude Console and open the link. A new organisation first needs credits or a payment method under **Billing**.',
                'Choose **Create Key**, pick the workspace whose limits the reviews should fall under, and name the key after this installation.',
                'Copy the key, which starts with `sk-ant-` and is shown once, and paste it here.',
            ],
            permissions: [
                'The workspace\'s models' => 'a key has no scopes: it can call every model its workspace may. Claude Code reviews with it, and so do the review from the diff and the questions on a pull request.',
            ],
            notes: [
                'A workspace of its own for the reviews, with a spend limit, keeps them apart from the rest of what the organisation spends.',
                'The base URL under Settings is only for a gateway that speaks the Anthropic API; the key is then what that gateway takes.',
            ],
        );
    }

    public function test(Connection $connection): void
    {
        $response = Http::withHeaders(['x-api-key' => (string) $connection->credential('api_key'), 'anthropic-version' => '2023-06-01'])
            // The address was checked to be public; where it redirects to was not.
            ->withoutRedirecting()
            ->timeout(15)
            ->get(rtrim($connection->setting('base_url') ?? 'https://api.anthropic.com', '/').'/v1/models', ['limit' => 1]);

        if (! $response->successful()) {
            throw new ConnectionFailed("Anthropic answered {$response->status()}: check the API key.");
        }
    }

    public function environment(Connection $connection): array
    {
        return array_filter([
            'ANTHROPIC_API_KEY' => $connection->credential('api_key'),
            'ANTHROPIC_BASE_URL' => $connection->setting('base_url'),
        ]);
    }

    public function reviewers(): array
    {
        return [ReviewProvider::ClaudeCode, ReviewProvider::Ocr];
    }

    public function review(Connection $connection, string $instructions, string $material, ?string $model = null, ?string $effort = null): Answer
    {
        // A repository's setting may be an alias only Claude Code knows, such as "opus".
        $model = is_string($model) && str_starts_with($model, 'claude-') ? $model : self::defaultModel();
        // Not through a gateway, which may not know the parameter.
        $fallback = $connection->setting('base_url') === null && preg_match(self::FALLBACK_MODELS, $model) === 1;

        $agent = new DiffReviewer($instructions, [
            'output_config' => array_filter(['effort' => $effort]) + ['format' => ['type' => 'json_schema', 'schema' => ReportSchema::closed()]],
        ] + ($fallback ? ['fallbacks' => 'default'] : []));

        $text = '';
        $stop = null;
        $usage = null;

        try {
            // Streamed, so the size of the answer is no timeout. The room for it,
            // 64,000 tokens of thinking and report, is the SDK's own default.
            $stream = $agent->stream($material, provider: $this->provider($connection, $this->config($connection, $fallback)), model: $model, timeout: (int) config('review.diff.timeout'));

            foreach ($stream as $event) {
                match (true) {
                    // The model that answers, which is another one after a fallback.
                    $event instanceof StreamStart => $model = $event->model ?: $model,
                    $event instanceof TextDelta => $text .= $event->delta,
                    $event instanceof StreamEnd => [$stop, $usage] = [$event->reason, $event->usage],
                    $event instanceof StreamError => throw new ReviewerFailed('Anthropic stopped the answer: '.mb_substr($event->message, 0, 500)),
                    default => null,
                };
            }
        } catch (ReviewerFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw $this->failure($exception);
        }

        // Before the text is read: after either of these it is no report.
        if ($stop === FinishReason::ContentFilter->value) {
            throw new ReviewerFailed("{$model} declined to review this pull request.");
        }

        if ($stop === FinishReason::Length->value) {
            throw new ReviewerFailed("{$model} ran out of room before the report was complete.");
        }

        $report = json_decode($text, true);

        if (! is_array($report) || array_is_list($report)) {
            throw new ReviewerFailed("{$model} answered with something that is not a report.");
        }

        return new Answer($report, $model, $this->usage($usage));
    }

    /**
     * A yes or a no to one question about a text, from the small model, as
     * structured output: the SDK has no classification for Anthropic.
     */
    public function decides(Connection $connection, string $question, string $text): ?bool
    {
        $answer = (new Decider($question))->prompt($text, provider: $this->provider($connection, $this->config($connection)), model: self::smallModel(), timeout: 30);

        return is_bool($answer['answer'] ?? null) ? $answer['answer'] : null;
    }

    public function describes(Connection $connection, string $instructions, string $text): ?array
    {
        $answer = (new Describer($instructions))->prompt($text, provider: $this->provider($connection, $this->config($connection)), model: self::smallModel(), timeout: 60);

        return Describer::read($answer);
    }

    /**
     * The SDK's provider for a connection, through its gateway where it has one.
     *
     * @return array<string, mixed>
     */
    protected function config(Connection $connection, bool $fallback = false): array
    {
        return [
            'driver' => 'anthropic',
            'key' => (string) $connection->credential('api_key'),
            'url' => rtrim($connection->setting('base_url') ?? self::API, '/').'/v1',
            'version' => self::VERSION,
            // The SDK's own default asks for a beta a review has no use for.
            'anthropic_beta' => $fallback ? 'server-side-fallback-2026-07-01' : '',
        ];
    }
}
