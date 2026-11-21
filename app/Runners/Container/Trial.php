<?php

declare(strict_types=1);

namespace App\Runners\Container;

/**
 * A review to try a setup with: of one pull request, open or merged, with the
 * reviewer and model that were asked for. It is recorded here and nothing of it
 * reaches the pull request: no comment, no build status, no review lock.
 */
final readonly class Trial
{
    /**
     * @param  string|null  $mergeCommit  the commit a merged pull request was merged in; null reviews the branch as it is
     * @param  string|null  $provider  the reviewer, or null for the one the repository would get
     * @param  string|null  $sandbox  where the reviewer runs, or null for where this installation runs it
     * @param  bool  $ignoreExisting  review as though nobody had: without the comments and earlier findings on the pull request
     * @param  string|null  $pullRef  the ref the host keeps an open pull request's head under in this repository, for one whose branch is in a fork
     * @param  array<string, bool>|null  $modules  the parts of the review to turn on or off, by ReviewSettings::MODULES name, over the repository's; those left out are as it has them
     */
    public function __construct(
        public int $pullRequestId,
        public string $destination,
        public ?string $mergeCommit = null,
        public ?string $provider = null,
        public ?string $model = null,
        public ?string $effort = null,
        public ?bool $verify = null,
        public bool $ignoreExisting = true,
        public ?string $sandbox = null,
        public ?string $pullRef = null,
        public ?array $modules = null,
    ) {}

    /**
     * What the review's scripts read a custom run from.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        return array_filter([
            'BITBUCKET_PR_ID' => (string) $this->pullRequestId,
            'BITBUCKET_PR_DESTINATION_BRANCH' => $this->destination,
            // Its being set is what makes the run a custom one.
            'REVIEW_CUSTOM_PROVIDER' => $this->provider ?? 'default',
            'REVIEW_CUSTOM' => 'true',
            'REVIEW_CUSTOM_MODEL' => $this->model,
            'REVIEW_CUSTOM_EFFORT' => $this->effort,
            'REVIEW_CUSTOM_VERIFY' => $this->verify === null ? null : ($this->verify ? 'true' : 'false'),
            'REVIEW_CUSTOM_EXISTING' => $this->ignoreExisting ? null : 'keep',
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }
}
