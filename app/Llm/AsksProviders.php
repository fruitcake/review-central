<?php

declare(strict_types=1);

namespace App\Llm;

use App\Exceptions\ReviewerFailed;
use App\Models\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Ai;
use Laravel\Ai\Responses\Data\TextUsage;
use Throwable;

/**
 * What the drivers share in asking a provider through the Laravel AI SDK: a
 * provider for one tenant's connection, and its failures and its usage in the
 * terms a review is recorded in.
 */
trait AsksProviders
{
    /**
     * The SDK's provider for a connection. Built on demand: the key is the
     * tenant's, not one this installation has in its configuration.
     *
     * @param  array<string, mixed>  $config  the driver, the key and what else it takes
     */
    protected function provider(Connection $connection, array $config): string
    {
        $name = "connection-{$connection->getKey()}-".substr(hash('sha256', (string) json_encode($config)), 0, 12);

        Ai::build(['name' => $name] + $config);

        return $name;
    }

    /**
     * A failed request as the review's log should have it: the provider's own
     * words where it gave any. A lost connection stays what it is, for the caller
     * that says so in its own words.
     *
     * @throws ConnectionException
     */
    protected function failure(Throwable $exception): ReviewerFailed
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ConnectionException) {
                throw $cause;
            }

            if ($cause instanceof RequestException) {
                $said = $cause->response->json('error.message') ?? $cause->response->body();

                return new ReviewerFailed("{$this->label()} answered {$cause->response->status()}: ".mb_substr((string) (is_string($said) ? $said : json_encode($said)), 0, 500), previous: $exception);
            }
        }

        return new ReviewerFailed("{$this->label()} gave no answer: ".mb_substr($exception->getMessage(), 0, 500), previous: $exception);
    }

    /**
     * The SDK counts what was read from and written to a cache among the input;
     * a review is priced with each apart.
     *
     * @return array{input: int, output: int, cache_read: int, cache_creation: int}
     */
    protected function usage(?TextUsage $usage): array
    {
        $usage ??= new TextUsage;

        return [
            'input' => max(0, $usage->uncachedInputTokens()),
            'output' => $usage->outputTokens,
            'cache_read' => $usage->cacheReadInputTokens ?? 0,
            'cache_creation' => $usage->cacheWriteInputTokens ?? 0,
        ];
    }
}
