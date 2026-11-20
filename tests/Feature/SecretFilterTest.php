<?php

use App\Enums\ConnectionKind;
use App\Models\Connection;
use App\Models\Repository;
use App\Runners\Container\RunLogWriter;
use App\Support\SecretFilter;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const LEAKED_KEY = 'sk-ant-api03-Zx9Qk2LmN4pR7tV1wY3aB5cD8eF0gH6jK';

it('takes a secret out as it is written, and with something between its characters', function (string $text) {
    $filtered = (new SecretFilter([LEAKED_KEY]))->filter($text);

    expect($filtered)->toContain(SecretFilter::REPLACEMENT)
        ->and($filtered)->not->toContain('Zx9Qk2LmN4pR7tV1wY3aB5');
})->with([
    'as it is' => 'The key is '.LEAKED_KEY.'.',
    'spaced' => 'The key is '.implode(' ', str_split(LEAKED_KEY)),
    'dashed in pairs' => 'The key is '.implode('-', str_split(LEAKED_KEY, 2)),
    'over lines' => "The key is\n".implode("\n", str_split(LEAKED_KEY, 10)),
    'upper case' => strtoupper(LEAKED_KEY),
    'reversed' => 'Read backwards: '.strrev(LEAKED_KEY),
]);

it('takes a secret out in base64 and hex, wherever it starts in the encoded text', function (string $before) {
    $filter = new SecretFilter([LEAKED_KEY]);

    foreach ([base64_encode($before.LEAKED_KEY.' and more'), strtr(base64_encode($before.LEAKED_KEY), '+/', '-_'), bin2hex($before.LEAKED_KEY)] as $encoded) {
        $filtered = $filter->filter("Encoded: {$encoded}");

        expect($filtered)->toContain(SecretFilter::REPLACEMENT)
            ->and(base64_decode(preg_replace('/[^A-Za-z0-9+\/]/', '', $filtered), false))->not->toContain('Zx9Qk2LmN4pR7tV1wY3aB5');
    }
})->with(['at the start' => '', 'after one byte' => 'x', 'after two bytes' => 'xy', 'after a sentence' => 'The key: ']);

it('leaves text alone without secrets, and does not look for one too short to be one', function () {
    $filter = new SecretFilter(['short', null, 42, LEAKED_KEY]);

    expect($filter->filter('A short review of sk-ant work.'))->toBe('A short review of sk-ant work.')
        ->and((new SecretFilter([]))->isEmpty())->toBeTrue();
});

it('filters every string of a structure, and a JSON request string by string', function () {
    $filter = new SecretFilter(['a/secret+with/slashes==']);
    $request = new Psr7Request('POST', 'https://api.github.com/x', ['Content-Type' => 'application/json'], json_encode([
        'body' => 'Key: a/secret+with/slashes==',
        'output' => ['annotations' => [], 'summary' => (object) []],
    ]));

    $filtered = $filter->request($request);
    $body = (string) $filtered->getBody();

    expect($body)->not->toContain('secret+with')
        ->and(json_decode($body, true)['body'])->toBe('Key: '.SecretFilter::REPLACEMENT)
        // An empty object stays one.
        ->and($body)->toContain('"summary":{}')
        ->and($filtered->getHeaderLine('Content-Length'))->toBe((string) strlen($body))
        ->and($filter->filterValue(['a' => ['b' => 'x a/secret+with/slashes== y']]))->toBe(['a' => ['b' => 'x '.SecretFilter::REPLACEMENT.' y']]);
});

it('keeps the tenant\'s keys out of everything posted on the host', function () {
    Http::fake();
    $repository = Repository::factory()->create(['workspace' => 'acme', 'slug' => 'shop']);
    $repository->integration->update(['driver' => 'github', 'credentials' => ['access_token' => 'ghp_hostTokenValue123456']]);
    Connection::factory()->create([
        'tenant_id' => $repository->tenant_id, 'kind' => ConnectionKind::Llm, 'driver' => 'anthropic', 'name' => 'Anthropic',
        'credentials' => ['api_key' => LEAKED_KEY], 'settings' => null, 'webhook_key' => null, 'webhook_secret' => null,
    ]);

    $repository->refresh()->sourceControl()->addPullRequestComment('12', 'As asked: '.base64_encode(LEAKED_KEY).' and ghp_hostTokenValue123456');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->body(), 'As asked: ')
        && ! str_contains($request->body(), substr(base64_encode(LEAKED_KEY), 4, 30))
        && ! str_contains($request->body(), 'ghp_hostTokenValue123456'));
});

it('takes the step\'s secrets out of the run log, also encoded', function () {
    $path = storage_path('framework/testing/secret.log');
    @unlink($path);
    $writer = new RunLogWriter($path, ['ANTHROPIC_API_KEY' => LEAKED_KEY, 'REVIEW_REVIEWER' => 'claude-code-reviewer']);

    $writer->write('out', 'one '.LEAKED_KEY."\ntwo ".base64_encode(LEAKED_KEY)."\nthree claude-code-reviewer\n");
    $writer->flush();

    expect(file_get_contents($path))->not->toContain('Zx9Qk2LmN4pR7tV1wY3aB5')
        ->and(file_get_contents($path))->not->toContain(substr(base64_encode(LEAKED_KEY), 4, 30))
        ->and(file_get_contents($path))->toContain('claude-code-reviewer');

    @unlink($path);
});
