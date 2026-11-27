<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\RunPresenter;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Services\ReviewContext;
use App\Services\ReviewPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Review Central puts the review on the pull request when the report arrives.
 *
 * Everything here goes through ReviewFiling rather than the publisher directly:
 * that is the only way a review reaches the pull request, so it is the contract
 * worth holding.
 */
class ReviewPublisherTest extends TestCase
{
    use RefreshDatabase;

    protected Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = $this->reviewing(Repository::factory()->create(['workspace' => 'example-co', 'slug' => 'storefront', 'name' => 'Storefront']));

        $this->repository = $repository;
    }

    /**
     * One callback rather than a map of URL patterns: Http::fake() merges stubs
     * instead of replacing them, and a GET and a POST to the same path cannot be
     * told apart by pattern anyway.
     *
     * @param  array<int, array<string, mixed>>  $comments
     * @param  array<int, array<string, mixed>>  $tasks
     */
    /**
     * The pull request's tasks, read live by the fake.
     *
     * A property rather than a captured value because Http::fake() merges stubs
     * instead of replacing them: calling fakeBitbucket() a second time leaves the
     * first callback answering, so a test that models two runs has to change the
     * state the one callback reads.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $tasks = [];

    /** The pull request's description, read live by the fake so a test can set it. */
    protected string $description = '';

    /** The pull request's state, read live by the fake so a test can merge it. */
    protected string $state = 'OPEN';

    /** The pull request's head, read live by the fake so a test can push to it. */
    protected string $head = 'abc1234def5678';

    protected function fakeBitbucket(array $comments = [], array $tasks = [], bool $postsFail = false): void
    {
        $this->tasks = $tasks;

        Http::fake(function (Request $request) use ($comments, $postsFail) {
            // Bitbucket refusing the write is what a failed publication looks like
            // in practice: the review server can read the pull request, but nothing it posts
            // lands.
            if ($postsFail
                && (($request->method() === 'POST' && str_contains($request->url(), '/comments'))
                    || ($request->method() === 'PUT'
                        && preg_match('#/pullrequests/\d+$#', (string) parse_url($request->url(), PHP_URL_PATH))))) {
                return Http::response(['error' => ['message' => 'nope']], 500);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/tasks')) {
                return Http::response(['values' => $this->tasks]);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/comments')) {
                return Http::response(['values' => $comments]);
            }

            if ($request->method() === 'GET' && preg_match('#/commit/[0-9a-f]+$#', (string) parse_url($request->url(), PHP_URL_PATH))) {
                return Http::response([
                    'message' => "Guard the scheduler behind ASANA_SYNC\n\nLonger body nobody needs inline.",
                    'author' => ['user' => ['display_name' => 'Alex Doe'], 'raw' => 'Alex Doe <b@example.com>'],
                ]);
            }

            // The pull request itself, for the endpoints that read it.
            if ($request->method() === 'GET' && preg_match('#/pullrequests/\d+$#', (string) parse_url($request->url(), PHP_URL_PATH))) {
                return Http::response([
                    'id' => 570,
                    'title' => 'Publish from the review server',
                    'description' => $this->description,
                    'state' => $this->state,
                    'draft' => false,
                    'source' => ['branch' => ['name' => 'tweak-models'], 'commit' => ['hash' => $this->head]],
                    'destination' => ['branch' => ['name' => 'master']],
                ]);
            }

            return Http::response(['id' => 9001], 201);
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function task(int $id, string $raw, string $state = 'UNRESOLVED'): array
    {
        return ['id' => $id, 'state' => $state, 'content' => ['raw' => $raw]];
    }

    /**
     * The text of every task created, so a test can say what was asked for.
     *
     * @return array<int, string>
     */
    protected function createdTasks(): array
    {
        $created = [];

        Http::recorded(function (Request $request) use (&$created): bool {
            if ($request->method() === 'POST' && str_contains($request->url(), '/tasks')) {
                $created[] = $request->data()['content']['raw'] ?? '';
            }

            return false;
        });

        return $created;
    }

    /**
     * @return array<string, mixed>
     */
    protected function comment(int $id, string $raw, bool $resolved = false): array
    {
        return [
            'id' => $id,
            'content' => ['raw' => $raw],
            'resolution' => $resolved ? ['type' => 'pullrequest_comment_resolution'] : null,
            'created_on' => '2026-09-20T10:00:00+00:00',
            'updated_on' => '2026-09-20T10:00:00+00:00',
            'user' => ['display_name' => 'AI review'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    protected function report(array $findings = [], string $commit = 'abc1234def5678', array $extra = []): TestResponse
    {
        return $this->fileReview(array_merge([
            'branch' => 'tweak-models',
            'commit' => $commit,
            'pull_request_id' => 570,
            'result' => 'FAILED',
            'summary' => 'Adds the publisher.',
            'findings' => $findings,
        ], $extra));
    }

    /**
     * @return array<string, mixed>
     */
    protected function finding(array $overrides = []): array
    {
        return array_merge([
            'id' => 'F1',
            'severity' => 'HIGH',
            'status' => 'new',
            'path' => 'app/Services/ReviewPublisher.php',
            'line' => 42,
            'title' => 'Publishing is not idempotent',
            'details' => 'A second run posts the finding again. Match on the marker instead.',
            'prompt' => 'In app/Services/ReviewPublisher.php, match findings on their marker id rather than on text, and add a test for a second run.',
        ], $overrides);
    }

    /**
     * A finding with nowhere to put a comment - something that is missing rather
     * than something that is wrong in a particular place.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function pathless(array $overrides = []): array
    {
        return $this->finding(array_merge([
            'path' => null,
            'line' => null,
            'title' => 'No tests cover the scheduler change',
        ], $overrides));
    }

    /**
     * The description the review wrote, or null when it wrote none.
     */
    protected function publishedDescription(): ?string
    {
        $written = null;

        Http::recorded(function (Request $request) use (&$written): bool {
            if ($request->method() === 'PUT'
                && preg_match('#/pullrequests/\d+$#', (string) parse_url($request->url(), PHP_URL_PATH))) {
                $written = $request->data()['description'] ?? null;
            }

            return false;
        });

        return $written;
    }

    /**
     * Every POST of a comment body, so a test can say what reached Bitbucket.
     *
     * @return array<int, string>
     */
    protected function postedComments(): array
    {
        $posted = [];

        Http::recorded(function (Request $request) use (&$posted): bool {
            // Resolving a thread is a POST under the comment too, with no content.
            if ($request->method() === 'POST' && str_contains($request->url(), '/comments') && ! str_contains($request->url(), '/resolve')) {
                $posted[] = $request->data()['content']['raw'] ?? '';
            }

            return false;
        });

        return $posted;
    }

    public function test_it_writes_the_review_into_the_description_when_there_is_none(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding()])->assertOk()->assertJsonPath('publish.published', true);

        $summary = $this->publishedDescription();

        $this->assertNotNull($summary, 'The review wrote no description.');
        $this->assertStringContainsString('[//]: # (ai-review-start)', $summary);
        $this->assertStringContainsString('[//]: # (ai-review-end)', $summary);
        $this->assertStringContainsString('**Result:** Changes requested', $summary);
        $this->assertStringContainsString('Publishing is not idempotent', $summary);
        $this->assertStringContainsString('[//]: # (ai-review reviewed=abc1234def5678 mode=both at=', $summary);
    }

    public function test_the_summary_links_to_rate_this_run(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding()])->assertOk();

        $run = ReviewRun::where('repository_id', $this->repository->id)->firstOrFail();

        $this->assertStringContainsString(
            '[Rate this review]('.route('reviews.rate', ['token' => $run->rating_token]).')',
            $this->publishedDescription(),
        );
    }

    public function test_the_summary_carries_a_copyable_prompt_for_what_is_still_open(): void
    {
        $this->fakeBitbucket();

        // No prompts, so the block falls back to the title and the first sentence.
        $this->report([
            $this->finding(['prompt' => null]),
            $this->finding(['id' => 'F2', 'severity' => 'MEDIUM', 'prompt' => null, 'title' => 'Timeout is too short', 'details' => 'Ninety seconds. The PROJ publishes before it answers.']),
        ])->assertOk();

        $summary = $this->publishedDescription();

        $this->assertStringContainsString('### Fix these', $summary);
        $this->assertStringContainsString('```text', $summary);
        $this->assertStringContainsString(
            'Check the review findings on example-co/storefront pull request 570 (branch tweak-models) against the code, and review the suggested fixes before applying them.',
            $summary,
        );
        // Worst first, numbered, each with the first sentence of its details as context.
        $this->assertStringContainsString('1. app/Services/ReviewPublisher.php:42 · finding F1 — Publishing is not idempotent. A second run posts the finding again.', $summary);
        $this->assertStringContainsString('2. app/Services/ReviewPublisher.php:42 · finding F2 — Timeout is too short. Ninety seconds.', $summary);
    }

    /**
     * Pasting the block hands a coding agent the instruction. Without the reason it
     * can only take that instruction on trust, which is the opposite of what a
     * finding is. How to answer one on the pull request is the agent's own skills'
     * business, so the block does not explain the tools.
     */
    public function test_the_copyable_prompt_carries_the_reason_and_not_the_tools(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding()])->assertOk();

        $summary = $this->publishedDescription();

        $this->assertStringContainsString('Why: A second run posts the finding again.', $summary);
        $this->assertStringNotContainsString('pull-request-feedback', $summary);
        $this->assertStringNotContainsString('reply-to-comment', $summary);
    }

    /**
     * The fallback line already ends in that very sentence, so repeating it below
     * would read as two different reasons for the same finding.
     */
    public function test_a_finding_without_a_prompt_gets_no_separate_reason_line(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding(['prompt' => null])])->assertOk();

        $this->assertStringNotContainsString('Why:', $this->publishedDescription());
    }

    public function test_the_reason_stops_at_the_first_paragraph(): void
    {
        $this->fakeBitbucket();

        // No full stop to cut at, so only the line break keeps the fix out of the
        // reason and the next numbered line intact.
        $this->report([$this->finding(['details' => "A second run posts the finding again\n\nMatch on the marker instead."])])->assertOk();

        $this->assertStringContainsString("Why: A second run posts the finding again.\n```", $this->publishedDescription());
    }

    public function test_the_copyable_prompt_is_left_out_when_nothing_is_open(): void
    {
        $this->fakeBitbucket();

        // An empty "fix these" reads as though the review did not finish.
        $this->report([$this->finding(['status' => 'fixed'])])->assertOk();

        $summary = $this->publishedDescription();

        $this->assertStringNotContainsString('### Fix these', $summary);
        $this->assertStringContainsString('✅ Fixed', $summary);
    }

    public function test_it_keeps_what_a_person_wrote_and_replaces_only_its_own_block(): void
    {
        // A description somebody wrote, with a stale block from an earlier run in it.
        $this->description = "Adds the ASANA_SYNC flag.\n\nSee PROJ-1 for why.\n\n"
            ."[//]: # (ai-review-start)\n\n## AI Review\n\nStale.\n\n"
            ."[//]: # (ai-review reviewed=0000000aaa mode=both at=2026-09-20T10:00:00+00:00)\n"
            .'[//]: # (ai-review-end)';

        $this->fakeBitbucket();

        $this->report([$this->finding()])->assertOk()->assertJsonPath('publish.summary', 'updated');

        $written = $this->publishedDescription();

        $this->assertStringContainsString('Adds the ASANA_SYNC flag.', $written);
        $this->assertStringContainsString('See PROJ-1 for why.', $written);
        $this->assertStringNotContainsString('Stale.', $written);
        // Exactly one block, however many runs have been through it.
        $this->assertSame(1, substr_count($written, '[//]: # (ai-review-start)'));
        $this->assertSame(1, substr_count($written, '[//]: # (ai-review-end)'));
    }

    public function test_work_to_do_and_the_record_of_what_was_are_separate_tables(): void
    {
        $this->fakeBitbucket();

        $this->report([
            $this->finding(['id' => 'F1', 'status' => 'open', 'title' => 'Still broken']),
            $this->finding(['id' => 'F2', 'status' => 'fixed', 'title' => 'Was broken']),
            $this->finding(['id' => 'F3', 'status' => 'acknowledged', 'severity' => 'LOW', 'title' => 'Intentional']),
        ])->assertOk();

        $written = $this->publishedDescription();

        $open = substr($written, strpos($written, '### Open findings'), strpos($written, '### Resolved') - strpos($written, '### Open findings'));
        $resolved = substr($written, strpos($written, '### Resolved'));

        // Each finding appears under one heading, not both.
        $this->assertStringContainsString('Still broken', $open);
        $this->assertStringNotContainsString('Was broken', $open);
        $this->assertStringNotContainsString('Intentional', $open);

        $this->assertStringContainsString('Was broken', $resolved);
        $this->assertStringContainsString('Intentional', $resolved);
        $this->assertStringNotContainsString('Still broken', $resolved);

        // And every one of them still leaves a marker, settled included, or the next
        // run forgets they were ever dealt with.
        foreach (['F1', 'F2', 'F3'] as $id) {
            $this->assertStringContainsString("ai-finding id={$id} ", $written);
        }
    }

    public function test_the_resolved_table_is_left_out_when_nothing_has_been_settled(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding(['status' => 'open'])])->assertOk();

        $written = $this->publishedDescription();

        $this->assertStringContainsString('### Open findings', $written);
        $this->assertStringNotContainsString('### Resolved', $written);
    }

    public function test_nothing_open_says_so_rather_than_showing_an_empty_table(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding(['status' => 'fixed'])], extra: ['result' => 'PASSED'])->assertOk();

        $written = $this->publishedDescription();

        $this->assertStringContainsString('None. Everything the review raised has been dealt with.', $written);
        $this->assertStringContainsString('### Resolved', $written);
    }

    public function test_the_description_carries_the_changes_and_what_to_do_before_release(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding()], extra: [
            'changes' => ['ASANA_SYNC now guards the console schedule', 'Everhour moves to its own key'],
            'before_release' => ['Run php artisan migrate', 'Set ASANA_SYNC=true in the environment'],
        ])->assertOk();

        $written = $this->publishedDescription();

        $this->assertStringContainsString("### Biggest changes\n- ASANA_SYNC now guards the console schedule", $written);
        $this->assertStringContainsString('- Everhour moves to its own key', $written);
        $this->assertStringContainsString("### Before release\n- Run php artisan migrate", $written);
        $this->assertStringContainsString('- Set ASANA_SYNC=true in the environment', $written);
    }

    public function test_a_line_too_long_for_the_description_is_shortened_rather_than_losing_the_review(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding()], extra: [
            'changes' => [str_repeat('a', 501)],
            'before_release' => [str_repeat('b', 501)],
        ])->assertOk();

        $written = $this->publishedDescription();

        $this->assertStringContainsString('- '.str_repeat('a', 500)."...\n", $written);
        $this->assertStringContainsString('- '.str_repeat('b', 500).'...', $written);
    }

    public function test_a_follow_up_that_says_nothing_keeps_what_the_description_already_lists(): void
    {
        // A follow-up review looks at the newest commits only, so it may have nothing
        // to add. Replacing the list with nothing would lose everything the earlier
        // runs described.
        $this->description = "[//]: # (ai-review-start)\n\n## AI Review\n\n"
            ."### Biggest changes\n- Moves the review into the description\n- Records the verdict\n\n"
            ."### Before release\n- php artisan migrate\n\n"
            .'[//]: # (ai-review-end)';

        $this->fakeBitbucket();

        $this->report([$this->finding()], extra: ['changes' => [], 'before_release' => []])->assertOk();

        $written = $this->publishedDescription();

        $this->assertStringContainsString('- Moves the review into the description', $written);
        $this->assertStringContainsString('- Records the verdict', $written);
        $this->assertStringContainsString('- php artisan migrate', $written);
    }

    public function test_what_the_review_does_say_replaces_the_list_rather_than_appending_to_it(): void
    {
        // The review is given the old list and returns the whole one, so what comes
        // back is authoritative - it has already carried forward what still holds.
        $this->description = "[//]: # (ai-review-start)\n\n### Biggest changes\n- Old and now wrong\n\n"
            .'[//]: # (ai-review-end)';

        $this->fakeBitbucket();

        $this->report([$this->finding()], extra: [
            'changes' => ['Old, reworded and still true', 'And something new'],
        ])->assertOk();

        $written = $this->publishedDescription();

        $this->assertStringContainsString('- Old, reworded and still true', $written);
        $this->assertStringContainsString('- And something new', $written);
        $this->assertStringNotContainsString('- Old and now wrong', $written);
    }

    public function test_a_heading_is_left_out_when_the_review_wrote_nothing_for_it(): void
    {
        $this->fakeBitbucket();

        // An empty "before release" reads as though the review did not consider it.
        $this->report([$this->finding()], extra: ['changes' => ['One thing'], 'before_release' => []])->assertOk();

        $written = $this->publishedDescription();

        $this->assertStringContainsString('### Biggest changes', $written);
        $this->assertStringNotContainsString('### Before release', $written);
    }

    public function test_a_request_for_changes_that_did_not_land_is_not_reported_as_success(): void
    {
        // 404 on the POST means the request never landed; the withdrawal of the
        // approval succeeding says nothing about that. Calling it done would leave
        // the merge open with nothing holding it.
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/request-changes')) {
                return Http::response(['error' => ['message' => 'not found']], 404);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/comments')) {
                return Http::response(['values' => []]);
            }

            if ($request->method() === 'GET') {
                return Http::response(['id' => 570, 'title' => 'T', 'description' => '', 'source' => ['branch' => ['name' => 'tweak-models']]]);
            }

            return Http::response(['id' => 9001], 201);
        });

        $this->report([$this->finding()], extra: ['result' => 'FAILED'])
            ->assertOk()
            ->assertJsonPath('publish.verdict', 'refused (changes HTTP 404, approval HTTP 201)');
    }

    public function test_withdrawing_something_that_was_never_set_is_not_a_failure(): void
    {
        // The mirror case: nothing to withdraw is exactly what 404 should mean.
        Http::fake(function (Request $request) {
            if ($request->method() === 'DELETE') {
                return Http::response(['error' => ['message' => 'not found']], 404);
            }

            if ($request->method() === 'GET' && str_contains($request->url(), '/comments')) {
                return Http::response(['values' => []]);
            }

            if ($request->method() === 'GET') {
                return Http::response(['id' => 570, 'title' => 'T', 'description' => '', 'source' => ['branch' => ['name' => 'tweak-models']]]);
            }

            return Http::response(['id' => 9001], 201);
        });

        $this->report([$this->finding(['status' => 'fixed'])], extra: ['result' => 'PASSED'])
            ->assertOk()
            ->assertJsonPath('publish.verdict', 'approved');
    }

    public function test_it_approves_the_pull_request_when_the_review_passes(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding(['status' => 'fixed'])], extra: ['result' => 'PASSED'])
            ->assertOk()
            ->assertJsonPath('publish.verdict', 'approved');

        // Both are said every run: an approval left standing after findings appear
        // would be worse than none at all.
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/approve'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/request-changes'));
    }

    public function test_it_withdraws_any_approval_when_the_review_fails(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding()], extra: ['result' => 'FAILED'])
            ->assertOk()
            ->assertJsonPath('publish.verdict', 'changes requested');

        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/request-changes'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/approve'));
    }

    public function test_a_passing_review_that_leaves_something_for_a_person_neither_approves_nor_asks_for_changes(): void
    {
        $this->fakeBitbucket();

        $point = '`database/migrations/merge_customers.php:31` deletes every customer without an order; whether production holds prospects to keep is not in the repository.';

        $this->report([$this->finding(['status' => 'fixed'])], extra: ['result' => 'PASSED', 'manual_review' => [$point]])
            ->assertOk()
            ->assertJsonPath('publish.verdict', 'manual review');

        // Both are taken back: the pull request waits for a person's approval.
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/approve'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/request-changes'));
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST'
            && (str_contains($r->url(), '/approve') || str_contains($r->url(), '/request-changes')));

        $written = $this->publishedDescription();

        $this->assertStringContainsString('**Result:** Manual review required', $written);
        $this->assertStringContainsString("### For a person to check\n\n- {$point}", $written);
        $this->assertStringContainsString('has not approved it', $written);

        $run = ReviewRun::query()->latest('id')->firstOrFail();

        $this->assertSame([$point], $run->manual_review);
        $this->assertSame(['label' => 'Manual review', 'tone' => 'manual'], RunPresenter::outcome($run));
    }

    public function test_a_failing_review_asks_for_changes_whatever_it_left_for_a_person(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding()], extra: ['result' => 'FAILED', 'manual_review' => ['Whether refunds may exceed the order total is not stated anywhere.']])
            ->assertOk()
            ->assertJsonPath('publish.verdict', 'changes requested');

        $written = $this->publishedDescription();

        $this->assertStringContainsString('**Result:** Changes requested', $written);
        $this->assertStringContainsString('- Whether refunds may exceed the order total is not stated anywhere.', $written);
        $this->assertSame('Changes requested', RunPresenter::outcome(ReviewRun::query()->latest('id')->firstOrFail())['label']);
    }

    public function test_it_approves_nothing_when_the_pull_request_moved_on_while_it_was_reviewed(): void
    {
        $this->fakeBitbucket();
        // Pushed while the review of abc1234 ran.
        $this->head = 'fed9876cba5432';

        $this->report([], extra: ['result' => 'PASSED'])
            ->assertOk()
            ->assertJsonPath('publish.verdict', 'not approved: the pull request moved on to fed9876cba54 while abc1234def56 was reviewed');

        // An approval that stands is taken back: it would cover the newer commit, which nobody reviewed.
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/approve'));
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/approve'));
    }

    public function test_what_was_left_for_a_person_stays_until_a_review_clears_it(): void
    {
        $this->description = "[//]: # (ai-review-start)\n\n## AI Review\n\n**Result:** MANUAL REVIEW REQUIRED\n\n"
            ."### For a person to check\n\n- Whether prospects may be deleted.\n\n"
            ."The review found nothing that holds the merge, and has not approved it: these points are for a person to settle.\n\n"
            .'[//]: # (ai-review-end)';

        $this->fakeBitbucket();

        // A report that does not carry the list, as an older pipeline file sends it.
        $this->report([], extra: ['result' => 'PASSED'])
            ->assertOk()
            ->assertJsonPath('publish.verdict', 'manual review');

        $this->assertStringContainsString('- Whether prospects may be deleted.', $this->publishedDescription());

        // An empty list is the review saying the point no longer applies.
        $this->head = 'def5678abc1234';
        $this->report([], commit: 'def5678abc1234', extra: ['result' => 'PASSED', 'manual_review' => []])
            ->assertOk()
            ->assertJsonPath('publish.verdict', 'approved');

        $written = $this->publishedDescription();

        $this->assertStringNotContainsString('For a person to check', $written);
        $this->assertStringContainsString('**Result:** Approved', $written);
    }

    public function test_a_review_full_of_dollar_signs_does_not_splice_the_old_block_into_the_new(): void
    {
        // The block is PHP written about PHP: prompts name $user, suggestions assign
        // $plan. preg_replace reads `$0` in a replacement as the whole match, so the
        // previous review used to end up nested inside the new one.
        $this->description = "Mine.\n\n---\n\n[//]: # (ai-review-start)\n\n"
            ."## AI Review\n\nThe previous run.\n\n"
            .'[//]: # (ai-review-end)';

        $this->fakeBitbucket();

        $this->report([$this->finding([
            'prompt' => 'In app/X.php, guard $0 and ${user} and $plan before the call.',
        ])])->assertOk();

        $written = $this->publishedDescription();

        $this->assertStringNotContainsString('The previous run.', $written);
        $this->assertSame(1, substr_count($written, '[//]: # (ai-review-start)'));
        $this->assertSame(1, substr_count($written, '[//]: # (ai-review-end)'));
        // Written literally, not read as back-references.
        $this->assertStringContainsString('guard $0 and ${user} and $plan', $written);
        $this->assertStringStartsWith('Mine.', $written);
    }

    public function test_an_empty_description_still_leaves_room_to_write_one(): void
    {
        $this->description = '';
        $this->fakeBitbucket();

        $this->report([$this->finding()])->assertOk();

        $written = $this->publishedDescription();

        // Above the fence, so it is theirs and the next run leaves it alone.
        $this->assertStringStartsWith('_Add a description here.', $written);
        $this->assertLessThan(strpos($written, '[//]: # (ai-review-start)'), strpos($written, '_Add a description here.'));
    }

    public function test_what_someone_writes_over_the_placeholder_survives_the_next_run(): void
    {
        // What the pull request looks like after a first run, once the author has
        // replaced the placeholder with a real description.
        $this->description = "Adds the ASANA_SYNC flag.\n\n---\n\n"
            ."[//]: # (ai-review-start)\n\n## AI Review\n\nStale.\n\n"
            .'[//]: # (ai-review-end)';

        $this->fakeBitbucket();

        $this->report([$this->finding()])->assertOk();

        $written = $this->publishedDescription();

        $this->assertStringStartsWith('Adds the ASANA_SYNC flag.', $written);
        $this->assertStringNotContainsString('_Add a description here.', $written);
        $this->assertStringNotContainsString('Stale.', $written);
        $this->assertSame(1, substr_count($written, '[//]: # (ai-review-start)'));
    }

    public function test_a_description_with_no_block_gets_one_appended_below(): void
    {
        $this->description = 'Adds the ASANA_SYNC flag.';
        $this->fakeBitbucket();

        $this->report([$this->finding()])->assertOk()->assertJsonPath('publish.summary', 'added');

        $written = $this->publishedDescription();

        $this->assertStringStartsWith('Adds the ASANA_SYNC flag.', $written);
        $this->assertStringContainsString("\n\n---\n\n[//]: # (ai-review-start)", $written);
    }

    public function test_it_posts_an_inline_comment_for_a_new_finding(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding()])->assertOk()->assertJsonPath('publish.comments.created', 1);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/comments')) {
                return false;
            }

            $data = $request->data();

            return ($data['inline']['path'] ?? null) === 'app/Services/ReviewPublisher.php'
                && ($data['inline']['to'] ?? null) === 42
                // Led by its id, the name "Fix these" and a reply use for it, with
                // the title on a line of its own.
                && str_starts_with($data['content']['raw'] ?? '', "F1 · HIGH\n\n**Publishing is not idempotent**")
                && str_contains($data['content']['raw'] ?? '', 'id=F1 status=new severity=HIGH path=app/Services/ReviewPublisher.php line=42');
        });
    }

    public function test_it_caps_details_that_would_make_an_unpostable_comment(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding(['details' => str_repeat('x', 9000)])])->assertOk();

        $body = collect($this->postedComments())
            ->first(fn (string $b): bool => str_contains($b, 'F1 · HIGH') && ! str_contains($b, '## AI Review'));

        $this->assertNotNull($body, 'The finding comment was not posted.');
        $this->assertLessThan(9000, strlen($body));
        // Still a usable comment: the marker survives the cap, so the next run can
        // still match this finding.
        $this->assertStringContainsString('[//]: # (ai-finding id=F1', $body);
    }

    public function test_a_finding_comment_carries_a_copyable_prompt(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding()])->assertOk();

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/comments')) {
                return false;
            }

            $body = $request->data()['content']['raw'] ?? '';

            return str_contains($body, '**Prompt for a coding agent:**')
                && str_contains($body, "```text\nIn app/Services/ReviewPublisher.php, match findings on their marker id rather than on text, and add a test for a second run.\n```");
        });
    }

    public function test_a_finding_carries_a_suggestion_when_it_has_one(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding(['suggestion' => '        $plan = $user->subscription?->plan_id;'])])->assertOk();

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/comments')) {
                return false;
            }

            $body = $request->data()['content']['raw'] ?? '';

            // Indentation is kept, and the suggestion comes before the prompt.
            return str_contains($body, "```suggestion\n        \$plan = \$user->subscription?->plan_id;\n```")
                && strpos($body, '```suggestion') < strpos($body, '**Prompt for a coding agent:**');
        });
    }

    public function test_an_overlong_suggestion_is_left_out_rather_than_truncated(): void
    {
        $this->fakeBitbucket();

        // Truncating this would put an ellipsis inside the Apply block, which commits
        // verbatim - so the block is dropped and the prose still says what to do.
        $this->report([$this->finding(['suggestion' => str_repeat("        \$x = 1;\n", 600)])])->assertOk();

        $posted = collect($this->postedComments())->first(fn (string $b): bool => str_contains($b, 'F1 · HIGH'));

        $this->assertNotNull($posted);
        $this->assertStringNotContainsString('```suggestion', $posted);
        $this->assertStringNotContainsString('...', $posted);
        $this->assertStringContainsString('**Prompt for a coding agent:**', $posted);
    }

    public function test_a_finding_first_commented_on_after_report_mode_keeps_its_suggestion(): void
    {
        // What report mode leaves behind: a marker per finding in the summary, and
        // no comment of its own. The finding is familiar; its comment is not there.
        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n"
                ."[//]: # (ai-review reviewed=abc1234def5678 mode=report)\n"
                .'[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/Services/ReviewPublisher.php line=42)'),
        ]);

        $this->report([$this->finding(['status' => 'open', 'suggestion' => '        $plan = null;'])])
            ->assertOk()
            ->assertJsonPath('publish.comments.created', 1);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && ($data['inline']['path'] ?? null) === 'app/Services/ReviewPublisher.php'
                && str_contains($data['content']['raw'] ?? '', '```suggestion');
        });
    }

    public function test_editing_a_general_comment_does_not_give_it_a_suggestion(): void
    {
        // Posted as a general comment on an earlier run, because Bitbucket refused
        // the inline placement. It has no `inline`, so it covers no lines.
        $general = $this->comment(2, "app/Services/ReviewPublisher.php:42\n\n"
            ."**HIGH** — Publishing is not idempotent\n\n"
            ."The wording from the earlier run.\n\n"
            .'[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/Services/ReviewPublisher.php line=42)');

        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $general,
        ]);

        $this->report([$this->finding([
            'status' => 'open',
            'details' => 'Reworded, so the comment has to be edited.',
            'suggestion' => '        $plan = null;',
        ])])->assertOk()->assertJsonPath('publish.comments.updated', 1);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/comments/2')) {
                return false;
            }

            return ! str_contains($request->data()['content']['raw'] ?? '', '```suggestion');
        });
    }

    public function test_a_finding_with_no_line_carries_no_suggestion(): void
    {
        $this->fakeBitbucket();

        // A suggestion replaces the lines its comment covers; this comment covers
        // none, so an Apply button here would do nothing.
        $this->report([$this->finding(['line' => null, 'suggestion' => 'whatever();'])])->assertOk();

        $comment = collect($this->postedComments())->first(fn (string $body): bool => str_contains($body, 'F1 · HIGH'));

        $this->assertNotNull($comment);
        $this->assertStringNotContainsString('```suggestion', $comment);
    }

    public function test_the_general_comment_fallback_drops_the_suggestion(): void
    {
        // Bitbucket rejects the inline placement, so the finding goes up as a general
        // comment - which covers no lines either.
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                // The same answer for the pull request and for its comments.
                return Http::response(['values' => [], 'source' => ['branch' => ['name' => 'tweak-models']]]);
            }

            $data = $request->data();

            if ($request->method() === 'POST' && isset($data['inline'])) {
                return Http::response(['error' => ['message' => 'line not in diff']], 400);
            }

            return Http::response(['id' => 9001], 201);
        });

        $this->report([$this->finding(['suggestion' => '        $plan = null;'])])->assertOk();

        // The inline attempt is recorded too, so take the one that was accepted:
        // the general comment, which opens with the location.
        $posted = collect($this->postedComments())->first(fn (string $b): bool => str_starts_with($b, 'app/Services/ReviewPublisher.php:42'));

        $this->assertNotNull($posted);
        $this->assertStringContainsString('app/Services/ReviewPublisher.php:42', $posted);
        $this->assertStringNotContainsString('```suggestion', $posted);
        $this->assertStringContainsString('**Prompt for a coding agent:**', $posted);
    }

    public function test_a_prompt_containing_a_block_does_not_escape_its_fence(): void
    {
        $this->fakeBitbucket();

        // A review of this codebase writes prompts about markdown constantly. A
        // three-backtick fence round a three-backtick body ends early, and the state
        // markers after it spill into the page as prose.
        $prompt = "In app/Services/ReviewPublisher.php, wrap the block:\n\n```suggestion\n\$x = 1;\n```\n\nand add a test.";

        $this->report([$this->finding(['prompt' => $prompt])])->assertOk();

        $written = $this->publishedDescription();
        $comment = collect($this->postedComments())->first(fn (string $b): bool => str_contains($b, 'F1 · HIGH'));

        foreach (['the description' => $written, 'the finding comment' => $comment] as $where => $body) {
            $this->assertStringContainsString('````', $body, "The fence was not widened in {$where}.");
            // The whole prompt is inside one block, tail included.
            $this->assertStringContainsString('and add a test.', $body);
            $this->assertStringContainsString('```suggestion', $body);
        }

        // And the marker is the last thing in the comment, not prose after a fence
        // that closed early.
        $this->assertStringEndsWith(')', rtrim($comment));
        $this->assertStringContainsString('[//]: # (ai-finding id=F1', $comment);

        $this->assertStringContainsString('[//]: # (ai-review-end)', $written);
        $this->assertGreaterThan(strrpos($written, '````'), strpos($written, '[//]: # (ai-review-end)'));
    }

    public function test_a_suggestion_containing_a_block_is_fenced_wide_enough(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding(['suggestion' => '$md = "```php";'])])->assertOk();

        $comment = collect($this->postedComments())->first(fn (string $b): bool => str_contains($b, 'F1 · HIGH'));

        $this->assertStringContainsString('````suggestion', $comment);
        $this->assertStringContainsString('[//]: # (ai-finding id=F1', $comment);
    }

    public function test_a_finding_with_no_prompt_gets_no_prompt_block(): void
    {
        $this->fakeBitbucket();

        // Better no block than a sentence assembled out of the title, which would
        // read like an instruction without being one.
        $this->report([$this->finding(['prompt' => null])])->assertOk();

        foreach ($this->postedComments() as $body) {
            $this->assertStringNotContainsString('**Prompt for a coding agent:**', $body);
        }
    }

    public function test_the_fix_these_block_uses_the_findings_own_prompt(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding()])->assertOk();

        $summary = $this->publishedDescription();

        // The same instruction as the comment on that line, so the two cannot drift.
        $this->assertStringContainsString(
            '1. app/Services/ReviewPublisher.php:42 · finding F1 — In app/Services/ReviewPublisher.php, match findings on their marker id rather than on text, and add a test for a second run.',
            $summary,
        );
    }

    public function test_it_leaves_a_finding_alone_when_nothing_about_it_changed(): void
    {
        $finding = $this->finding(['status' => 'open']);

        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $this->comment(2, "F1 · HIGH\n\n**Publishing is not idempotent**\n\n"
                ."A second run posts the finding again. Match on the marker instead.\n\n"
                ."**Prompt for a coding agent:**\n\n```text\nIn app/Services/ReviewPublisher.php, match findings on their marker id rather than on text, and add a test for a second run.\n```\n\n"
                .'[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/Services/ReviewPublisher.php line=42)'),
        ]);

        $this->report([$finding])
            ->assertOk()
            ->assertJsonPath('publish.comments.created', 0)
            ->assertJsonPath('publish.comments.updated', 0);
    }

    public function test_a_fixed_reply_links_the_commit_and_says_what_it_was(): void
    {
        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $this->comment(2, "**HIGH** — Publishing is not idempotent\n\n[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/Services/ReviewPublisher.php line=42)"),
        ]);

        $this->report([$this->finding(['status' => 'fixed'])])->assertOk();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && ($data['parent']['id'] ?? null) === 2
                // The sha alone tells a reader nothing they can act on: link it, and
                // say what it was. Subject line only - the body stays out.
                && str_contains($data['content']['raw'] ?? '', 'Fixed in [`abc1234d`](https://bitbucket.org/example-co/storefront/commits/abc1234def5678)')
                && str_contains($data['content']['raw'] ?? '', 'Guard the scheduler behind ASANA_SYNC (Alex Doe)')
                && ! str_contains($data['content']['raw'] ?? '', 'Longer body');
        });
    }

    public function test_a_reply_is_marked_as_the_reviews_own(): void
    {
        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $this->comment(2, "**HIGH** — Publishing is not idempotent\n\n[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/Services/ReviewPublisher.php line=42)"),
        ]);

        $this->report([$this->finding(['status' => 'fixed'])])->assertOk();

        // Approving or writing the description starts another build, and that build
        // asks whether anything has happened since the last review. An unmarked
        // reply answered yes — with the review's own words.
        Http::assertSent(function (Request $request): bool {
            $raw = (string) ($request->data()['content']['raw'] ?? '');

            return $request->method() === 'POST'
                && ($request->data()['parent']['id'] ?? null) === 2
                && str_contains($raw, "\n[//]: # (ai-review-reply)")
                && preg_match(ReviewContext::REPLY_MARKER, $raw) === 1;
        });
    }

    public function test_the_commit_is_looked_up_once_however_often_it_is_named(): void
    {
        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $this->comment(2, "**HIGH** — One\n\n[//]: # (ai-finding id=F1 status=open severity=HIGH path=a.php line=1)"),
            $this->comment(3, "**HIGH** — Two\n\n[//]: # (ai-finding id=F2 status=open severity=HIGH path=b.php line=2)"),
        ]);

        // Two replies and the summary all name the same commit.
        $this->report([
            $this->finding(['id' => 'F1', 'status' => 'fixed', 'path' => 'a.php', 'line' => 1]),
            $this->finding(['id' => 'F2', 'status' => 'fixed', 'path' => 'b.php', 'line' => 2]),
        ])->assertOk()->assertJsonPath('publish.comments.replied', 2);

        $lookups = 0;
        Http::recorded(function (Request $request) use (&$lookups): bool {
            if ($request->method() === 'GET' && str_contains($request->url(), '/commit/')) {
                $lookups++;
            }

            return false;
        });

        $this->assertSame(1, $lookups, 'The same commit was fetched more than once.');
    }

    public function test_an_unreadable_commit_still_gets_a_link(): void
    {
        // The commit may be gone - force-pushed away, or simply refused. The link
        // stands on its own; only the decoration is lost.
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/commit/')) {
                return Http::response(['error' => ['message' => 'not found']], 404);
            }

            if ($request->method() === 'GET') {
                // The same answer for the pull request and for its comments.
                return Http::response(['values' => [], 'source' => ['branch' => ['name' => 'tweak-models']]]);
            }

            return Http::response(['id' => 9001], 201);
        });

        $this->report([$this->finding()])->assertOk();

        $summary = $this->publishedDescription();

        $this->assertStringContainsString('up to [`abc1234d`](https://bitbucket.org/example-co/storefront/commits/abc1234def5678)', $summary);
        $line = collect(explode("\n", $summary))->first(fn (string $line): bool => str_contains($line, 'up to [`abc1234d`]'));
        $this->assertStringNotContainsString(' — ', $line);
    }

    public function test_it_updates_the_comment_and_replies_once_when_a_finding_is_fixed(): void
    {
        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $this->comment(2, "**HIGH** — Publishing is not idempotent\n\n[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/Services/ReviewPublisher.php line=42)"),
        ]);

        $this->report([$this->finding(['status' => 'fixed'])])
            ->assertOk()
            ->assertJsonPath('publish.comments.updated', 1)
            ->assertJsonPath('publish.comments.replied', 1);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            // The commit, not just the phrase: reading it off the finding instead of
            // the run made every reply say "Fixed in `unknown`".
            return $request->method() === 'POST'
                && ($data['parent']['id'] ?? null) === 2
                && str_contains($data['content']['raw'] ?? '', 'Fixed in [`abc1234d`](');
        });
    }

    public function test_a_settled_finding_keeps_its_comment_word_for_word(): void
    {
        $original = "**HIGH** — Publishing is not idempotent\n\n"
            ."The wording the review used when it first found this, with a ✅ in it.\n\n"
            .'[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/Services/ReviewPublisher.php line=42)';

        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $this->comment(2, $original),
        ]);

        // The review has since reworded the finding; the comment must not follow.
        $this->report([$this->finding([
            'status' => 'fixed',
            'title' => 'Rephrased after the fact',
            'details' => 'Wording from a later run that must not overwrite the record.',
        ])])->assertOk();

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/comments/2')) {
                return false;
            }

            $body = $request->data()['content']['raw'] ?? '';

            return mb_check_encoding($body, 'UTF-8')
                // Only the marker moved.
                && str_contains($body, '**HIGH** — Publishing is not idempotent')
                && str_contains($body, 'The wording the review used when it first found this, with a ✅ in it.')
                && ! str_contains($body, 'Rephrased after the fact')
                && ! str_contains($body, 'Fixed')
                && str_contains($body, '[//]: # (ai-finding id=F1 status=fixed severity=HIGH');
        });
    }

    public function test_a_settled_finding_is_not_touched_again_on_a_later_run(): void
    {
        $settled = "**HIGH** — Publishing is not idempotent\n\n"
            ."A second run posts the finding again. Match on the marker instead.\n\n"
            .'[//]: # (ai-finding id=F1 status=fixed severity=HIGH path=app/Services/ReviewPublisher.php line=42)';

        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=aaaaaaaa mode=both)"),
            $this->comment(2, $settled, resolved: true),
        ]);

        // A later commit, and nothing about the finding has changed: no edit, no
        // second reply, no second resolve.
        $this->report([$this->finding(['status' => 'fixed'])], commit: 'bbbb2222cccc3333')
            ->assertOk()
            ->assertJsonPath('publish.comments.updated', 0)
            ->assertJsonPath('publish.comments.replied', 0)
            ->assertJsonPath('publish.comments.resolved', 0);
    }

    public function test_it_posts_no_inline_comments_in_report_mode(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding()], extra: ['mode' => 'report'])
            ->assertOk()
            ->assertJsonPath('publish.comments.created', 0);

        foreach ($this->postedComments() as $body) {
            $this->assertStringContainsString('## AI Review', $body, 'A finding was posted as its own comment in report mode.');
        }
    }

    /**
     * Bitbucket refuses to edit a merged pull request's description, and that
     * refusal used to fail the build. The review goes into a comment instead,
     * holds nothing, and still counts as the answer. `no pull request` is what
     * report.sh passes the build on, findings or not.
     */
    public function test_a_review_of_a_merged_pull_request_is_posted_as_a_comment(): void
    {
        $this->state = 'MERGED';
        $this->fakeBitbucket();

        $this->report([$this->finding()])
            ->assertOk()
            ->assertJsonPath('publish.published', false)
            ->assertJsonPath('publish.reason', 'no pull request')
            ->assertJsonPath('publish.summary', 'commented: the pull request is merged');

        $this->assertNull($this->publishedDescription());

        $summary = collect($this->postedComments())->first(fn (string $raw): bool => preg_match(ReviewContext::REVIEW_MARKER, $raw) === 1);
        $this->assertNotNull($summary, 'No summary comment was posted.');
        $this->assertStringStartsWith('_The pull request was already merged when this review finished', $summary);
        $this->assertStringContainsString('Publishing is not idempotent', $summary);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/approve')
            || str_contains($request->url(), '/request-changes')
            || str_contains($request->url(), '/tasks'));

        $this->assertSame(
            ReviewRun::STATUS_FINISHED,
            ReviewRun::where('commit', 'abc1234def5678')->firstOrFail()->status,
        );
    }

    public function test_a_second_late_review_edits_the_summary_comment_rather_than_adding_one(): void
    {
        $this->state = 'MERGED';
        $this->fakeBitbucket([
            $this->comment(77, "## AI Review\n\n[//]: # (ai-review reviewed=0000aaa1111bbb mode=both at=2026-09-20T10:00:00+00:00)"),
        ]);

        $this->report([$this->finding()])
            ->assertOk()
            ->assertJsonPath('publish.summary', 'comment updated: the pull request is merged');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/pullrequests/570/comments/77')
            && str_contains($request->data()['content']['raw'] ?? '', 'ai-review reviewed=abc1234def5678'));
        $this->assertSame([], array_filter($this->postedComments(), fn (string $raw): bool => preg_match(ReviewContext::REVIEW_MARKER, $raw) === 1));
    }

    public function test_it_does_not_overwrite_a_summary_from_a_newer_review(): void
    {
        $newer = new ReviewRun;
        $newer->tenant_id = $this->repository->tenant_id;
        $newer->repository_id = $this->repository->getKey();
        $newer->workspace = 'example-co';
        $newer->slug = 'storefront';
        $newer->branch = 'tweak-models';
        $newer->pull_request_id = 570;
        $newer->commit = 'fff9999aaa1111';
        $newer->started_at = now()->addMinutes(10);
        $newer->save();

        $this->description = "[//]: # (ai-review-start)\n\n## AI Review\n\n"
            ."[//]: # (ai-review reviewed=fff9999aaa1111 mode=both at=2026-09-25T10:00:00+00:00)\n"
            .'[//]: # (ai-review-end)';

        $this->fakeBitbucket();

        $this->report([$this->finding()])
            ->assertOk()
            ->assertJsonPath('publish.published', false)
            ->assertJsonPath('publish.reason', 'a newer review is already published');

        $this->assertNull($this->publishedDescription(), 'The newer review was overwritten.');
    }

    public function test_it_records_the_run_even_when_there_is_no_pull_request_to_publish_to(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding()], extra: ['pull_request_id' => null])
            ->assertOk()
            ->assertJsonPath('publish.published', false)
            ->assertJsonPath('publish.reason', 'no pull request');

        $this->assertSame(
            ReviewRun::STATUS_FINISHED,
            ReviewRun::where('commit', 'abc1234def5678')->firstOrFail()->status,
        );
    }

    public function test_a_retry_after_a_failed_publication_is_not_skipped_as_unchanged(): void
    {
        // A credential the review server can read the pull request with, but writes that fail.
        $this->fakeBitbucket(postsFail: true);

        // The precheck records the input hash, the review runs, publishing fails.
        $first = $this->openReview([
            'pull_request_id' => 570,
            'commit' => 'abc1234def5678',
        ])->assertOk();

        $this->report([$this->finding()])->assertOk()->assertJsonPath('publish.published', false);

        $this->assertSame(
            ReviewRun::STATUS_FAILED,
            ReviewRun::where('commit', 'abc1234def5678')->firstOrFail()->status,
        );

        // Nothing has changed, so without the fix this asks the same question and is
        // told the answer is already on record - leaving the review unpublished for
        // good, because the retry never runs.
        $this->openReview([
            'pull_request_id' => 570,
            'commit' => 'abc1234def5678',
        ])
            ->assertOk()
            ->assertJsonPath('skip', false);
    }

    public function test_it_resolves_the_thread_once_a_finding_is_settled(): void
    {
        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $this->comment(2, "**HIGH** — Publishing is not idempotent\n\n[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/Services/ReviewPublisher.php line=42)"),
        ]);

        $this->report([$this->finding(['status' => 'fixed'])])
            ->assertOk()
            ->assertJsonPath('publish.comments.resolved', 1);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/comments/2/resolve'));
    }

    public function test_it_reopens_the_thread_when_a_fixed_finding_comes_back(): void
    {
        $settled = "**HIGH** — Publishing is not idempotent\n\n"
            ."A second run posts the finding again.\n\n"
            .'[//]: # (ai-finding id=F1 status=fixed severity=HIGH path=app/Services/ReviewPublisher.php line=42)';

        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $this->comment(2, $settled, resolved: true),
        ]);

        // The review says the problem is live again. A collapsed thread is where
        // nobody would see that.
        $this->report([$this->finding(['status' => 'open'])])
            ->assertOk()
            ->assertJsonPath('publish.comments.reopened', 1)
            ->assertJsonPath('publish.comments.resolved', 0);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/comments/2/resolve'));
    }

    public function test_an_acknowledged_finding_stays_resolved(): void
    {
        $settled = "**LOW** — Naming nit\n\n"
            .'[//]: # (ai-finding id=F1 status=open severity=LOW path=app/Services/ReviewPublisher.php line=42)';

        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $this->comment(2, $settled, resolved: true),
        ]);

        // Someone resolved the thread; the review read that as acknowledgement. It
        // must not be dragged back open.
        $this->report([$this->finding(['status' => 'acknowledged', 'severity' => 'LOW'])])
            ->assertOk()
            ->assertJsonPath('publish.comments.reopened', 0)
            ->assertJsonPath('publish.comments.resolved', 0);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/resolve'));
    }

    /**
     * A finding that was wrong is told apart from one left as it is on purpose, so
     * the summary and the thread say which it was.
     */
    public function test_a_false_positive_is_resolved_and_called_what_it_is(): void
    {
        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $this->comment(2, "**HIGH** — Publishing is not idempotent\n\n[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/Services/ReviewPublisher.php line=42)"),
        ]);

        $this->report([$this->finding(['status' => 'false_positive'])])
            ->assertOk()
            ->assertJsonPath('publish.comments.resolved', 1)
            ->assertJsonPath('publish.comments.replied', 1);

        $this->assertContains(
            "Marked as a false positive.\n\n".ReviewPublisher::REPLY_MARKER,
            $this->postedComments(),
        );

        $summary = $this->publishedDescription();
        $this->assertStringContainsString('### Resolved', $summary);
        $this->assertStringContainsString('🚫 False positive', $summary);
        $this->assertStringNotContainsString('### Fix these', $summary);
    }

    /**
     * A finding someone called a false positive in its thread, as the pull request
     * has it after their reply and, optionally, the review's comment on it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function disputedThread(string $markedStatus, bool $reviewAnswered): array
    {
        $comments = [
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $this->comment(2, "**HIGH** — Publishing is not idempotent\n\n[//]: # (ai-finding id=F1 status={$markedStatus} severity=HIGH path=app/Services/ReviewPublisher.php line=42)"),
            ['parent' => ['id' => 2]] + $this->comment(3, 'False positive: the marker check at line 80 skips a second post.'),
        ];

        if ($reviewAnswered) {
            $comments[] = ['parent' => ['id' => 3]] + $this->comment(4, "Line 80 compares titles, not markers.\n\n".ReviewPublisher::REPLY_MARKER);
        }

        return $comments;
    }

    /**
     * Calling a finding a false positive is the person's decision. The review may
     * say it disagrees, once, in place of the plain reply, but the finding is
     * settled and blocks nothing.
     */
    public function test_the_review_may_comment_on_a_false_positive_but_not_override_it(): void
    {
        $this->fakeBitbucket($this->disputedThread('open', reviewAnswered: false));

        $this->report(
            [$this->finding(['status' => 'false_positive', 'reply' => 'Line 80 compares titles, not markers.'])],
            extra: ['result' => 'PASSED'],
        )
            ->assertOk()
            ->assertJsonPath('publish.comments.resolved', 1)
            ->assertJsonPath('publish.comments.replied', 1);

        $this->assertSame(
            ["Line 80 compares titles, not markers.\n\n".ReviewPublisher::REPLY_MARKER],
            $this->postedComments(),
        );
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && ($request->data()['parent']['id'] ?? null) === 2);
    }

    /**
     * The comment is the review's final word: it does not repeat it on every run
     * until somebody writes again.
     */
    public function test_the_review_does_not_comment_on_the_same_dispute_twice(): void
    {
        $this->fakeBitbucket($this->disputedThread('false_positive', reviewAnswered: true));

        $this->report([$this->finding(['status' => 'false_positive', 'reply' => 'Line 80 still compares titles.'])])
            ->assertOk()
            ->assertJsonPath('publish.comments.replied', 0);

        $this->assertSame([], $this->postedComments());
    }

    public function test_it_does_not_resolve_a_thread_that_is_already_resolved(): void
    {
        $settled = "✅ **Fixed** in `abc1234d` — kept below for the record.\n\n"
            ."**HIGH** — Publishing is not idempotent\n\n"
            ."A second run posts the finding again. Match on the marker instead.\n\n"
            .'[//]: # (ai-finding id=F1 status=fixed severity=HIGH path=app/Services/ReviewPublisher.php line=42)';

        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $this->comment(2, $settled, resolved: true),
        ]);

        $this->report([$this->finding(['status' => 'fixed'])])
            ->assertOk()
            ->assertJsonPath('publish.comments.resolved', 0);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/resolve'));
    }

    public function test_it_leaves_an_open_findings_thread_alone(): void
    {
        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $this->comment(2, "**HIGH** — Publishing is not idempotent\n\n[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/Services/ReviewPublisher.php line=42)"),
        ]);

        $this->report([$this->finding(['status' => 'open'])])
            ->assertOk()
            ->assertJsonPath('publish.comments.resolved', 0);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/resolve'));
    }

    public function test_a_low_finding_is_commented_on_but_blocks_nothing(): void
    {
        $this->fakeBitbucket();

        // Worth saying, not worth stopping the merge for. It is still a comment, so
        // it can be read, replied to and resolved like any other.
        $this->report([$this->finding(['severity' => 'LOW'])], extra: ['result' => 'PASSED', 'fail_on' => 'medium'])
            ->assertOk()
            ->assertJsonPath('publish.comments.created', 1)
            ->assertJsonPath('publish.tasks.created', 0)
            ->assertJsonPath('publish.verdict', 'approved');

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '/comments')
                && ($data['inline']['path'] ?? null) === 'app/Services/ReviewPublisher.php'
                && str_starts_with($data['content']['raw'] ?? '', "F1 · LOW\n");
        });
    }

    public function test_a_security_finding_is_labelled_as_one(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding(['category' => 'security'])])->assertOk();

        $comment = collect($this->postedComments())->first(fn (string $b): bool => str_contains($b, 'F1 · HIGH'));
        $summary = $this->publishedDescription();

        $this->assertStringStartsWith("F1 · HIGH · security\n\n**Publishing is not idempotent**", $comment);
        $this->assertStringContainsString(
            "- **F1 · HIGH · security · 🆕 New** Publishing is not idempotent\n    - `app/Services/ReviewPublisher.php:42`",
            $summary,
        );
    }

    public function test_an_unproven_finding_says_so_and_blocks_nothing(): void
    {
        $this->fakeBitbucket();

        // Reported at the severity it would have if confirmed, per the security
        // exception - but a reader must not mistake it for an established finding.
        $this->report([$this->pathless(['category' => 'security', 'confidence' => 6])])
            ->assertOk()
            // A task is a merge check of its own; an unproven finding must not create
            // one, or it blocks through the back door what it cannot block directly.
            ->assertJsonPath('publish.tasks.created', 0);

        $comment = collect($this->postedComments())->first(fn (string $b): bool => str_contains($b, 'F1 · HIGH'));

        $this->assertStringStartsWith("F1 · HIGH · security · unconfirmed\n", $comment);
    }

    public function test_an_established_finding_is_not_labelled_unconfirmed(): void
    {
        $this->fakeBitbucket();

        // 8 is the threshold, and a finding that states no confidence is established.
        $this->report([
            $this->finding(['confidence' => 8]),
            $this->finding(['id' => 'F2', 'title' => 'No confidence stated']),
        ])->assertOk();

        foreach ($this->postedComments() as $body) {
            $this->assertStringNotContainsString('unconfirmed', $body);
        }
    }

    public function test_correctness_is_left_unsaid_because_it_is_the_default(): void
    {
        $this->fakeBitbucket();

        // Printing it on every finding would be noise that hides the ones worth
        // flagging. An unrecognised value is treated the same way.
        $this->report([
            $this->finding(['category' => 'correctness']),
            $this->finding(['id' => 'F2', 'category' => 'nonsense', 'title' => 'Second']),
        ])->assertOk();

        foreach ($this->postedComments() as $body) {
            $this->assertMatchesRegularExpression('/^F[12] · HIGH\n/', $body);
        }
    }

    public function test_a_refused_request_changes_does_not_fail_the_publish(): void
    {
        // Bitbucket may not let an access token act as a participant. Recorded
        // rather than thrown, and the value matters: findings no longer fail the
        // build on their own, so the pipeline reads this to decide whether anything
        // is holding the merge - block.sh fails the build when it is not.
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/request-changes') || str_contains($request->url(), '/approve')) {
                return Http::response(['error' => ['message' => 'nope']], 403);
            }

            if ($request->method() === 'GET') {
                // The same answer for the pull request and for its comments.
                return Http::response(['values' => [], 'source' => ['branch' => ['name' => 'tweak-models']]]);
            }

            return Http::response(['id' => 9001], 201);
        });

        $this->report([$this->finding()], extra: ['result' => 'FAILED'])
            ->assertOk()
            ->assertJsonPath('publish.published', true)
            ->assertJsonPath('publish.verdict', 'refused (changes HTTP 403, approval HTTP 403)');
    }

    public function test_it_creates_a_task_for_a_finding_with_no_file_to_comment_on(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->pathless()])->assertOk()->assertJsonPath('publish.tasks.created', 1);

        $this->assertSame(['F1: No tests cover the scheduler change (unknown)'], $this->createdTasks());
    }

    public function test_a_blocking_finding_on_the_diff_gets_a_task_too(): void
    {
        $this->fakeBitbucket();

        // One task per finding that blocks the merge, whether or not it also has a
        // line to comment on: the rule is the build's, not where the finding sits.
        $this->report([$this->finding()])->assertOk()->assertJsonPath('publish.tasks.created', 1);

        $this->assertSame(['F1: Publishing is not idempotent (app/Services/ReviewPublisher.php:42)'], $this->createdTasks());
    }

    public function test_without_a_threshold_tasks_follow_the_packages_default(): void
    {
        $this->fakeBitbucket();

        // A pipeline that sends no fail_on blocked on blocking.jq's default, where
        // maintainability never blocks and security blocks at any severity. Read as
        // plain `medium`, the review server would task the first and not the second.
        $this->report([
            $this->pathless(['id' => 'F1', 'severity' => 'MEDIUM', 'category' => 'maintainability', 'title' => 'Misleading name']),
            $this->pathless(['id' => 'F2', 'severity' => 'LOW', 'category' => 'security', 'title' => 'Token in the log']),
        ])->assertOk();

        $this->assertSame(['F2: Token in the log (unknown)'], $this->createdTasks());
    }

    public function test_it_creates_no_task_for_a_finding_below_the_threshold(): void
    {
        $this->fakeBitbucket();

        // A pathless LOW must not block a merge the build would have let through.
        $this->report([$this->pathless(['severity' => 'LOW'])], extra: ['fail_on' => 'medium'])
            ->assertOk()
            ->assertJsonPath('publish.tasks.created', 0);

        $this->assertSame([], $this->createdTasks());
    }

    public function test_each_category_can_have_a_threshold_of_its_own(): void
    {
        $this->fakeBitbucket();

        $this->report([
            $this->pathless(['id' => 'F1', 'severity' => 'MEDIUM', 'category' => 'maintainability', 'title' => 'Misleading name']),
            $this->pathless(['id' => 'F2', 'severity' => 'LOW', 'category' => 'security', 'title' => 'Token in the log']),
            $this->pathless(['id' => 'F3', 'severity' => 'MEDIUM', 'title' => 'Wrong total']),
        ], extra: ['fail_on' => 'medium,security:any,maintainability:never'])->assertOk();

        // A LOW security finding reaches `any`; a MEDIUM maintainability one never
        // blocks; a finding without a category uses the first threshold.
        $this->assertSame(['F2: Token in the log (unknown)', 'F3: Wrong total (unknown)'], $this->createdTasks());
    }

    public function test_a_category_the_server_does_not_know_yet_is_accepted(): void
    {
        $this->fakeBitbucket();

        // A category the server has not heard of yet must not lose the whole
        // review.
        $this->report([$this->pathless(['category' => 'accessibility'])], extra: ['fail_on' => 'never,accessibility:any'])
            ->assertOk()
            ->assertJsonPath('publish.tasks.created', 1);
    }

    public function test_a_pre_existing_finding_says_so_and_blocks_nothing(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->pathless(['introduced' => false])])
            ->assertOk()
            ->assertJsonPath('publish.tasks.created', 0);

        $this->assertStringContainsString('- **F1 · HIGH · Pre-existing · 🆕 New** No tests cover the scheduler change', $this->publishedDescription());
        $this->assertStringStartsWith("F1 · HIGH · Pre-existing\n", $this->postedComments()[0]);
    }

    public function test_the_comment_says_why_the_finding_was_flagged(): void
    {
        $this->fakeBitbucket();

        $this->report([
            $this->finding(['evidence' => 'Ran the job twice against one pull request; ReviewPublisher.php:42 posts without checking the marker.']),
            $this->finding(['id' => 'F2', 'title' => 'Second', 'evidence' => '  ']),
        ])->assertOk();

        [$withEvidence, $without] = $this->postedComments();

        // Below the details, set apart from the claim it supports.
        $this->assertStringContainsString(
            "Match on the marker instead.\n\n**Why this was flagged**\n\nRan the job twice against one pull request; ReviewPublisher.php:42 posts without checking the marker.\n\n",
            $withEvidence,
        );
        // Nothing to say, no heading.
        $this->assertStringNotContainsString('Why this was flagged', $without);
    }

    public function test_a_finding_no_longer_reported_keeps_its_thread_open_and_says_why_once(): void
    {
        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $this->comment(2, "**HIGH** — Publishing is not idempotent\n\n[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/Services/ReviewPublisher.php line=42)"),
        ]);

        $this->report([$this->finding(['status' => 'unreported'])], extra: ['result' => 'PASSED'])
            ->assertOk()
            ->assertJsonPath('publish.comments.replied', 1)
            ->assertJsonPath('publish.comments.resolved', 0);

        $this->assertStringContainsString('no longer reports this', $this->postedComments()[0]);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/resolve'));

        // Still listed for a person to settle, but not work the review asks for.
        $summary = $this->publishedDescription();
        $this->assertStringContainsString("### Open findings\n\n- **F1 · HIGH · ❔ Not reported again** Publishing is not idempotent", $summary);
        $this->assertStringContainsString('❔ Not reported again', $summary);
        $this->assertStringNotContainsString('### Fix these', $summary);
    }

    public function test_the_no_longer_reported_reply_is_not_repeated(): void
    {
        $this->fakeBitbucket([
            $this->comment(1, "## AI Review\n\n[//]: # (ai-review reviewed=abc1234def5678 mode=both)"),
            $this->comment(2, "**HIGH** — Publishing is not idempotent\n\n[//]: # (ai-finding id=F1 status=unreported severity=HIGH path=app/Services/ReviewPublisher.php line=42)"),
        ]);

        $this->report([$this->finding(['status' => 'unreported'])], extra: ['result' => 'PASSED'])
            ->assertOk()
            ->assertJsonPath('publish.comments.replied', 0);
    }

    public function test_it_resolves_the_task_of_a_finding_no_longer_reported(): void
    {
        $this->fakeBitbucket(tasks: [
            $this->task(7, 'F1: No tests cover the scheduler change (unknown)'),
        ]);

        // The task is a merge check, and the finding has stopped holding the merge.
        $this->report([$this->pathless(['status' => 'unreported'])])
            ->assertOk()
            ->assertJsonPath('publish.tasks.resolved', 1);
    }

    public function test_it_creates_no_second_task_for_a_finding_that_already_has_one(): void
    {
        $this->fakeBitbucket(tasks: [
            $this->task(1, 'F1: No tests cover the scheduler change (unknown)'),
        ]);

        $this->report([$this->pathless(['status' => 'open'])])
            ->assertOk()
            ->assertJsonPath('publish.tasks.created', 0);

        $this->assertSame([], $this->createdTasks());
    }

    public function test_it_resolves_the_task_when_the_finding_is_fixed(): void
    {
        $this->fakeBitbucket(tasks: [
            $this->task(7, 'F1: No tests cover the scheduler change (unknown)'),
        ]);

        $this->report([$this->pathless(['status' => 'fixed'])])
            ->assertOk()
            ->assertJsonPath('publish.tasks.resolved', 1);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->method() === 'PUT'
                && str_contains($request->url(), '/tasks/7')
                && ($data['state'] ?? null) === 'RESOLVED'
                // The wording is passed back untouched: the finding id lives in it,
                // and it is the only thing tying the task to the finding.
                && ($data['content']['raw'] ?? null) === 'F1: No tests cover the scheduler change (unknown)';
        });
    }

    public function test_it_resolves_a_task_whose_finding_is_no_longer_confident(): void
    {
        // Run one: confirmed, so it earned a task.
        $this->fakeBitbucket();
        $this->report([$this->pathless(['confidence' => 9])], commit: 'aaaa1111bbbb')
            ->assertOk()
            ->assertJsonPath('publish.tasks.created', 1);

        // Run two: the same finding, still open, but the review is no longer sure.
        // The task is a merge check, so leaving it open would block what the finding
        // itself can no longer block. Set on the live fake, not a second fake().
        $this->tasks = [$this->task(7, 'F1: No tests cover the scheduler change (unknown)')];

        $this->report([$this->pathless(['status' => 'open', 'confidence' => 6])], commit: 'bbbb2222cccc')
            ->assertOk()
            ->assertJsonPath('publish.tasks.resolved', 1)
            ->assertJsonPath('publish.tasks.created', 0);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/tasks/7'));
    }

    public function test_it_never_reopens_a_task_someone_resolved(): void
    {
        $this->fakeBitbucket(tasks: [
            $this->task(7, 'F1: No tests cover the scheduler change (unknown)', 'RESOLVED'),
        ]);

        $this->report([$this->finding(['status' => 'open'])])
            ->assertOk()
            ->assertJsonPath('publish.tasks.created', 0)
            ->assertJsonPath('publish.tasks.resolved', 0);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/tasks/7'));
    }

    public function test_it_creates_no_tasks_when_the_run_asks_for_none(): void
    {
        $this->fakeBitbucket();

        $this->report([$this->finding()], extra: ['create_tasks' => false])
            ->assertOk()
            ->assertJsonPath('publish.tasks.skipped', 'this run asked for no tasks');

        $this->assertSame([], $this->createdTasks());
    }
}
