<?php

namespace Tests\Feature;

use App\Models\Repository;
use App\Runners\ContainerRunner;
use App\Services\ReviewLock;
use App\Services\ReviewPublisher;
use App\Tickets\TicketResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ReviewOpeningTest extends TestCase
{
    use RefreshDatabase;

    protected string $key;

    protected Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = $this->reviewing(Repository::factory()->create(['workspace' => 'example-co', 'slug' => 'storefront', 'name' => 'Storefront']));

        $this->repository = $repository;

        // The reviews the markers in these tests speak of: one is only believed
        // when the server has the review itself.
        $this->reviewed($repository, 570, ...array_map(fn (string $letter): string => str_repeat($letter, 40), ['a', 'b', 'c', 'd', 'e', 'f']));

    }

    /**
     * @param  array<int, array<string, mixed>>  $comments
     * @param  array<string, mixed>  $pullRequest
     */
    protected function fakeBitbucket(array $comments = [], array $pullRequest = []): void
    {
        Http::fake([
            '*/pullrequests/570/comments*' => Http::response(['values' => $comments]),
            '*/pullrequests/570' => Http::response(array_merge([
                'id' => 570,
                'title' => 'Add the review step',
                'description' => 'PROJ-1',
                'state' => 'OPEN',
                'draft' => false,
                'source' => ['branch' => ['name' => 'feature/review']],
                'destination' => ['branch' => ['name' => 'master']],
            ], $pullRequest)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function comment(int $id, string $raw, string $createdOn, ?string $author = 'Alex', ?array $inline = null): array
    {
        return [
            'id' => $id,
            'content' => ['raw' => $raw],
            'created_on' => $createdOn,
            'updated_on' => $createdOn,
            'user' => ['display_name' => $author],
            'inline' => $inline,
        ];
    }

    public function test_it_skips_a_draft(): void
    {
        $this->fakeBitbucket([], ['draft' => true]);

        $response = $this->openReview(['pull_request_id' => 570, 'commit' => 'abc']);

        $response->assertOk();
        $response->assertJsonPath('skip', true);
        $response->assertJsonPath('reason', 'draft');
    }

    public function test_it_reviews_a_draft_when_asked(): void
    {
        $this->fakeBitbucket([], ['draft' => true]);

        $this->openReview(['pull_request_id' => 570, 'drafts' => true])
            ->assertJsonPath('skip', false);
    }

    public function test_it_reviews_a_draft_someone_asked_for_with_a_review_comment(): void
    {
        $this->fakeBitbucket([
            $this->comment(1, '/review', '2026-09-21T09:00:00+00:00'),
        ], ['draft' => true]);

        $this->openReview(['pull_request_id' => 570, 'commit' => 'abc'])
            ->assertJsonPath('skip', false);
    }

    public function test_a_draft_whose_review_comment_was_answered_is_skipped_again(): void
    {
        $sha = str_repeat('a', 40);
        $this->fakeBitbucket([
            $this->comment(1, '/review', '2026-09-20T09:00:00+00:00'),
            $this->comment(2, "## Claude Review\n[//]: # (ai-review reviewed={$sha} mode=both)", '2026-09-20T10:00:00+00:00', 'Claude'),
        ], ['draft' => true]);

        $this->openReview(['pull_request_id' => 570, 'commit' => str_repeat('b', 40)])
            ->assertJsonPath('skip', true)
            ->assertJsonPath('reason', 'draft');
    }

    public function test_it_skips_a_commit_it_already_reviewed(): void
    {
        $sha = str_repeat('a', 40);
        $this->fakeBitbucket([
            $this->comment(1, "## Claude Review\n[//]: # (ai-review reviewed={$sha} mode=both)", '2026-09-20T10:00:00+00:00', 'Claude'),
        ]);

        $response = $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}"]);

        $response->assertJsonPath('skip', true);
        $response->assertJsonPath('reason', 'already-reviewed');
        $response->assertJsonPath('last_reviewed_commit', $sha);
    }

    public function test_it_reviews_again_when_someone_commented_after_the_review(): void
    {
        $sha = str_repeat('a', 40);
        $this->fakeBitbucket([
            $this->comment(1, "## Claude Review\n[//]: # (ai-review reviewed={$sha} mode=both)", '2026-09-20T10:00:00+00:00', 'Claude'),
            $this->comment(2, 'This one is intentional, see the ticket.', '2026-09-21T09:00:00+00:00'),
        ]);

        $response = $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'build' => '41']);

        $response->assertJsonPath('skip', false);
        $response->assertJsonPath('new_activity', true);
        $response->assertJsonPath('comments.1.after_last_review', true);
        $response->assertJsonPath('comments.1.is_review', false);
    }

    public function test_a_person_answering_a_finding_is_reviewed_again_on_the_next_trigger(): void
    {
        $sha = str_repeat('a', 40);

        // The shape this has in real life: someone disagrees with a finding in its
        // own thread. No commit follows, so the build that reads this is the one
        // Bitbucket starts for the pull request changing — and it has to review the
        // same commit again, against what they said.
        $reply = $this->comment(3, 'This is deliberate, the ticket asks for it.', '2026-09-25T09:30:00+00:00');
        $reply['parent'] = ['id' => 2];

        $this->fakeBitbucket([
            $this->comment(2, "**HIGH** — Broken\n\n[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/X.php line=8)", '2026-09-25T08:00:00+00:00', 'AI review'),
            $reply,
        ], ['description' => "[//]: # (ai-review-start)\n\n"
            ."[//]: # (ai-review reviewed={$sha} mode=both at=2026-09-25T09:00:00+00:00)\n"
            ."[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/X.php line=8)\n"
            .'[//]: # (ai-review-end)']);

        $this->openReview(['pull_request_id' => 570, 'commit' => $sha, 'build' => '41'])
            ->assertOk()
            ->assertJsonPath('skip', false)
            ->assertJsonPath('new_activity', true)
            // And it is given the finding they answered, so it can settle it rather
            // than raise it again.
            ->assertJsonPath('findings.0.id', 'F1')
            ->assertJsonPath('findings.0.status', 'open');
    }

    public function test_a_commit_nobody_has_said_anything_about_is_not_reviewed_twice(): void
    {
        $sha = str_repeat('a', 40);
        $this->fakeBitbucket([
            $this->comment(1, "## Claude Review\n[//]: # (ai-review reviewed={$sha} mode=both)", '2026-09-20T10:00:00+00:00', 'Claude'),
        ]);

        // Nothing has happened since, so there is nothing to say that was not said.
        // REVIEW_FORCE is how someone asks for the same review twice.
        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'build' => '41'])
            ->assertJsonPath('skip', true)
            ->assertJsonPath('reason', 'already-reviewed');
    }

    public function test_a_question_for_the_reviewer_is_no_reason_to_review_the_commit_again(): void
    {
        $sha = str_repeat('a', 40);
        $this->fakeBitbucket([
            $this->comment(1, "## Claude Review\n[//]: # (ai-review reviewed={$sha} mode=both)", '2026-09-20T10:00:00+00:00', 'Claude'),
            $this->comment(2, '@review did you mean the export in F2?', '2026-09-21T10:00:00+00:00'),
        ]);

        $this->openReview(['pull_request_id' => 570, 'commit' => $sha])
            ->assertOk()
            ->assertJsonPath('skip', true)
            ->assertJsonPath('reason', 'already-reviewed');
    }

    public function test_a_quoted_marker_in_the_reviews_prose_is_not_its_own_state(): void
    {
        $real = str_repeat('a', 40);
        $forged = str_repeat('b', 40);

        // A review of a repository that documents these markers will reproduce one
        // on a line of its own sooner or later - an example in a summary, a finding
        // about the marker format. Inline in backticks is already harmless, because
        // the pattern is line-anchored; at the start of a line it is not. The
        // review's own marker is always appended last, so the last one counts.
        $this->fakeBitbucket([
            $this->comment(
                1,
                "## AI Review\n\nThe skill ends every summary with a line like:\n\n[//]: # (ai-review reviewed={$forged} mode=both)\n\nwhich the next run reads back.\n\n[//]: # (ai-review reviewed={$real} mode=both)",
                '2026-09-20T10:00:00+00:00',
                'AI review',
            ),
        ]);

        $this->openReview(['pull_request_id' => 570, 'commit' => $real])
            ->assertOk()
            ->assertJsonPath('last_reviewed_commit', $real)
            ->assertJsonPath('reason', 'already-reviewed');
    }

    public function test_a_resolved_thread_is_reported_so_the_review_can_let_it_go(): void
    {
        $sha = str_repeat('c', 40);

        $comments = [
            $this->comment(1, "**LOW** — Naming nit\n\n[//]: # (ai-finding id=F1 status=open severity=LOW path=app/X.php line=3)", '2026-09-20T10:00:00+00:00', 'AI review'),
            $this->comment(2, 'Unrelated.', '2026-09-20T11:00:00+00:00'),
        ];
        // Someone read the LOW finding and resolved it rather than replying.
        $comments[0]['resolution'] = ['type' => 'pullrequest_comment_resolution'];

        $this->fakeBitbucket($comments);

        $this->openReview(['pull_request_id' => 570, 'commit' => $sha])
            ->assertOk()
            ->assertJsonPath('findings.0.id', 'F1')
            ->assertJsonPath('findings.0.resolved', true)
            ->assertJsonPath('comments.0.resolved', true)
            ->assertJsonPath('comments.1.resolved', false);
    }

    public function test_the_summary_does_not_claim_a_findings_own_thread(): void
    {
        $sha = str_repeat('d', 40);

        // The order a real run writes them in: finding comments first, then the
        // summary - which repeats a marker for every finding, settled ones included.
        $inline = $this->comment(
            2,
            "**HIGH** — Broken\n\n[//]: # (ai-finding id=F1 status=fixed severity=HIGH path=app/X.php line=8)",
            '2026-09-20T10:00:00+00:00',
            'AI review',
        );
        $inline['resolution'] = ['type' => 'pullrequest_comment_resolution'];

        $summary = $this->comment(
            3,
            "## AI Review\n\n[//]: # (ai-review reviewed={$sha} mode=both)\n[//]: # (ai-finding id=F1 status=fixed severity=HIGH path=app/X.php line=8)",
            '2026-09-20T11:00:00+00:00',
            'AI review',
        );

        $this->fakeBitbucket([$inline, $summary]);

        $this->openReview(['pull_request_id' => 570, 'commit' => $sha])
            ->assertOk()
            // The thread that was resolved is the finding's own, not the summary's.
            ->assertJsonPath('findings.0.resolved', true)
            ->assertJsonPath('findings.0.comment_id', 2)
            ->assertJsonPath('findings.0.status', 'fixed');
    }

    public function test_it_reads_its_own_state_from_the_pull_request_description(): void
    {
        $sha = str_repeat('e', 40);

        // Where the review writes itself now. In report mode this is the only place
        // a finding's state exists - there are no finding comments at all.
        $this->fakeBitbucket([], ['description' => "Adds the flag.\n\n"
            ."[//]: # (ai-review-start)\n\n## AI Review\n\n"
            ."[//]: # (ai-review reviewed={$sha} mode=report at=2026-09-25T09:00:00+00:00)\n"
            ."[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/X.php line=8)\n"
            .'[//]: # (ai-review-end)']);

        $this->openReview(['pull_request_id' => 570, 'commit' => $sha])
            ->assertOk()
            ->assertJsonPath('last_reviewed_commit', $sha)
            ->assertJsonPath('findings.0.id', 'F1')
            ->assertJsonPath('findings.0.status', 'open')
            ->assertJsonPath('reason', 'already-reviewed');
    }

    public function test_the_description_is_handed_over_without_the_reviews_own_section(): void
    {
        // Left in, the review reads the last review's summary as the author's own
        // account of the change. The rule above the section and the placeholder for
        // an empty description are the review server's too; a rule the author wrote is not.
        $this->fakeBitbucket([], ['description' => "Adds the flag.\n\n---\n\nRollout notes.\n\n"
            .ReviewPublisher::DESCRIPTION_PLACEHOLDER."\n\n---\n\n"
            ."[//]: # (ai-review-start)\n\n## AI Review\n\n**Result:** FAILED\n\n"
            ."[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/X.php line=8)\n"
            .'[//]: # (ai-review-end)']);

        $this->openReview(['pull_request_id' => 570, 'commit' => str_repeat('e', 40)])
            ->assertOk()
            ->assertJsonPath('pull_request.description', "Adds the flag.\n\n---\n\nRollout notes.")
            // The section is still read for the review's state.
            ->assertJsonPath('findings.0.id', 'F1');
    }

    public function test_a_comment_after_the_description_was_written_counts_as_new_activity(): void
    {
        $sha = str_repeat('e', 40);

        // The description has no timestamp of its own, so the review writes one into
        // its marker. Without it every comment would read as new.
        $this->fakeBitbucket([
            $this->comment(1, 'Older, before the review ran.', '2026-09-25T08:00:00+00:00'),
            $this->comment(2, 'This one is intentional, see the ticket.', '2026-09-25T10:00:00+00:00'),
        ], ['description' => "[//]: # (ai-review-start)\n\n"
            ."[//]: # (ai-review reviewed={$sha} mode=report at=2026-09-25T09:00:00+00:00)\n"
            .'[//]: # (ai-review-end)']);

        $this->openReview(['pull_request_id' => 570, 'commit' => $sha])
            ->assertOk()
            ->assertJsonPath('comments.0.after_last_review', false)
            ->assertJsonPath('comments.1.after_last_review', true)
            // Which is what makes it activity the last review has not seen. Whether
            // that is worth another review is the caller's decision, not this one's.
            ->assertJsonPath('new_activity', true);
    }

    public function test_the_reviews_own_reply_is_not_somebody_asking_for_another_review(): void
    {
        $sha = str_repeat('e', 40);

        // Bitbucket starts a build when a pull request is approved or its description
        // is edited — both of which the review does itself at the end of a run. The
        // build that follows must find nothing to do. It did: the review's replies
        // in a finding's thread carried no marker, so the run read its own "Fixed
        // in …" as a person talking and reviewed the same commit again.
        $reply = $this->comment(2, "Fixed in [`abc1234d`](https://bitbucket.org/x/y/commits/abc1234d).\n\n[//]: # (ai-review-reply)", '2026-09-25T09:30:00+00:00', 'AI review');
        $reply['parent'] = ['id' => 1];

        $this->fakeBitbucket([
            $this->comment(1, "**HIGH** — Broken\n\n[//]: # (ai-finding id=F1 status=fixed severity=HIGH path=app/X.php line=8)", '2026-09-25T08:00:00+00:00', 'AI review'),
            $reply,
        ], ['description' => "[//]: # (ai-review-start)\n\n"
            ."[//]: # (ai-review reviewed={$sha} mode=both at=2026-09-25T09:00:00+00:00)\n"
            .'[//]: # (ai-review-end)']);

        $this->openReview(['pull_request_id' => 570, 'commit' => $sha])
            ->assertOk()
            // After the review wrote its description, and still its own.
            ->assertJsonPath('comments.1.after_last_review', true)
            ->assertJsonPath('comments.1.is_review', true)
            ->assertJsonPath('skip', true)
            ->assertJsonPath('reason', 'already-reviewed');
    }

    public function test_the_descriptions_status_wins_over_a_stale_summary_comment(): void
    {
        $sha = str_repeat('f', 40);

        // A pull request reviewed before the review moved into the description: the
        // old summary still says the finding is open, and the finding's own thread
        // has been resolved since. The description is the review talking now.
        $inline = $this->comment(
            2,
            "**HIGH** — Broken\n\n[//]: # (ai-finding id=F1 status=fixed severity=HIGH path=app/X.php line=8)",
            '2026-09-25T09:00:00+00:00',
            'AI review',
        );
        $inline['resolution'] = ['type' => 'pullrequest_comment_resolution'];

        $staleSummary = $this->comment(
            3,
            "## AI Review\n\n[//]: # (ai-review reviewed=0000000aaa mode=both)\n"
            .'[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/X.php line=8)',
            '2026-09-25T09:30:00+00:00',
            'AI review',
        );

        $this->fakeBitbucket([$inline, $staleSummary], ['description' => "[//]: # (ai-review-start)\n\n"
            ."[//]: # (ai-review reviewed={$sha} mode=both at=2026-09-25T10:00:00+00:00)\n"
            ."[//]: # (ai-finding id=F1 status=fixed severity=HIGH path=app/X.php line=8)\n"
            .'[//]: # (ai-review-end)']);

        $this->openReview(['pull_request_id' => 570, 'commit' => $sha])
            ->assertOk()
            // Fixed, as the description says - not open, as the stale summary does.
            ->assertJsonPath('findings.0.status', 'fixed')
            // And the thread it belongs to is still its own.
            ->assertJsonPath('findings.0.comment_id', 2)
            ->assertJsonPath('findings.0.resolved', true)
            ->assertJsonPath('last_reviewed_commit', $sha);
    }

    public function test_the_previous_changes_are_handed_back_to_the_review(): void
    {
        $sha = str_repeat('c', 40);

        $this->fakeBitbucket([], ['description' => "Mine.\n\n[//]: # (ai-review-start)\n\n## AI Review\n\n"
            ."### Biggest changes\n- Moves the review into the description\n- Records the verdict\n\n"
            ."### Before release\n- php artisan migrate\n\n"
            ."### For a person to check\n\n- Whether prospects may be deleted.\n\nThese points are for a person to settle.\n\n"
            ."[//]: # (ai-review reviewed={$sha} mode=both at=2026-09-25T10:00:00+00:00)\n"
            .'[//]: # (ai-review-end)']);

        // Without these a follow-up describes only the commits it just read.
        $this->openReview(['pull_request_id' => 570, 'commit' => $sha])
            ->assertOk()
            ->assertJsonPath('changes.0', 'Moves the review into the description')
            ->assertJsonPath('changes.1', 'Records the verdict')
            ->assertJsonPath('before_release.0', 'php artisan migrate')
            ->assertJsonPath('manual_review', ['Whether prospects may be deleted.']);
    }

    public function test_it_reviews_a_new_commit(): void
    {
        $this->fakeBitbucket([
            $this->comment(1, '[//]: # (ai-review reviewed='.str_repeat('a', 40).')', '2026-09-20T10:00:00+00:00', 'Claude'),
        ]);

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => str_repeat('b', 40),
        ])->assertJsonPath('skip', false);
    }

    public function test_it_returns_the_findings_the_review_left_behind(): void
    {
        $this->fakeBitbucket([
            $this->comment(1, '[//]: # (ai-review reviewed='.str_repeat('a', 40).')', '2026-09-20T10:00:00+00:00', 'Claude'),
            $this->comment(2, "**HIGH — Null dereference**\n\n[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/X.php line=88)", '2026-09-20T10:00:01+00:00', 'Claude', ['path' => 'app/X.php', 'to' => 88]),
        ]);

        $response = $this->openReview(['pull_request_id' => 570]);

        $response->assertJsonPath('findings.0.id', 'F1');
        $response->assertJsonPath('findings.0.status', 'open');
        $response->assertJsonPath('findings.0.severity', 'HIGH');
        $response->assertJsonPath('findings.0.path', 'app/X.php');
        $response->assertJsonPath('findings.0.comment_id', 2);
    }

    public function test_it_does_not_fetch_the_ticket_for_a_skipped_review(): void
    {
        $this->fakeBitbucket([], ['title' => 'PROJ-1 Add the review step', 'draft' => true]);

        $this->mock(TicketResolver::class)->shouldNotReceive('forTitle');

        $this->openReview(['pull_request_id' => 570])
            ->assertJsonPath('ticket', null);
    }

    public function test_a_probe_answers_only_the_skip_question(): void
    {
        $this->fakeBitbucket([], ['title' => 'PROJ-1 Add the review step', 'draft' => true]);

        $this->mock(TicketResolver::class)->shouldNotReceive('forTitle');

        $response = $this->openReview(['pull_request_id' => 570, 'probe' => true]);

        $response->assertJson([
            'skip' => true,
            'reason' => 'draft',
            'last_reviewed_commit' => null,
            'locked_by' => null,
        ]);

        // A probe answers the question and nothing else: no comment bodies, no ticket.
        $response->assertJsonMissingPath('comments');
        $response->assertJsonMissingPath('ticket');
    }

    public function test_a_probe_returns_only_the_answer(): void
    {
        $this->fakeBitbucket([], ['title' => 'PROJ-1 Add the review step']);

        $this->fakeTicket('# Ticket');

        $this->openReview(['pull_request_id' => 570, 'probe' => true])
            ->assertJsonPath('skip', false)
            ->assertJsonMissingPath('comments')
            ->assertJsonMissingPath('ticket');
    }

    public function test_it_ignores_a_deleted_review_so_the_next_run_reviews_again(): void
    {
        $sha = str_repeat('a', 40);
        $summary = $this->comment(1, "[//]: # (ai-review reviewed={$sha})", '2026-09-20T10:00:00+00:00', 'Claude');
        $summary['deleted'] = true;

        $finding = $this->comment(2, '[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/X.php line=8)', '2026-09-20T10:00:01+00:00', 'Claude');
        $finding['deleted'] = true;

        $this->fakeBitbucket([$summary, $finding]);

        $response = $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}"]);

        $response->assertJsonPath('skip', false);
        $response->assertJsonPath('last_reviewed_commit', null);
        $response->assertJsonPath('findings', []);
    }

    public function test_a_quoted_marker_is_not_the_reviews_own_state(): void
    {
        $sha = str_repeat('a', 40);
        $this->fakeBitbucket([
            // Someone replies quoting the review, which copies its marker line.
            $this->comment(1, "I disagree:\n\n> [//]: # (ai-review reviewed={$sha})\n> [//]: # (ai-finding id=F1 status=fixed)", '2026-09-20T10:00:00+00:00'),
        ]);

        $response = $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}"]);

        $response->assertJsonPath('skip', false);
        $response->assertJsonPath('last_reviewed_commit', null);
        $response->assertJsonPath('findings', []);
        $response->assertJsonPath('comments.0.is_review', false);
    }

    public function test_it_orders_comments_by_date_whatever_bitbucket_sends(): void
    {
        $older = str_repeat('a', 40);
        $newer = str_repeat('b', 40);

        // Out of order on purpose: the newest review has to win regardless.
        $this->fakeBitbucket([
            $this->comment(2, "[//]: # (ai-review reviewed={$newer})", '2026-09-21T10:00:00+00:00', 'Claude'),
            $this->comment(1, "[//]: # (ai-review reviewed={$older})", '2026-09-20T10:00:00+00:00', 'Claude'),
        ]);

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$newer}"])
            ->assertJsonPath('last_reviewed_commit', $newer)
            ->assertJsonPath('skip', true);
    }

    public function test_a_bitbucket_failure_is_not_an_empty_pull_request(): void
    {
        Http::fake([
            '*/pullrequests/570*' => Http::response(['type' => 'error', 'error' => ['message' => 'Access denied']], 403),
        ]);

        // A 403 used to decode into a pull request with every field null and a
        // review would run against it.
        $this->expectException(RequestException::class);

        $this->openReview(['pull_request_id' => 570]);
    }

    public function test_a_second_run_on_the_same_commit_is_turned_away(): void
    {
        $sha = str_repeat('c', 40);
        $this->fakeBitbucket();
        $this->fakeTicket('# Ticket');

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => 'build-1'])
            ->assertJsonPath('skip', false);

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => 'build-2'])
            ->assertStatus(409)
            ->assertJsonPath('skip', true)
            ->assertJsonPath('reason', 'in-progress')
            ->assertJsonPath('locked_by', 'build-1');
    }

    public function test_a_probe_sees_the_lock_without_taking_it(): void
    {
        $sha = str_repeat('c', 40);
        $this->fakeBitbucket();
        $this->fakeTicket('# Ticket');

        // Nothing holds it yet, so the precheck lets the run through...
        $this->openReview(['pull_request_id' => 570, 'probe' => true, 'commit' => "{$sha}", 'lock' => 'build-1'])
            ->assertOk()
            ->assertJsonPath('skip', false)
            ->assertJsonPath('locked_by', null);

        // ...and probing did not take the lock, so the review step still gets it.
        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => 'build-1'])
            ->assertOk()
            ->assertJsonPath('skip', false);
    }

    public function test_a_probe_stops_a_run_while_another_is_reviewing(): void
    {
        $sha = str_repeat('c', 40);
        $this->fakeBitbucket();
        $this->fakeTicket('# Ticket');

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => 'build-1']);

        // The second run now stops at the precheck, before the review image is pulled.
        $this->openReview(['pull_request_id' => 570, 'probe' => true, 'commit' => "{$sha}", 'lock' => 'build-2'])
            ->assertOk()
            ->assertJsonPath('skip', true)
            ->assertJsonPath('reason', 'in-progress')
            ->assertJsonPath('locked_by', 'build-1');
    }

    public function test_a_probe_does_not_stop_the_run_holding_the_lock(): void
    {
        $sha = str_repeat('c', 40);
        $this->fakeBitbucket();
        $this->fakeTicket('# Ticket');

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => 'build-1']);

        // A retried precheck in the same build is not a second run.
        $this->openReview(['pull_request_id' => 570, 'probe' => true, 'commit' => "{$sha}", 'lock' => 'build-1'])
            ->assertJsonPath('skip', false);
    }

    public function test_a_draft_still_reads_as_a_draft_while_locked(): void
    {
        $sha = str_repeat('c', 40);
        $this->fakeBitbucket([], ['draft' => true]);

        ReviewLock::acquire($this->repository, 'feature/review', 'build-1');

        // The real reason to skip wins over the lock, so the log says why the
        // review is not running rather than blaming a run that is not the cause.
        $this->openReview(['pull_request_id' => 570, 'probe' => true, 'commit' => "{$sha}", 'lock' => 'build-2'])
            ->assertJsonPath('skip', true)
            ->assertJsonPath('reason', 'draft')
            ->assertJsonPath('locked_by', 'build-1');
    }

    public function test_the_run_that_holds_the_lock_can_ask_again(): void
    {
        $sha = str_repeat('c', 40);
        $this->fakeBitbucket();
        $this->fakeTicket('# Ticket');

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => 'build-1']);

        // A retried step is the same run continuing, not a second one.
        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => 'build-1'])
            ->assertJsonPath('skip', false);
    }

    public function test_a_new_commit_waits_for_the_review_in_flight(): void
    {
        $this->fakeBitbucket();
        $this->fakeTicket('# Ticket');

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => str_repeat('c', 40),
            'lock' => 'build-1',
        ])->assertOk();

        // One review per pull request. A second push must not review alongside the
        // first: it would read its context before the first published, miss the
        // findings it is about to post, and comment on the same defects again.
        $this->openReview([
            'pull_request_id' => 570,
            'commit' => str_repeat('d', 40),
            'lock' => 'build-2',
        ])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'in-progress')
            ->assertJsonPath('pending', true);
    }

    public function test_releasing_lets_the_next_run_through(): void
    {
        $sha = str_repeat('c', 40);
        $this->fakeBitbucket();
        $this->fakeTicket('# Ticket');

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => 'build-1']);

        $this->assertSame(
            ['released' => true, 'locked_by' => null, 'restarted' => null],
            $this->endRun($this->repository, 'feature/review', $sha, 'build-1'),
        );

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => 'build-2'])
            ->assertJsonPath('skip', false);
    }

    public function test_a_turned_away_run_cannot_release_someone_elses_lock(): void
    {
        $sha = str_repeat('c', 40);
        $this->fakeBitbucket();
        $this->fakeTicket('# Ticket');

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => 'build-1']);

        $this->assertSame(
            ['released' => false, 'locked_by' => 'build-1', 'restarted' => null],
            $this->endRun($this->repository, 'feature/review', $sha, 'build-2'),
        );
    }

    public function test_a_skipped_review_takes_no_lock(): void
    {
        $sha = str_repeat('c', 40);
        $this->fakeBitbucket([], ['draft' => true]);

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => 'build-1'])
            ->assertJsonPath('reason', 'draft');

        $this->assertSame(
            ['released' => false, 'locked_by' => null, 'restarted' => null],
            $this->endRun($this->repository, 'feature/review', $sha, 'build-1'),
        );
    }

    public function test_it_resolves_the_pull_request_from_the_branch(): void
    {
        // A run is started for a branch, and may not know its pull request.
        Http::fake([
            '*/pullrequests?*' => Http::response(['values' => [[
                'id' => 570,
                'title' => 'PROJ-1 Add the review step',
                'state' => 'OPEN',
                'draft' => false,
                'updated_on' => '2026-09-21T09:00:00+00:00',
                'source' => ['branch' => ['name' => 'feature/review']],
                'destination' => ['branch' => ['name' => 'master']],
            ]]]),
            '*/pullrequests/570/comments*' => Http::response(['values' => []]),
        ]);

        $this->fakeTicket('# Ticket');

        $this->openReview(['branch' => 'feature/review'])
            ->assertOk()
            ->assertJsonPath('skip', false)
            ->assertJsonPath('pull_request.id', 570);
    }

    public function test_a_branch_with_no_open_pull_request_is_nothing_to_review(): void
    {
        Http::fake(['*/pullrequests?*' => Http::response(['values' => []])]);

        $this->openReview(['branch' => 'feature/nothing'])
            ->assertOk()
            ->assertJsonPath('skip', true)
            ->assertJsonPath('reason', 'no-open-pull-request');
    }

    public function test_the_lock_is_the_same_whether_you_ask_by_branch_or_by_id(): void
    {
        $sha = str_repeat('e', 40);
        Http::fake([
            '*/pullrequests?*' => Http::response(['values' => [[
                'id' => 570,
                'title' => 'Add the review step',
                'state' => 'OPEN',
                'draft' => false,
                'source' => ['branch' => ['name' => 'feature/review']],
                'destination' => ['branch' => ['name' => 'master']],
            ]]]),
            '*/pullrequests/570/comments*' => Http::response(['values' => []]),
            // Unmatched URLs are executed for real, so the by-id lookup is stubbed too.
            '*/pullrequests/570' => Http::response([
                'id' => 570,
                'title' => 'Add the review step',
                'state' => 'OPEN',
                'draft' => false,
                'source' => ['branch' => ['name' => 'feature/review']],
                'destination' => ['branch' => ['name' => 'master']],
            ]),
        ]);

        $this->fakeTicket('# Ticket');

        $this->openReview(['branch' => 'feature/review', 'commit' => "{$sha}", 'lock' => 'build-1'])
            ->assertOk();

        // Locked on the pull request's source branch, so the id form sees the same lock.
        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => 'build-2'])
            ->assertStatus(409)
            ->assertJsonPath('locked_by', 'build-1');
    }

    public function test_it_takes_over_the_lock_of_a_stopped_build(): void
    {
        $sha = str_repeat('f', 40);
        $this->fakeBitbucket();
        $this->fakeTicket('# Ticket');
        app(ContainerRunner::class)->markRunning($this->repository, 41);

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => '41'])->assertOk();

        // The job of build 41 was killed, so it never released the lock, and its
        // runner no longer counts it as running.
        app(ContainerRunner::class)->markEnded($this->repository, 41);

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => '42'])
            ->assertOk()
            ->assertJsonPath('skip', false);
    }

    public function test_it_leaves_the_lock_alone_while_the_build_is_still_running(): void
    {
        $sha = str_repeat('f', 40);
        $this->fakeBitbucket();
        $this->fakeTicket('# Ticket');

        app(ContainerRunner::class)->markRunning($this->repository, 41);
        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => '41'])->assertOk();

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => '42'])
            ->assertStatus(409)
            ->assertJsonPath('locked_by', '41');
    }

    public function test_a_runner_that_cannot_say_keeps_the_lock(): void
    {
        $sha = str_repeat('f', 40);
        $this->fakeBitbucket();
        $this->fakeTicket('# Ticket');

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => '41'])->assertOk();

        // "Cannot tell" must never take a lock away: two agents commenting on one
        // pull request is worse than one review that had to wait.
        $this->partialMock(ContainerRunner::class)->shouldReceive('isRunning')->andThrow(new RuntimeException('The cache is down.'));

        $this->openReview(['pull_request_id' => 570, 'commit' => "{$sha}", 'lock' => '42'])
            ->assertStatus(409);
    }

    public function test_a_branch_name_is_not_spliced_into_a_query_string(): void
    {
        // A branch containing & or # would end the query string early, or invent a
        // parameter; a + would arrive as a space and resolve a different branch.
        // In a JSON body it is just a string.
        $branch = 'feature/a&b#c+d';

        Http::fake([
            '*/pullrequests?*' => Http::response(['values' => [[
                'id' => 570,
                'title' => 'Add the review step',
                'state' => 'OPEN',
                'draft' => false,
                'source' => ['branch' => ['name' => $branch]],
                'destination' => ['branch' => ['name' => 'master']],
            ]]]),
            '*/pullrequests/570/comments*' => Http::response(['values' => []]),
        ]);

        $this->fakeTicket('# Ticket');

        $this->openReview(['branch' => $branch])
            ->assertOk()
            ->assertJsonPath('pull_request.id', 570)
            ->assertJsonPath('pull_request.source_branch', $branch);

        Http::assertSent(fn ($request): bool => str_contains(urldecode($request->url()), 'source.branch.name="'.$branch.'"'));
    }

    public function test_it_skips_a_run_whose_commit_is_no_longer_the_head(): void
    {
        $old = str_repeat('a', 40);
        $new = str_repeat('b', 40);

        $this->fakeBitbucket([], ['source' => [
            'branch' => ['name' => 'feature/review'],
            'commit' => ['hash' => $new],
        ]]);

        // This build was still queuing when the next push landed; reviewing the
        // commit it was started for would spend minutes on code nobody is on.
        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $old,
        ])
            ->assertJsonPath('skip', true)
            ->assertJsonPath('reason', 'superseded');
    }

    public function test_a_custom_run_reviews_what_a_merged_pull_request_went_in_as(): void
    {
        $merged = str_repeat('d', 40);

        $this->fakeBitbucket([], ['state' => 'MERGED', 'source' => [
            'branch' => ['name' => 'feature/review'],
            'commit' => ['hash' => str_repeat('b', 12)],
        ]]);

        // After a squash the commit that went in is not the branch's head.
        $this->openReview(['pull_request_id' => 570, 'commit' => $merged])
            ->assertJsonPath('reason', 'superseded');

        $this->openReview(['pull_request_id' => 570, 'commit' => $merged, 'custom' => true, 'force' => true])
            ->assertOk()
            ->assertJsonPath('skip', false)
            ->assertJsonPath('reason', null);
    }

    public function test_the_head_commit_itself_is_not_superseded(): void
    {
        $sha = str_repeat('b', 40);

        $this->fakeBitbucket([], ['source' => [
            'branch' => ['name' => 'feature/review'],
            'commit' => ['hash' => $sha],
        ]]);

        $this->fakeTicket('# Ticket');

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $sha,
        ])->assertJsonPath('skip', false);
    }

    public function test_an_abbreviated_head_still_matches(): void
    {
        $sha = str_repeat('b', 40);

        // Bitbucket abbreviates the hash in some payloads; a 12-character head is
        // the same commit, not a different one.
        $this->fakeBitbucket([], ['source' => [
            'branch' => ['name' => 'feature/review'],
            'commit' => ['hash' => substr($sha, 0, 12)],
        ]]);

        $this->fakeTicket('# Ticket');

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => $sha,
        ])->assertJsonPath('skip', false);
    }

    public function test_an_unknown_head_never_skips(): void
    {
        // Older payloads carry no commit on the source; not knowing the head must
        // never be read as "this one is stale".
        $this->fakeBitbucket();

        $this->fakeTicket('# Ticket');

        $this->openReview([
            'pull_request_id' => 570,
            'commit' => str_repeat('a', 40),
        ])->assertJsonPath('skip', false);
    }
}
