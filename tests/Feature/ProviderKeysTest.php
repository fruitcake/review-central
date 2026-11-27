<?php

use App\Connections\Drivers;
use App\Connections\LlmDriver;
use App\Enums\ConnectionKind;
use App\Llm\ProviderKeys;
use App\Models\Connection;

it('knows the host of every key an AI provider\'s connection gives a reviewer', function () {
    foreach (array_keys(app(Drivers::class)->options(ConnectionKind::Llm)) as $name) {
        $connection = new Connection(['kind' => ConnectionKind::Llm, 'driver' => $name, 'credentials' => ['api_key' => 'key-of-'.$name.'-0123456789'], 'settings' => ['model' => 'some-model']]);
        /** @var LlmDriver $driver */
        $driver = $connection->driver();

        $keys = array_keys(array_filter($driver->environment($connection), fn (string $value): bool => $value === 'key-of-'.$name.'-0123456789'));

        expect($keys)->not->toBeEmpty()
            ->and(array_diff($keys, array_keys(ProviderKeys::HOSTS)))->toBe([], "{$name} gives a key ProviderKeys does not know the host of.");
    }
});

it('replaces each key by a placeholder for its host, its gateway\'s where it has one', function () {
    $proxied = ProviderKeys::proxied([
        'ANTHROPIC_API_KEY' => 'sk-ant-api03-SECRETSECRETSECRET',
        'ANTHROPIC_BASE_URL' => 'https://Gateway.example.com/anthropic',
        'GROQ_API_KEY' => 'gsk_SECRETSECRETSECRET',
        'REVIEW_REVIEWER' => 'claude-code',
    ]);

    expect(json_encode($proxied['environment']))->not->toContain('SECRET')
        ->and($proxied['environment']['ANTHROPIC_API_KEY'])->toStartWith('sk-ant-api03-placeholder-')
        ->and($proxied['environment']['GROQ_API_KEY'])->toStartWith('gsk_placeholder-')
        ->and($proxied['environment']['REVIEW_REVIEWER'])->toBe('claude-code')
        ->and($proxied['credentials'])->toBe([
            ['host' => 'gateway.example.com', 'placeholder' => $proxied['environment']['ANTHROPIC_API_KEY'], 'value' => 'sk-ant-api03-SECRETSECRETSECRET'],
            ['host' => 'api.groq.com', 'placeholder' => $proxied['environment']['GROQ_API_KEY'], 'value' => 'gsk_SECRETSECRETSECRET'],
        ]);
});

it('gives the OpenAI key to the ChatGPT backend\'s API only, and not through a gateway', function () {
    $direct = ProviderKeys::proxied(['OPENAI_API_KEY' => 'sk-proj-SECRETSECRETSECRET']);
    $placeholder = $direct['environment']['OPENAI_API_KEY'];

    expect($direct['credentials'])->toBe([
        ['host' => 'api.openai.com', 'placeholder' => $placeholder, 'value' => 'sk-proj-SECRETSECRETSECRET'],
        ['host' => 'chatgpt.com', 'placeholder' => $placeholder, 'value' => 'sk-proj-SECRETSECRETSECRET', 'paths' => ['/backend-api/']],
    ]);

    $gateway = ProviderKeys::proxied(['OPENAI_API_KEY' => 'sk-proj-SECRETSECRETSECRET', 'OPENAI_BASE_URL' => 'https://gateway.example.com/v1']);

    expect(array_column($gateway['credentials'], 'host'))->toBe(['gateway.example.com']);
});

it('refuses a key for a gateway over plain HTTP rather than hand it to the sandbox', function () {
    expect(fn () => ProviderKeys::proxied(['ANTHROPIC_API_KEY' => 'sk-ant-one-123456', 'ANTHROPIC_BASE_URL' => 'http://gateway.internal']))
        ->toThrow(RuntimeException::class, 'a key goes to a gateway over HTTPS only');
});

it('refuses a key for a gateway on another port than the Worker takes', function () {
    expect(fn () => ProviderKeys::proxied(['ANTHROPIC_API_KEY' => 'sk-ant-one-123456', 'ANTHROPIC_BASE_URL' => 'https://gateway.example.com:8443']))
        ->toThrow(RuntimeException::class, 'port 443 only');

    expect(ProviderKeys::proxied(['ANTHROPIC_API_KEY' => 'sk-ant-one-123456', 'ANTHROPIC_BASE_URL' => 'https://gateway.example.com:443/v1'])['credentials'][0]['host'])
        ->toBe('gateway.example.com');
});

it('gives a warm sandbox the same placeholder for the same key, so the Worker can tell the keys did not change', function () {
    $placeholder = fn (string $key, ?string $warm): string => ProviderKeys::proxied(['ANTHROPIC_API_KEY' => $key], $warm)['environment']['ANTHROPIC_API_KEY'];

    expect($placeholder('sk-ant-one', 'warm-7-abc'))->toBe($placeholder('sk-ant-one', 'warm-7-abc'))
        ->toStartWith('sk-ant-placeholder-')
        ->not->toContain('one')
        ->not->toBe($placeholder('sk-ant-two', 'warm-7-abc'))
        ->not->toBe($placeholder('sk-ant-one', 'warm-8-abc'))
        // Without a warm sandbox, a new one each run.
        ->and($placeholder('sk-ant-one', null))->not->toBe($placeholder('sk-ant-one', null));
});
