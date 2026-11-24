<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConnectionKind;
use App\Enums\ReviewProvider;
use App\Enums\ReviewsFrom;
use App\Models\Repository;
use App\Runners\DiffRunner;
use Throwable;

/**
 * Whether a repository can be reviewed as it is set up now, checked against its
 * host and this installation rather than assumed.
 */
class RepositoryReadiness
{
    public const OK = 'ok';

    public const PROBLEM = 'problem';

    /** Nothing wrong, and nothing confirmed either. */
    public const UNKNOWN = 'unknown';

    /**
     * @return array<int, array{label: string, note: string, state: string}>
     */
    public function checks(Repository $repository): array
    {
        $checks = [$this->enabled($repository), $this->readable($repository), $this->provider($repository), $this->reviewers($repository)];

        $runner = $repository->runner();
        // Where it was chosen: on the repository, or by the platform for all.
        $whose = $repository->review_runner === null ? ', as the platform' : '';

        $checks[] = match (true) {
            ! $runner->available() => ['label' => 'Runner', 'note' => "{$runner->label()}{$whose} is not set up on this installation. {$runner->setupHint()} Or pick another runner under \"Runs on\".", 'state' => self::PROBLEM],
            // A git server has no pull requests, so no diff to review.
            $runner instanceof DiffRunner && ! $repository->sourceControl()->publishes() && $repository->integration?->driver === 'git' => ['label' => 'Runner', 'note' => 'The diff alone needs a host with pull requests (Bitbucket or GitHub). A git server\'s repositories need Docker or a Cloudflare sandbox, for the security review.', 'state' => self::PROBLEM],
            default => ['label' => 'Runner', 'note' => "{$runner->label()}{$whose}. Nothing is needed in the repository.", 'state' => self::OK],
        };
        $checks[] = $this->webhook($repository);
        $checks[] = $this->tickets($repository);

        return $checks;
    }

    public function ready(Repository $repository): bool
    {
        return ! in_array(self::PROBLEM, array_column($this->checks($repository), 'state'), true);
    }

    /**
     * @return array{label: string, note: string, state: string}
     */
    protected function readable(Repository $repository): array
    {
        try {
            $found = $repository->sourceControl()->getRepository();
        } catch (Throwable $exception) {
            return ['label' => 'Connection can read the repository', 'note' => 'The host could not be asked: '.$exception->getMessage(), 'state' => self::PROBLEM];
        }

        return $found === null
            ? ['label' => 'Connection can read the repository', 'note' => "{$repository->integration->name} cannot see {$repository->fullName()}: check its name and the token.", 'state' => self::PROBLEM]
            : ['label' => 'Connection can read the repository', 'note' => "{$repository->integration->name} · {$repository->workspace}", 'state' => self::OK];
    }

    /**
     * Off is no problem: such a repository is reviewed when a person starts it.
     *
     * @return array{label: string, note: string, state: string}
     */
    protected function enabled(Repository $repository): array
    {
        return match (true) {
            ! $repository->reviews_enabled => ['label' => 'Reviews', 'note' => 'Off: nothing starts a review by itself; a person starts each one, from Pull requests or with a /review comment, and @review questions are still answered.', 'state' => self::UNKNOWN],
            $repository->reviews_from === ReviewsFrom::Trusted => ['label' => 'Reviews', 'note' => 'On, for the trusted accounts\' pull requests; the others wait under Pull requests.', 'state' => self::OK],
            default => ['label' => 'Reviews', 'note' => 'On, for the pull requests that may be reviewed, whoever opened them.', 'state' => self::OK],
        };
    }

    /**
     * The reviewers the runner said it can run, which it says when it runs.
     *
     * @return array{label: string, note: string, state: string}
     */
    protected function reviewers(Repository $repository): array
    {
        $available = $repository->review_available_providers;

        return match (true) {
            $available === null => ['label' => 'Reviewers available', 'note' => 'Known after the first review.', 'state' => self::UNKNOWN],
            // Asked, and none could run: no AI key the runner can use.
            $available === [] => ['label' => 'Reviewers available', 'note' => 'None, at the last review: no AI provider the runner can use.', 'state' => self::PROBLEM],
            default => ['label' => 'Reviewers available', 'note' => implode(', ', array_map(fn (string $name): string => ReviewProvider::tryFrom($name)?->text() ?? $name, $available)), 'state' => self::OK],
        };
    }

    /**
     * @return array{label: string, note: string, state: string}
     */
    protected function provider(Repository $repository): array
    {
        $providers = $repository->tenant->connections()->where('kind', ConnectionKind::Llm)->pluck('name');

        return $providers->isEmpty()
            ? ['label' => 'AI provider', 'note' => 'None connected: a review has no model to run on.', 'state' => self::PROBLEM]
            : ['label' => 'AI provider', 'note' => $providers->implode(', '), 'state' => self::OK];
    }

    /**
     * @return array{label: string, note: string, state: string}
     */
    protected function webhook(Repository $repository): array
    {
        if ($repository->webhook_received_at !== null) {
            return ['label' => 'Webhook', 'note' => 'Last delivery '.$repository->webhook_received_at->diffForHumans().'.', 'state' => self::OK];
        }

        // A webhook on the workspace covers this repository too, one on another
        // repository does not, and a delivery does not say which it came from.
        if ($repository->integration->webhook_received_at !== null) {
            return ['label' => 'Webhook', 'note' => "{$repository->integration->name} receives deliveries, but none for this repository yet.", 'state' => self::UNKNOWN];
        }

        return ['label' => 'Webhook', 'note' => 'No delivery received yet. Without it nothing starts a review: see "Webhook" on the connection.', 'state' => self::PROBLEM];
    }

    /**
     * @return array{label: string, note: string, state: string}
     */
    protected function tickets(Repository $repository): array
    {
        $sources = $repository->tenant->ticketSources()
            ->where(fn ($query) => $query->whereNull('repository_id')->orWhere('repository_id', $repository->getKey()))
            ->pluck('name');

        return $sources->isEmpty()
            ? ['label' => 'Ticket source', 'note' => 'None: reviews read no ticket for this repository.', 'state' => self::UNKNOWN]
            : ['label' => 'Ticket source', 'note' => $sources->implode(', '), 'state' => self::OK];
    }
}
