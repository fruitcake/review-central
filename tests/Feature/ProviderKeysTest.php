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

it('leaves a key that goes to a gateway over plain HTTP as it is', function () {
    $proxied = ProviderKeys::proxied(['ANTHROPIC_API_KEY' => 'sk-ant-one-123456', 'ANTHROPIC_BASE_URL' => 'http://gateway.internal']);

    expect($proxied['environment']['ANTHROPIC_API_KEY'])->toBe('sk-ant-one-123456')
        ->and($proxied['credentials'])->toBe([]);
});
