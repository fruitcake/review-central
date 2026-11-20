<?php

declare(strict_types=1);

namespace App\Services;

use App\Connections\LlmDriver;
use App\Enums\ConnectionKind;
use App\Enums\ReviewProvider;
use App\Models\Connection;
use App\Models\Tenant;

/**
 * The AI provider keys a tenant's reviews run with, as the environment variables
 * the reviewers read them from.
 */
class ReviewCredentials
{
    /**
     * With several connections to one provider, the oldest is the one used.
     *
     * Given the reviewer that runs, only the keys of the providers that run it:
     * the step runs beside the pull request's code, and a key it does not need is
     * one it cannot lose. A reviewer no provider names as its own, such as Pi on a
     * trial, reviews with whichever there is, and gets them all.
     *
     * @return array<string, string>
     */
    public function environment(Tenant $tenant, ?string $reviewer = null): array
    {
        $connections = collect($this->connections($tenant));
        $own = $connections->filter(fn (Connection $connection): bool => collect($this->driver($connection)->reviewers())
            ->contains(fn (ReviewProvider $provider): bool => $provider->value === $reviewer));

        $environment = [];

        foreach ($reviewer !== null && $own->isNotEmpty() ? $own : $connections as $connection) {
            $environment += $this->driver($connection)->environment($connection);
        }

        return $environment;
    }

    protected function driver(Connection $connection): LlmDriver
    {
        /** @var LlmDriver $driver */
        $driver = $connection->driver();

        return $driver;
    }

    /**
     * The reviewers the tenant has a key for.
     *
     * @return array<int, string>
     */
    public function reviewers(Tenant $tenant): array
    {
        $reviewers = [];

        foreach ($this->connections($tenant) as $connection) {
            /** @var LlmDriver $driver */
            $driver = $connection->driver();

            foreach ($driver->reviewers() as $reviewer) {
                $reviewers[$reviewer->value] = true;
            }
        }

        return array_keys($reviewers);
    }

    /**
     * The reviewers a trial can ask for, by name, with what a person calls them:
     * the ones the tenant has a key for, and, with any AI provider's key at all,
     * the ones that are here to be tried and review with whichever there is.
     *
     * @return array<string, string>
     */
    public function trialReviewers(Tenant $tenant): array
    {
        $reviewers = [
            ...$this->reviewers($tenant),
            ...($this->environment($tenant) === [] ? [] : array_column(ReviewProvider::onTrial(), 'value')),
        ];

        return collect($reviewers)->mapWithKeys(fn (string $reviewer): array => [$reviewer => ReviewProvider::tryFrom($reviewer)?->text() ?? $reviewer])->all();
    }

    /**
     * @return iterable<int, Connection>
     */
    protected function connections(Tenant $tenant): iterable
    {
        return $tenant->connections()->where('kind', ConnectionKind::Llm)->oldest('id')->get();
    }
}
