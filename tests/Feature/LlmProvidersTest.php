<?php

use App\Connections\Drivers;
use App\Enums\ConnectionKind;
use App\Exceptions\ConnectionFailed;
use App\Exceptions\ReviewerFailed;
use App\Llm\Agents\DiffReviewer;
use App\Llm\ApiKeyDriver;
use App\Models\Connection;
use App\Models\Tenant;
use App\Services\ReviewCredentials;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);

    $this->connect = fn (string $driver, string $model = 'model-one'): Connection => Connection::query()->create([
        'tenant_id' => $this->tenant->id, 'kind' => ConnectionKind::Llm, 'driver' => $driver, 'name' => ucfirst($driver),
        'credentials' => ['api_key' => "key-{$driver}"], 'settings' => ['model' => $model],
    ]);
});

it('hands each provider\'s key and model to the reviewer under the names Pi reads', function (string $driver, string $variable, string $model) {
    ($this->connect)($driver, 'model-one');

    expect(app(ReviewCredentials::class)->environment($this->tenant))->toBe([$variable => "key-{$driver}", $model => 'model-one'])
        // Not one of the reviewers a repository is set to: Pi reviews with it, on a trial.
        ->and(app(ReviewCredentials::class)->reviewers($this->tenant))->toBe([])
        ->and(app(Drivers::class)->get(ConnectionKind::Llm, $driver))->toBeInstanceOf(ApiKeyDriver::class);
})->with([
    ['gemini', 'GEMINI_API_KEY', 'PI_REVIEW_MODEL_GOOGLE'],
    ['mistral', 'MISTRAL_API_KEY', 'PI_REVIEW_MODEL_MISTRAL'],
    ['groq', 'GROQ_API_KEY', 'PI_REVIEW_MODEL_GROQ'],
    ['deepseek', 'DEEPSEEK_API_KEY', 'PI_REVIEW_MODEL_DEEPSEEK'],
    ['xai', 'XAI_API_KEY', 'PI_REVIEW_MODEL_XAI'],
    ['openrouter', 'OPENROUTER_API_KEY', 'PI_REVIEW_MODEL_OPENROUTER'],
]);

it('tests a connection with a request only a working key gets an answer to', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['models' => []]),
        'api.mistral.ai/*' => Http::response(['message' => 'Unauthorized'], 401),
    ]);
    $gemini = ($this->connect)('gemini');
    $mistral = ($this->connect)('mistral');

    $gemini->driver()->test($gemini);

    // Google takes the key in its own header, the others as a bearer token.
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/v1beta/models') && $request->hasHeader('x-goog-api-key', 'key-gemini'));

    expect(fn () => $mistral->driver()->test($mistral))->toThrow(ConnectionFailed::class, 'Mistral answered 401: check the API key.');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.mistral.ai/v1/models' && $request->hasHeader('Authorization', 'Bearer key-mistral'));
});

it('reviews a diff through such a provider, with the schema in words and its answer read as the report', function () {
    $gemini = ($this->connect)('gemini', 'gemini-test');
    $report = ['result' => 'PASSED', 'summary' => 'Nothing in the diff held up.', 'findings' => []];
    // Fenced, as a model does that was told not to.
    DiffReviewer::fake(["```json\n".json_encode($report)."\n```"]);

    $answer = $gemini->driver()->review($gemini, 'Review the diff.', 'diff --git a/a.php b/a.php', 'claude-opus-5-5', 'high');

    expect($answer->report)->toBe($report);

    // Its own model, not the one the repository set for Claude; and the schema, since nothing else holds it to one.
    DiffReviewer::assertPrompted(fn ($prompt): bool => $prompt->model === 'gemini-test'
        && str_contains((string) $prompt->agent->instructions(), 'Answer with one JSON object and nothing else')
        && str_contains((string) $prompt->agent->instructions(), '"findings"'));

    DiffReviewer::fake(['I could not review this.']);

    expect(fn () => $gemini->driver()->review($gemini, 'Review the diff.', 'diff'))->toThrow(ReviewerFailed::class, 'answered with something that is not a report');
});

it('offers Pi on a trial to a tenant with any provider\'s key, and to none without', function () {
    $offered = fn (): array => app(ReviewCredentials::class)->trialReviewers($this->tenant);

    expect($offered())->toBe([]);

    ($this->connect)('groq');

    // Pi reviews with whichever provider there is; Claude Code needs Anthropic's.
    expect($offered())->toBe(['pi' => 'Pi']);

    Connection::factory()->anthropic()->create(['tenant_id' => $this->tenant->id]);

    expect($offered())->toBe(['claude-code' => 'Claude Code', 'ocr' => 'OpenCodeReview', 'pi' => 'Pi']);
});
