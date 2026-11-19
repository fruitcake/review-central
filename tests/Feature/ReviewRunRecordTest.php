<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunContainerReview;
use App\Models\ModelPrice;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Runners\ContainerRunner;
use App\Services\ReviewLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReviewRunRecordTest extends TestCase
{
    use RefreshDatabase;

    protected string $key;

    protected Repository $repository;

    protected string $sha;

    /** The pull request's head, so a test can move it under a running review. */
    protected string $head;

    /** Its comments, for the same reason: Http::fake() merges, it does not replace. */
    protected array $comments = [];

    /** And its description, which is where the review keeps its own state. */
    protected string $description = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->reviewing(Repository::factory()->create(['workspace' => 'example-co', 'slug' => 'storefront', 'name' => 'Storefront']));

        $this->sha = str_repeat('a', 40);
        $this->head = $this->sha;

        // A closure, not a fixed response: Http::fake() merges stubs rather than
        // replacing them, so a test cannot override this later — it moves $head.
        Http::fake([
            '*/pullrequests/570/comments*' => fn () => Http::response(['values' => $this->comments]),
            '*/pullrequests/570' => fn () => Http::response([
                'id' => 570,
                'title' => 'PROJ-1 Add the review step',
                'description' => $this->description,
                'state' => 'OPEN',
                'draft' => false,
                'source' => [
                    'branch' => ['name' => 'feature/review'],
                    'commit' => ['hash' => $this->head],
                ],
                'destination' => ['branch' => ['name' => 'master']],
            ]),
        ]);

        $this->fakeTicket('# Ticket');

        // A run that waited for the lock is started again as a job.
        Queue::fake();
    }

    protected function startRun(string $build = '77'): void
    {
        // Its job said it runs before it asked for anything.
        app(ContainerRunner::class)->markRunning($this->repository, (int) $build);

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$this->sha}", 'lock' => $build])
            ->assertOk();
    }

    /**
     * What a run does at the end: file the report, and its job then releases the
     * lock. Without the release the next build is turned away by the lock and
     * never reaches the question this is testing.
     *
     * @param  array<string, mixed>  $report
     */
    protected function finishRun(string $build = '77', array $report = []): void
    {
        $this->fileReview(array_merge([
            'branch' => 'feature/review',
            'commit' => $this->sha,
            'build' => (int) $build,
            'result' => 'PASSED',
            'summary' => 'No findings.',
            'findings' => [],
        ], $report))->assertOk();

        $this->endRun($this->repository, 'feature/review', $this->sha, $build);
    }

    public function test_taking_the_lock_opens_a_running_record(): void
    {
        $this->startRun();

        $run = ReviewRun::firstOrFail();

        $this->assertSame(ReviewRun::STATUS_RUNNING, $run->status);
        $this->assertSame($this->repository->getKey(), $run->repository_id);
        $this->assertSame('example-co', $run->workspace);
        $this->assertSame('storefront', $run->slug);
        $this->assertSame('feature/review', $run->branch);
        $this->assertSame(570, $run->pull_request_id);
        $this->assertSame($this->sha, $run->commit);
        $this->assertSame(77, $run->build_number);
        $this->assertNull($run->finished_at);
        // The context is kept so a surprising review can be explained afterwards.
        $this->assertSame(570, $run->context['pull_request']['id']);
    }

    public function test_a_run_without_a_lock_is_still_recorded(): void
    {
        // A comparison run - a second agent reviewing a pull request the first has
        // already reviewed - takes no lock, because queueing it behind the review
        // that gates the merge is the opposite of what pressing Run asks for. It is
        // still a run, and what it costs has to be visible next to the other one.
        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $this->sha,
            'build' => '88',
        ])->assertOk();

        $run = ReviewRun::firstOrFail();

        $this->assertSame(ReviewRun::STATUS_RUNNING, $run->status);
        $this->assertSame(88, $run->build_number);

        // And it left the lock alone, so the run that does hold one still holds it.
        $this->assertNull(ReviewLock::heldBy($this->repository, 'feature/review'));
    }

    public function test_a_run_without_a_lock_never_turns_another_run_away(): void
    {
        $this->startRun('77');

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $this->sha,
            'build' => '88',
        ])->assertOk();

        $this->assertSame('77', ReviewLock::heldBy($this->repository, 'feature/review'));
        $this->assertSame(2, ReviewRun::count());
    }

    public function test_a_forced_run_reviews_a_commit_that_was_already_reviewed(): void
    {
        $this->comments = [[
            'id' => 1,
            'content' => ['raw' => "Reviewed.\n\n[//]: # (ai-review reviewed={$this->sha})"],
            'user' => ['nickname' => 'pipelines'],
            'created_on' => '2026-09-20T10:00:00+00:00',
        ]];

        // The marker alone is words anyone can write on a pull request: with no
        // review behind it the commit is reviewed.
        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $this->sha,
        ])->assertOk()->assertJsonPath('skip', false)->assertJsonPath('last_reviewed_commit', null);

        $this->reviewed($this->repository, 570, $this->sha);

        // Without the flag this is the whole point of the precheck: nothing changed.
        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $this->sha,
        ])->assertOk()->assertJsonPath('reason', 'already-reviewed');

        // With it, a second agent gets to review the same commit anyway.
        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $this->sha,
            'build' => '88',
            'force' => true,
        ])->assertOk()->assertJsonPath('skip', false);
    }

    public function test_a_forced_run_still_leaves_a_draft_alone(): void
    {
        Http::fake(['*/pullrequests/571/comments*' => Http::response(['values' => []])]);
        Http::fake(['*/pullrequests/571' => Http::response([
            'id' => 571,
            'title' => 'PROJ-1 Work in progress',
            'state' => 'OPEN',
            'draft' => true,
            'source' => ['branch' => ['name' => 'feature/wip'], 'commit' => ['hash' => $this->sha]],
            'destination' => ['branch' => ['name' => 'master']],
        ])]);

        // Force means "this commit was reviewed, review it again", not "review
        // things the settings say not to review".
        $this->openReview([
            'pull_request_id' => 571,
            'commit' => $this->sha,
            'force' => true,
        ])->assertOk()->assertJsonPath('reason', 'draft');
    }

    public function test_the_headline_model_is_the_one_that_did_the_work(): void
    {
        $this->startRun();

        // A real review: Sonnet does the reviewing, Haiku picks up a small task,
        // and Haiku's session lands in the transcript first. The headline model
        // used to be whichever key came first, which made a 1.8M-token Sonnet
        // review read as a Haiku one.
        $this->fileReview([
            'branch' => 'feature/review',
            'commit' => $this->sha,
            'build' => 77,
            'result' => 'PASSED',
            'summary' => 'No findings.',
            'findings' => [],
            'usage' => [
                'claude-haiku-4-5-20251001' => ['input' => 16, 'output' => 2_080, 'cache_read' => 15_126],
                'claude-sonnet-4-6' => ['input' => 52, 'output' => 15_989, 'cache_read' => 1_633_541],
            ],
        ])->assertOk();

        $run = ReviewRun::firstOrFail();

        $this->assertSame('claude-sonnet-4-6', $run->model);
        // Both are still recorded; only the headline changed.
        $this->assertCount(2, $run->usage);
    }

    public function test_a_retried_step_reuses_its_record(): void
    {
        $this->startRun();
        $this->startRun();

        $this->assertSame(1, ReviewRun::count());
    }

    public function test_the_report_closes_the_run(): void
    {
        $this->startRun();

        $response = $this->fileReview([
            'branch' => 'feature/review',
            'commit' => $this->sha,
            'build' => 77,
            'pull_request_id' => 570,
            'result' => 'FAILED',
            'summary' => 'One HIGH finding.',
            'findings' => [
                ['id' => 'F1', 'severity' => 'HIGH', 'status' => 'new', 'path' => 'app/X.php'],
                ['id' => 'F2', 'severity' => 'LOW', 'status' => 'fixed', 'path' => 'app/Y.php'],
            ],
        ]);

        $response->assertOk();

        $run = ReviewRun::firstOrFail();

        $this->assertSame(ReviewRun::STATUS_FINISHED, $run->status);
        $this->assertSame('FAILED', $run->result);
        $this->assertSame('One HIGH finding.', $run->summary);
        $this->assertCount(2, $run->findings);
        $this->assertSame(1, $run->openFindings());
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->duration_seconds);
        $this->assertSame(1, ReviewRun::count());
    }

    public function test_a_report_without_a_start_is_still_recorded(): void
    {
        // The opening call can fail — the review server was unreachable when the run began —
        // and the result must not be lost because of it.
        $this->fileReview([
            'branch' => 'feature/review',
            'commit' => $this->sha,
            'build' => 99,
            'result' => 'PASSED',
            'summary' => 'No findings.',
            'findings' => [],
        ])->assertOk();

        $run = ReviewRun::firstOrFail();

        $this->assertSame(ReviewRun::STATUS_FINISHED, $run->status);
        $this->assertNotNull($run->started_at);

        // The start time is invented to satisfy the column, so the duration is
        // unknown rather than zero. Reporting a review that took eight minutes as
        // taking none is worse than admitting the clock was never started.
        $this->assertNull($run->duration_seconds);
    }

    public function test_a_step_that_timed_itself_is_believed_when_the_server_missed_the_start(): void
    {
        // A comparison run takes no lock, so on a PROJ that never opened its record
        // there is no start to subtract from. The step measured it instead.
        $this->fileReview([
            'branch' => 'feature/review',
            'commit' => $this->sha,
            'build' => 99,
            'result' => 'PASSED',
            'summary' => 'No findings.',
            'findings' => [],
            'duration' => 315,
        ])->assertOk();

        $this->assertSame(315, ReviewRun::firstOrFail()->duration_seconds);
    }

    public function test_the_servers_own_clock_beats_the_steps_measurement(): void
    {
        $this->freezeSecond();

        $this->startRun();

        $this->travel(90)->seconds();

        $this->fileReview([
            'branch' => 'feature/review',
            'commit' => $this->sha,
            'build' => 77,
            'result' => 'PASSED',
            'summary' => 'No findings.',
            'findings' => [],
            // The step times the agent; the review server times the run, which starts earlier.
            // When both are available the one that covers more of it wins.
            'duration' => 12,
        ])->assertOk();

        $this->assertSame(90, ReviewRun::firstOrFail()->duration_seconds);
    }

    public function test_the_sweep_times_out_a_run_that_never_reported(): void
    {
        $this->startRun();

        ReviewRun::query()->update([
            'started_at' => now()->subMinutes(ReviewRun::TIMEOUT_MINUTES + 5),
        ]);

        $this->artisan('review:sweep-runs')->assertSuccessful();

        $this->assertSame(ReviewRun::STATUS_TIMEOUT, ReviewRun::firstOrFail()->status);
    }

    public function test_the_sweep_leaves_a_recent_run_alone(): void
    {
        $this->startRun();

        $this->artisan('review:sweep-runs')->assertSuccessful();

        $this->assertSame(ReviewRun::STATUS_RUNNING, ReviewRun::firstOrFail()->status);
    }

    public function test_it_skips_when_nothing_that_matters_changed(): void
    {
        $this->startRun();
        $this->finishRun();

        // A different build, same everything else: the first run's answer still holds.
        $this->openReview(['pull_request_id' => 570, 'commit' => "{$this->sha}", 'lock' => '78'])
            ->assertJsonPath('skip', true)
            ->assertJsonPath('reason', 'unchanged');
    }

    public function test_a_changed_ticket_reviews_again_on_the_same_commit(): void
    {
        $this->startRun();
        $this->finishRun();

        // The acceptance criteria moved, so the same code deserves another look.
        $this->fakeTicket('# Ticket, now with a new criterion');

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$this->sha}", 'lock' => '79'])
            ->assertJsonPath('skip', false);
    }

    public function test_a_newer_review_recipe_reviews_again(): void
    {
        $this->openReview(['pull_request_id' => 570, 'commit' => "{$this->sha}", 'lock' => '77', 'recipe' => 'v1'])
            ->assertJsonPath('skip', false);

        $this->finishRun();

        // Improving the review has to re-review, or the improvement never lands.
        $this->openReview(['pull_request_id' => 570, 'commit' => "{$this->sha}", 'lock' => '80', 'recipe' => 'v2'])
            ->assertJsonPath('skip', false);
    }

    public function test_turning_tasks_on_reviews_again(): void
    {
        $this->openReview(['pull_request_id' => 570, 'commit' => "{$this->sha}", 'lock' => '77', 'create_tasks' => false])
            ->assertJsonPath('skip', false);

        $this->finishRun();

        // The findings were reported without tasks; turning tasks on has to review
        // again, or the tasks it was turned on for are never created.
        $this->openReview(['pull_request_id' => 570, 'commit' => "{$this->sha}", 'lock' => '78', 'create_tasks' => true])
            ->assertJsonPath('skip', false);
    }

    public function test_the_same_task_setting_still_skips(): void
    {
        $this->openReview(['pull_request_id' => 570, 'commit' => "{$this->sha}", 'lock' => '77', 'create_tasks' => true])
            ->assertJsonPath('skip', false);

        $this->finishRun();

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$this->sha}", 'lock' => '78', 'create_tasks' => true])
            ->assertJsonPath('skip', true)
            ->assertJsonPath('reason', 'unchanged');
    }

    public function test_an_unpublished_report_is_recorded_without_touching_the_pull_request(): void
    {
        $this->startRun();

        $this->finishRun(report: [
            'pull_request_id' => 570,
            'result' => 'FAILED',
            'findings' => [['id' => 'F1', 'severity' => 'HIGH', 'status' => 'new', 'path' => 'app/X.php', 'line' => 3]],
            'publish' => false,
        ]);

        $run = ReviewRun::firstOrFail();
        $this->assertSame(ReviewRun::STATUS_UNPUBLISHED, $run->status);
        $this->assertSame('FAILED', $run->result);
        $this->assertCount(1, $run->findings);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'bitbucket.org') && $request->method() !== 'GET');

        // Nobody saw it, so it answers nothing: the real run still reviews.
        $this->openReview(['pull_request_id' => 570, 'commit' => "{$this->sha}", 'lock' => '78'])
            ->assertJsonPath('skip', false);
    }

    public function test_the_run_records_which_reviewer_ran_and_the_report_has_the_last_word(): void
    {
        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $this->sha,
            'lock' => '77',
            'agent' => 'ocr',
            'provider' => 'openai-responses',
            'effort' => 'medium',
            'custom' => true,
        ])->assertOk();

        $run = ReviewRun::firstOrFail();
        $this->assertSame(['ocr', 'openai-responses', 'medium', true], [$run->agent, $run->provider, $run->effort, $run->custom]);

        // The config file the agent read set no effort, so the runtime default applied.
        $this->finishRun(report: ['agent' => 'ocr', 'provider' => 'openai-responses', 'effort' => null]);

        $run->refresh();
        $this->assertSame(['ocr', 'openai-responses', null, true], [$run->agent, $run->provider, $run->effort, $run->custom]);
    }

    public function test_an_unfinished_run_does_not_count_as_an_answer(): void
    {
        $this->startRun();

        // The reviewer died without reporting, so its job released nothing but its lock.
        $this->endRun($this->repository, 'feature/review', $this->sha, '77');

        // The question was never answered, so the next build has to ask it.
        $this->openReview(['pull_request_id' => 570, 'commit' => "{$this->sha}", 'lock' => '81'])
            ->assertJsonPath('skip', false);
    }

    public function test_releasing_the_lock_closes_a_run_that_never_reported(): void
    {
        $this->startRun();

        // The review step failed, so only the end of its job is left.
        $this->endRun($this->repository, 'feature/review', $this->sha, '77');

        $run = ReviewRun::firstOrFail();
        $this->assertSame(ReviewRun::STATUS_FAILED, $run->status);
        $this->assertNotNull($run->finished_at);
    }

    public function test_it_records_tokens_per_model_and_per_type(): void
    {
        $this->startRun();

        $this->fileReview([
            'branch' => 'feature/review',
            'commit' => $this->sha,
            'build' => 77,
            'result' => 'PASSED',
            'findings' => [],
            'model' => 'claude-opus-5',
            'cost' => 0.4231,
            'usage' => [
                'claude-opus-5' => ['input' => 12000, 'output' => 3000, 'cache_read' => 900000, 'cache_creation' => 45000],
                'claude-haiku-4-5' => ['input' => 800, 'output' => 200],
            ],
        ])->assertOk();

        $run = ReviewRun::firstOrFail();

        $this->assertSame('claude-opus-5', $run->model);
        $this->assertSame(900000, $run->usage['claude-opus-5']['cache_read']);
        $this->assertSame(200, $run->usage['claude-haiku-4-5']['output']);

        // The flat columns are sums over every model, for the charts.
        $this->assertSame(12800, $run->input_tokens);
        $this->assertSame(3200, $run->output_tokens);
        $this->assertSame(961000, $run->totalTokens());
        $this->assertSame('0.4231', $run->cost);
    }

    /**
     * A review is many turns over one growing conversation, so most of its input
     * should be the same prefix read again. The share says whether that is
     * happening — output is left out, because it is never cached, and a cache
     * write counts as a miss, because that is what it is.
     */
    public function test_it_works_out_how_much_of_the_input_came_from_the_cache(): void
    {
        $run = new ReviewRun;
        $run->usage = [
            'claude-opus-5' => ['input' => 10000, 'output' => 3000, 'cache_read' => 80000, 'cache_creation' => 10000],
        ];

        // 80000 read, against 10000 fresh and 10000 written. Output is not in it.
        $this->assertSame(0.8, $run->cacheHitRate());
    }

    public function test_a_run_that_read_no_input_has_no_cache_share(): void
    {
        $run = new ReviewRun;

        // Nothing to divide by, and a zero here would read as "cached nothing"
        // rather than "nothing ran".
        $this->assertNull($run->cacheHitRate());

        $run->usage = ['claude-opus-5' => ['output' => 3000]];

        $this->assertNull($run->cacheHitRate());
    }

    public function test_a_run_that_cached_nothing_says_zero(): void
    {
        $run = new ReviewRun;
        $run->usage = ['claude-opus-5' => ['input' => 10000, 'output' => 3000]];

        $this->assertSame(0.0, $run->cacheHitRate());
    }

    public function test_it_ignores_token_types_it_does_not_know(): void
    {
        $this->startRun();

        $this->fileReview([
            'branch' => 'feature/review',
            'commit' => $this->sha,
            'build' => 77,
            'result' => 'PASSED',
            'findings' => [],
            'usage' => ['claude-opus-5' => ['input' => 10, 'something_new' => 99]],
        ])->assertOk();

        $run = ReviewRun::firstOrFail();

        // A new token type must not silently inflate a total that is meant to mean
        // one specific thing.
        $this->assertSame(['claude-opus-5' => ['input' => 10]], $run->usage);
        $this->assertSame(10, $run->totalTokens());
    }

    public function test_an_older_ocr_collectors_cache_writes_are_still_priced(): void
    {
        ModelPrice::create([
            'model' => 'claude-opus-5-5', 'input' => '0.000005', 'output' => '0.000025',
            'cache_read' => '0.0000005', 'cache_creation' => '0.00000625', 'fetched_at' => now(),
        ]);

        $this->startRun();

        // Before 1.0 the OCR collector named cache writes `cache_write`. Dropped as an
        // unknown type, they would cost nothing, and the run would read as cheaper.
        $this->finishRun(report: ['usage' => ['claude-opus-5-5' => ['input' => 1000, 'cache_write' => 100_000]], 'cost' => null]);

        $run = ReviewRun::firstOrFail();

        $this->assertSame(['input' => 1000, 'cache_creation' => 100_000], $run->usage['claude-opus-5-5']);
        // 1000×5e-6 + 100000×6.25e-6
        $this->assertEqualsWithDelta(0.63, (float) $run->cost, 0.0001);
        $this->assertTrue($run->cost_estimated);
    }

    public function test_a_report_without_usage_stays_empty(): void
    {
        $this->startRun();
        $this->finishRun();

        $run = ReviewRun::firstOrFail();

        // Nothing reports usage yet, and an invented zero would read as "free".
        $this->assertNull($run->usage);
        $this->assertNull($run->input_tokens);
        $this->assertNull($run->cost);
        $this->assertSame(0, $run->totalTokens());
    }

    public function test_a_retried_report_without_usage_keeps_what_was_recorded(): void
    {
        $this->startRun();
        $this->finishRun('77', ['usage' => ['claude-sonnet-5' => ['input' => 1000]], 'cost' => 0.05]);

        // A second report — a retried step, say — that carries no numbers means
        // "I have none", not "this run used nothing".
        $this->fileReview([
            'branch' => 'feature/review',
            'commit' => $this->sha,
            'build' => 77,
            'result' => 'PASSED',
            'findings' => [],
        ])->assertOk();

        $run = ReviewRun::firstOrFail();

        $this->assertSame(1000, $run->usage['claude-sonnet-5']['input']);
        $this->assertSame('0.0500', $run->cost);
    }

    public function test_a_short_commit_is_still_accepted(): void
    {
        // Losing a whole result because a pipeline reported an abbreviated sha
        // would be a poor trade.
        $this->fileReview([
            'branch' => 'feature/review',
            'commit' => 'abc1234',
            'result' => 'PASSED',
            'findings' => [],
        ])->assertOk();

        $this->assertSame('abc1234', ReviewRun::firstOrFail()->commit);
    }

    public function test_it_records_what_the_pull_request_moved_to_while_reviewing(): void
    {
        $newer = str_repeat('b', 40);

        $this->startRun();

        // By the time the review published, someone had pushed again.
        $this->head = $newer;

        $this->fileReview([
            'branch' => 'feature/review',
            'commit' => $this->sha,
            'build' => 77,
            'pull_request_id' => 570,
            'result' => 'PASSED',
            'findings' => [],
        ])->assertOk()->assertJsonPath('superseded_by', $newer);

        // The review is kept: it is the baseline the next run reviews from.
        $run = ReviewRun::firstOrFail();
        $this->assertSame(ReviewRun::STATUS_FINISHED, $run->status);
        $this->assertSame($newer, $run->superseded_by);
    }

    public function test_the_next_run_is_told_what_it_is_picking_up(): void
    {
        $newer = str_repeat('b', 40);

        ReviewRun::create([
            'tenant_id' => $this->repository->tenant_id,
            'repository_id' => $this->repository->getKey(),
            'workspace' => 'example-co',
            'slug' => 'storefront',
            'branch' => 'feature/review',
            'pull_request_id' => 570,
            'commit' => $this->sha,
            'superseded_by' => $newer,
            'status' => ReviewRun::STATUS_FINISHED,
            'findings' => [['id' => 'F1', 'status' => 'open', 'severity' => 'HIGH']],
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(5),
        ]);

        $this->head = $newer;

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $newer,
            'lock' => '78',
        ])
            ->assertJsonPath('skip', false)
            ->assertJsonPath('handover.reviewed_up_to', $this->sha)
            ->assertJsonPath('handover.open_findings', 1);
    }

    public function test_an_ordinary_run_has_no_handover(): void
    {
        $this->startRun();

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $this->sha,
            'lock' => '77',
        ])->assertJsonPath('handover', null);
    }

    public function test_a_follow_up_is_given_what_each_finding_actually_claimed(): void
    {
        $sha = str_repeat('a', 40);
        $next = str_repeat('b', 40);

        // A finished run stored the full findings, including the source line each
        // one rests on.
        ReviewRun::create([
            'tenant_id' => $this->repository->tenant_id,
            'repository_id' => $this->repository->getKey(),
            'workspace' => 'example-co',
            'slug' => 'storefront',
            'branch' => 'feature/review',
            'pull_request_id' => 570,
            'commit' => $sha,
            'status' => ReviewRun::STATUS_FINISHED,
            'findings' => [[
                'id' => 'F1',
                'severity' => 'HIGH',
                'status' => 'open',
                'path' => 'app/X.php',
                'line' => 88,
                'title' => 'Null dereference on an empty result',
                'details' => 'find() may return null here.',
                'anchor' => '$user = User::find($id);',
                // The whole snippet and where it ends, so a follow-up matches the
                // finding on more than one line that may repeat in the file.
                'existing_code' => '$user = User::find($id);'."\n".'$user->touch();',
                'end_line' => 89,
                // Already like this before the change, which blocks nothing.
                'introduced' => false,
                'category' => 'security',
            ]],
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(5),
        ]);

        // The pull request only carries the markers, which cannot hold any of that.
        // The description is where the review writes them; the finding's own comment
        // is what the id points at.
        $this->description = "[//]: # (ai-review-start)\n\n[//]: # (ai-review reviewed={$sha})\n"
            ."[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/X.php line=88)\n"
            .'[//]: # (ai-review-end)';

        $this->comments = [[
            'id' => 9,
            'content' => ['raw' => "**HIGH**\n\n[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/X.php line=88)"],
            'created_on' => '2026-09-21T10:00:00+00:00',
            'updated_on' => '2026-09-21T10:00:00+00:00',
            'user' => ['display_name' => 'Claude'],
        ]];

        $this->head = $next;

        $response = $this->openReview([
            'pull_request_id' => 570,
            'commit' => $next,
            'lock' => '78',
        ]);

        $response->assertJsonPath('findings.0.id', 'F1');
        // From the marker...
        $response->assertJsonPath('findings.0.status', 'open');
        $response->assertJsonPath('findings.0.comment_id', 9);
        // ...and the rest from what the run stored, so the follow-up does not have
        // to read the comment back and work out what it meant.
        $response->assertJsonPath('findings.0.title', 'Null dereference on an empty result');
        $response->assertJsonPath('findings.0.anchor', '$user = User::find($id);');
        $response->assertJsonPath('findings.0.category', 'security');
        $response->assertJsonPath('findings.0.existing_code', '$user = User::find($id);'."\n".'$user->touch();');
        $response->assertJsonPath('findings.0.end_line', 89);
        $response->assertJsonPath('findings.0.introduced', false);
    }

    public function test_another_pull_requests_findings_are_not_this_ones(): void
    {
        $sha = str_repeat('a', 40);
        $next = str_repeat('b', 40);

        // This pull request's own run, and a later one on another pull request.
        // Finding ids start at F1 on every pull request, so the later run's F1 is a
        // different finding altogether.
        $this->storedRun(570, $sha, now()->subMinutes(20), [['id' => 'F1', 'title' => 'Ours', 'anchor' => 'ours();']]);
        $this->storedRun(571, str_repeat('c', 40), now()->subMinutes(5), [['id' => 'F1', 'title' => 'Theirs', 'anchor' => 'theirs();']]);

        $this->withOneFinding($sha, $next);

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $next,
            'lock' => '78',
        ])
            ->assertJsonPath('findings.0.title', 'Ours')
            ->assertJsonPath('findings.0.anchor', 'ours();');
    }

    public function test_a_finding_the_latest_run_left_out_is_still_described(): void
    {
        $sha = str_repeat('a', 40);
        $next = str_repeat('b', 40);

        // The finding was described by the earlier run; the latest one reported
        // something else and left F1 out.
        $this->storedRun(570, str_repeat('d', 40), now()->subMinutes(30), [['id' => 'F1', 'title' => 'Described earlier', 'anchor' => 'earlier();', 'category' => 'performance']]);
        $this->storedRun(570, $sha, now()->subMinutes(5), [['id' => 'F2', 'title' => 'Something else']]);

        $this->withOneFinding($sha, $next);

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $next,
            'lock' => '78',
        ])
            ->assertJsonPath('findings.0.title', 'Described earlier')
            ->assertJsonPath('findings.0.anchor', 'earlier();')
            ->assertJsonPath('findings.0.category', 'performance');
    }

    /**
     * A finished run on a pull request, with the findings it stored.
     *
     * @param  array<int, array<string, mixed>>  $findings
     */
    protected function storedRun(int $pullRequestId, string $commit, \DateTimeInterface $finishedAt, array $findings): void
    {
        ReviewRun::create([
            'tenant_id' => $this->repository->tenant_id,
            'repository_id' => $this->repository->getKey(),
            'workspace' => 'example-co',
            'slug' => 'storefront',
            'branch' => 'feature/review-'.$pullRequestId,
            'pull_request_id' => $pullRequestId,
            'commit' => $commit,
            'status' => ReviewRun::STATUS_FINISHED,
            'findings' => $findings,
            'started_at' => (clone $finishedAt)->modify('-5 minutes'),
            'finished_at' => $finishedAt,
        ]);
    }

    /**
     * The pull request as a reviewed one with a single open finding, F1, on its
     * description and its own comment.
     */
    protected function withOneFinding(string $reviewed, string $head): void
    {
        $this->description = "[//]: # (ai-review-start)\n\n[//]: # (ai-review reviewed={$reviewed})\n"
            ."[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/X.php line=88)\n"
            .'[//]: # (ai-review-end)';

        $this->comments = [[
            'id' => 9,
            'content' => ['raw' => "**HIGH**\n\n[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/X.php line=88)"],
            'created_on' => '2026-09-21T10:00:00+00:00',
            'updated_on' => '2026-09-21T10:00:00+00:00',
            'user' => ['display_name' => 'Claude'],
        ]];

        $this->head = $head;
    }

    public function test_the_marker_wins_on_status(): void
    {
        $sha = str_repeat('a', 40);
        $next = str_repeat('b', 40);

        ReviewRun::create([
            'tenant_id' => $this->repository->tenant_id,
            'repository_id' => $this->repository->getKey(),
            'workspace' => 'example-co',
            'slug' => 'storefront',
            'branch' => 'feature/review',
            'pull_request_id' => 570,
            'commit' => $sha,
            'status' => ReviewRun::STATUS_FINISHED,
            'findings' => [['id' => 'F1', 'status' => 'open', 'title' => 'Was open']],
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(5),
        ]);

        // The description was rewritten since: the finding is fixed. A stored status
        // is only ever as fresh as the run that wrote it.
        $this->description = "[//]: # (ai-review-start)\n\n[//]: # (ai-review reviewed={$sha})\n"
            ."[//]: # (ai-finding id=F1 status=fixed)\n"
            .'[//]: # (ai-review-end)';

        $this->head = $next;

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $next,
            'lock' => '78',
        ])
            ->assertJsonPath('findings.0.status', 'fixed')
            ->assertJsonPath('findings.0.title', 'Was open');
    }

    /**
     * @return array<string, mixed>
     */
    private function release(string $commit, string $build): array
    {
        return $this->endRun($this->repository, 'feature/review', $commit, $build);
    }

    public function test_a_turned_away_run_is_kept_and_started_again_on_release(): void
    {
        $next = str_repeat('b', 40);

        $this->startRun();

        // A second push while the first review is working: it is the head now, and
        // it is turned away and recorded.
        $this->head = $next;

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $next,
            'lock' => '78',
        ])->assertStatus(409)->assertJsonPath('pending', true);

        $pending = ReviewRun::where('commit', $next)->firstOrFail();
        $this->assertSame(ReviewRun::STATUS_PENDING, $pending->status);

        $released = $this->release($this->sha, '77');

        $this->assertTrue($released['released']);
        $this->assertSame($next, $released['restarted']['commit']);
        $this->assertTrue($released['restarted']['started']);

        // It is no longer waiting for anything, and it says which build took over —
        // the replacement is a different build with a record of its own.
        $pending->refresh();
        $this->assertSame(ReviewRun::STATUS_RESTARTED, $pending->status);
        $this->assertSame($released['restarted']['build_number'], $pending->restarted_as);

        // Pinned to the commit that was skipped, not to whatever the branch is now.
        Queue::assertPushed(fn (RunContainerReview $job): bool => $job->commit === $next
            && $job->branch === 'feature/review'
            && $job->build === $pending->restarted_as);
    }

    public function test_only_the_newest_waiting_run_is_started(): void
    {
        $second = str_repeat('b', 40);
        $third = str_repeat('c', 40);

        $this->startRun();

        foreach ([[$second, '78'], [$third, '79']] as [$commit, $build]) {
            // Each push in turn is the head when its own build starts.
            $this->head = $commit;

            $this->openReview([
                'pull_request_id' => 570,
                'commit' => $commit,
                'lock' => $build,
            ])->assertStatus(409);
        }

        $released = $this->release($this->sha, '77');

        // The newest is the pull request as it now stands; the one before it is
        // already inside that diff, so starting it too would review it twice.
        $this->assertSame($third, $released['restarted']['commit']);
        Queue::assertPushed(RunContainerReview::class, 1);

        // Overtaken, not failed: nothing went wrong with it.
        $skipped = ReviewRun::where('commit', $second)->firstOrFail();
        $this->assertSame(ReviewRun::STATUS_SKIPPED, $skipped->status);
        $this->assertSame($third, $skipped->superseded_by);
        $this->assertNotNull($skipped->restarted_at);

        $this->assertSame(
            ReviewRun::STATUS_RESTARTED,
            ReviewRun::where('commit', $third)->firstOrFail()->status,
        );
    }

    public function test_a_run_is_never_started_twice(): void
    {
        $next = str_repeat('b', 40);

        $this->startRun();
        $this->head = $next;

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $next,
            'lock' => '78',
        ])->assertStatus(409);

        $this->assertSame($next, $this->release($this->sha, '77')['restarted']['commit']);

        // Whatever happens next must not start it again: that is how a handover
        // becomes a loop.
        $this->startRun('80');
        $this->assertNull($this->release($this->sha, '80')['restarted']);
    }

    public function test_a_run_turned_away_cannot_start_anything(): void
    {
        $next = str_repeat('b', 40);

        $this->startRun();
        $this->head = $next;

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $next,
            'lock' => '78',
        ])->assertStatus(409);

        // Build 78 never held the lock, so its own final step hands over nothing.
        $released = $this->release($next, '78');

        $this->assertFalse($released['released']);
        $this->assertNull($released['restarted']);
    }

    public function test_reporting_the_result_releases_the_lock_and_starts_the_waiting_run(): void
    {
        $next = str_repeat('b', 40);

        $this->startRun();
        $this->head = $next;

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $next,
            'lock' => '78',
        ])->assertStatus(409);

        // The review is published by the time the report arrives, so the waiting
        // run can start now rather than after the rest of the pipeline winds down.
        $this->fileReview([
            'branch' => 'feature/review',
            'commit' => $this->sha,
            'build' => 77,
            'pull_request_id' => 570,
            'result' => 'PASSED',
            'findings' => [],
        ])
            ->assertOk()
            ->assertJsonPath('handover.released', true)
            ->assertJsonPath('handover.restarted.commit', $next)
            ->assertJsonPath('handover.restarted.started', true);

        // The final step still runs, and finds nothing left to do.
        $this->release($this->sha, '77');
        // Once, not once per release.
        Queue::assertPushed(RunContainerReview::class, 1);
    }

    public function test_the_final_step_still_hands_over_when_nothing_was_reported(): void
    {
        $next = str_repeat('b', 40);

        $this->startRun();
        $this->head = $next;

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $next,
            'lock' => '78',
        ])->assertStatus(409);

        // The review died without reporting; the final step is the only thing left.
        $released = $this->release($this->sha, '77');

        $this->assertTrue($released['released']);
        $this->assertSame($next, $released['restarted']['commit']);
    }

    public function test_a_report_without_a_cost_is_costed_from_its_tokens(): void
    {
        ModelPrice::create([
            'model' => 'claude-sonnet-4-6',
            'input' => '0.000003',
            'output' => '0.000015',
            'cache_read' => '0.0000003',
            'cache_creation' => '0.00000375',
            'fetched_at' => now(),
        ]);

        $this->startRun();

        // A review over ACP reports its cost on the protocol stream, which the
        // pipeline never sees — but the tokens are exact.
        $this->fileReview([
            'branch' => 'feature/review',
            'commit' => $this->sha,
            'build' => 77,
            'result' => 'PASSED',
            'findings' => [],
            'usage' => ['claude-sonnet-4-6' => ['input' => 40, 'output' => 8018, 'cache_read' => 1094511, 'cache_creation' => 112237]],
        ])->assertOk();

        $run = ReviewRun::firstOrFail();

        $this->assertEqualsWithDelta(0.8696, (float) $run->cost, 0.0001);
        // Said to be derived, not measured.
        $this->assertTrue($run->cost_estimated);
    }

    public function test_a_reported_cost_is_not_overwritten_by_an_estimate(): void
    {
        ModelPrice::create([
            'model' => 'claude-sonnet-4-6',
            'input' => '0.000003',
            'output' => '0.000015',
            'cache_read' => '0.0000003',
            'cache_creation' => '0.00000375',
            'fetched_at' => now(),
        ]);

        $this->startRun();

        $this->fileReview([
            'branch' => 'feature/review',
            'commit' => $this->sha,
            'build' => 77,
            'result' => 'PASSED',
            'findings' => [],
            'cost' => 0.5,
            'usage' => ['claude-sonnet-4-6' => ['input' => 1000000]],
        ])->assertOk();

        $run = ReviewRun::firstOrFail();

        // What the run measured beats what a price list implies.
        $this->assertSame('0.5000', $run->cost);
        $this->assertFalse($run->cost_estimated);
    }

    public function test_switching_model_reviews_again(): void
    {
        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $this->sha,
            'lock' => '77',
            'model' => 'claude-sonnet-5',
        ])->assertJsonPath('skip', false);

        $this->finishRun();

        // Same commit, same everything — except the model that would review it.
        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $this->sha,
            'lock' => '78',
            'model' => 'claude-opus-5',
        ])->assertJsonPath('skip', false);
    }

    public function test_the_same_model_still_skips(): void
    {
        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $this->sha,
            'lock' => '77',
            'model' => 'claude-sonnet-5',
        ])->assertJsonPath('skip', false);

        $this->finishRun();

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $this->sha,
            'lock' => '78',
            'model' => 'claude-sonnet-5',
        ])
            ->assertJsonPath('skip', true)
            ->assertJsonPath('reason', 'unchanged');
    }

    public function test_a_run_is_only_ever_recorded_against_its_own_repository(): void
    {
        $other = Repository::factory()->create(['workspace' => 'example-co', 'slug' => 'another-project']);

        $this->fileReview([
            'branch' => 'feature/review',
            'commit' => $this->sha,
            'result' => 'PASSED',
        ], $other)->assertOk();

        $run = ReviewRun::firstOrFail();

        $this->assertSame($other->getKey(), $run->repository_id);
        $this->assertSame($other->tenant_id, $run->tenant_id);
    }
}
