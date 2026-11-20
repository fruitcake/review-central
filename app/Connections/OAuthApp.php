<?php

declare(strict_types=1);

namespace App\Connections;

/**
 * One app a person signs in at a service through: one kept in the list, or the
 * one .env sets. A connection remembers it by its key.
 */
final readonly class OAuthApp
{
    /** The key of the app .env sets, which no list holds. */
    public const ENV = 'env';

    /**
     * @param  array<string, string>  $credentials
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $credentials,
    ) {}

    public function clientId(): string
    {
        return $this->credentials['client_id'] ?? '';
    }

    public function clientSecret(): string
    {
        return $this->credentials['client_secret'] ?? '';
    }
}
