<?php

namespace Tests;

use App\Models\Repository;
use App\Models\ReviewRun;
use App\Services\ReviewFiling;
use App\Services\ReviewHandover;
use App\Services\ReviewOpening;
use App\Tickets\TicketResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Each process of a parallel run gets a storage of its own for what the
        // tests write, or one would delete the work, log or session directory
        // another is still using.
        $token = ParallelTesting::token();

        if ($token !== false) {
            $storage = $this->app->storagePath("framework/testing/process-{$token}");
            File::ensureDirectoryExists("{$storage}/framework/testing");
            $this->app->useStoragePath($storage);
        }

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

    /** The repository openReview() and fileReview() are about when they are not told. */
    protected ?Repository $reviewRepository = null;

    /**
     * Has openReview() and fileReview() be about this repository from now on.
     */
    protected function reviewing(Repository $repository): Repository
    {
        return $this->reviewRepository = $repository;
    }

    /**
     * Opens a review as a runner does, with its answer as a response to assert on:
     * 200 with the context, or 409 when another run holds the lock.
     *
     * @param  array<string, mixed>  $input
     * @return TestResponse<JsonResponse>
     */
    public function openReview(array $input, ?Repository $repository = null): TestResponse
    {
        $opened = app(ReviewOpening::class)->open($repository ?? $this->reviewRepository ?? throw new \LogicException('No repository: call reviewing() first.'), $input);

        return TestResponse::fromBaseResponse(response()->json($opened['body'], $opened['status']));
    }

    /**
     * Files a review's report as a runner does, with what came of it as a response
     * to assert on.
     *
     * @param  array<string, mixed>  $report
     * @return TestResponse<JsonResponse>
     */
    public function fileReview(array $report, ?Repository $repository = null): TestResponse
    {
        $filed = app(ReviewFiling::class)->file($repository ?? $this->reviewRepository ?? throw new \LogicException('No repository: call reviewing() first.'), $report);

        return TestResponse::fromBaseResponse(response()->json($filed));
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
