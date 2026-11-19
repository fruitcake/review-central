<?php

namespace Tests;

use App\Models\Repository;
use App\Models\ReviewRun;
use App\Services\ReviewHandover;
use App\Services\RunTokens;
use App\Tickets\TicketResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A container review writes its log as it runs; not among the real ones.
        config([
            'review.container.log.directory' => storage_path('framework/testing/review-logs'),
            'review.container.sessions.directory' => storage_path('framework/testing/review-sessions'),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/review-logs'));
        File::deleteDirectory(storage_path('framework/testing/review-sessions'));

        parent::tearDown();
    }

    /**
     * Has every pull request title name a ticket with this text, without a ticket
     * source or a tracker behind it.
     */
    protected function fakeTicket(string $markdown): void
    {
        $this->mock(TicketResolver::class)->shouldReceive('forTitle')->andReturn([
            'reference' => 'PROJ-1',
            'source' => 'Tickets',
            'url' => 'https://tickets.example.com/PROJ-1',
            'markdown' => $markdown,
            'error' => null,
        ]);
    }

    /**
     * Has calls with this key count as steps of a run of the repository, whatever
     * branch and commit they name. A real token is good for one branch and commit,
     * which the tests of the token itself use; the others are about what a call
     * does once it is let in.
     */
    protected function actAsRunOf(Repository $repository, string $key = 'test-key'): Repository
    {
        $tokens = app(RunTokens::class);

        if (! $tokens instanceof AnyRunTokens) {
            $tokens = new AnyRunTokens;
            $this->app->instance(RunTokens::class, $tokens);
        }

        $tokens->repositories[$key] = (int) $repository->getKey();

        return $repository;
    }

    /**
     * What the job of a run does when it ends, however it ended: closes a run that
     * never reported and hands the review lock back.
     *
     * @return array<string, mixed>
     */
    protected function endRun(Repository $repository, string $branch, string $commit, string $holder): array
    {
        ReviewHandover::abandon($repository, $commit, $holder);

        return ReviewHandover::release($repository, $branch, $commit, $holder);
    }

    /**
     * Has reviews of a pull request finished on these commits. A marker in the
     * description or a comment that says a commit was reviewed is only believed
     * when the server has such a review itself.
     */
    protected function reviewed(Repository $repository, int $pullRequestId, string ...$commits): void
    {
        foreach ($commits as $commit) {
            ReviewRun::query()->create([
                'tenant_id' => $repository->tenant_id, 'repository_id' => $repository->getKey(), 'workspace' => $repository->workspace, 'slug' => $repository->slug,
                'branch' => 'feature/review', 'commit' => $commit, 'pull_request_id' => $pullRequestId,
                'status' => ReviewRun::STATUS_FINISHED, 'result' => 'PASSED', 'started_at' => now()->subDay(), 'finished_at' => now()->subDay(),
            ]);
        }
    }
}
