<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ReviewContext;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReviewContextInputHashTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            'pull_request' => [
                'title' => 'PROJ-1 Add the review step',
                'description' => 'Adds it.',
                'source_branch' => 'feature/review',
                'destination_branch' => 'master',
            ],
            'comments' => [
                ['id' => 1, 'text' => 'Looks off to me.', 'is_review' => false, 'created_on' => '2026-09-20T10:00:00+00:00'],
                ['id' => 2, 'text' => '[//]: # (ai-review reviewed=abc)', 'is_review' => true, 'created_on' => '2026-09-20T11:00:00+00:00'],
            ],
            'settings' => ['commit' => 'abc', 'mode' => 'both', 'fail_on' => 'medium', 'create_tasks' => true, 'model' => 'claude-sonnet-5'],
            'findings' => [['id' => 'F1', 'status' => 'open']],
            'last_reviewed_commit' => 'abc',
        ];
    }

    public function test_it_does_not_change_because_time_passed(): void
    {
        Carbon::setTestNow('2026-09-20 12:00:00');
        $before = ReviewContext::inputHash($this->context(), '# Ticket', 'r1');

        // Two days later the same pull request is looked up again. Nothing a reader
        // would call a change has happened, so the hash must not move.
        Carbon::setTestNow('2026-09-22 12:00:00');
        $after = ReviewContext::inputHash($this->context(), '# Ticket', 'r1');

        Carbon::setTestNow();

        $this->assertSame($before, $after);
    }

    public function test_it_ignores_what_the_review_itself_produced(): void
    {
        $before = ReviewContext::inputHash($this->context(), '# Ticket', 'r1');

        $after = $this->context();
        $after['findings'] = [['id' => 'F1', 'status' => 'fixed'], ['id' => 'F2', 'status' => 'new']];
        $after['last_reviewed_commit'] = 'def';
        $after['comments'][1]['text'] = '[//]: # (ai-review reviewed=def)';

        // Otherwise every hash is unique the moment a review runs, and the check
        // can never match anything.
        $this->assertSame($before, ReviewContext::inputHash($after, '# Ticket', 'r1'));
    }

    public function test_the_reviews_own_section_of_the_description_is_not_a_change(): void
    {
        $before = ReviewContext::inputHash($this->context(), '# Ticket', 'r1');

        // The review writes its summary into the description now, and Bitbucket
        // starts a build when a description changes. Hashing the description whole
        // meant every run handed the build it caused a hash nothing had answered,
        // so the run reviewed the same commit again — and wrote the description
        // again.
        $after = $this->context();
        $after['pull_request']['description'] = "Adds it.\n\n"
            ."[//]: # (ai-review-start)\n\n## AI Review\n\nIt adds it.\n\n"
            ."[//]: # (ai-review reviewed=abc mode=both at=2026-09-25T09:00:00+00:00)\n"
            ."[//]: # (ai-finding id=F1 status=open severity=HIGH path=app/X.php line=8)\n"
            .'[//]: # (ai-review-end)';

        $this->assertSame($before, ReviewContext::inputHash($after, '# Ticket', 'r1'));
    }

    public function test_comment_order_does_not_matter(): void
    {
        $reversed = $this->context();
        $reversed['comments'] = array_reverse($reversed['comments']);

        $this->assertSame(
            ReviewContext::inputHash($this->context(), '# Ticket', 'r1'),
            ReviewContext::inputHash($reversed, '# Ticket', 'r1'),
        );
    }

    public function test_a_pipeline_without_instructions_hashes_as_before(): void
    {
        // The hash this context had before the review server served instructions. Finished
        // runs are matched on it, so a pipeline that reads the skill's own copy
        // must keep it, or every open pull request is reviewed and posted again.
        $this->assertSame(
            'ef54fe5551bd5d3bb34c7c614a14344a88df7897097e9901b6194aa63a9e697c',
            ReviewContext::inputHash($this->context(), '# Ticket', 'r1'),
        );
    }

    public function test_it_changes_with_the_instructions(): void
    {
        $this->assertNotSame(
            ReviewContext::inputHash($this->context(), '# Ticket', 'r1', '3f9c2a1b7d4e6f80'),
            ReviewContext::inputHash($this->context(), '# Ticket', 'r1', '0a1b2c3d4e5f6071'),
        );
    }

    /**
     * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string, 2: ?string}>
     */
    public static function changes(): array
    {
        return [
            'a new commit' => [fn (array $c): array => tap($c, function (&$c) {
                $c['settings']['commit'] = 'def';
            }), '# Ticket', 'r1'],
            'a person commenting' => [fn (array $c): array => tap($c, function (&$c) {
                $c['comments'][] = ['id' => 3, 'text' => 'One more thing', 'is_review' => false];
            }), '# Ticket', 'r1'],
            'an edited comment' => [fn (array $c): array => tap($c, function (&$c) {
                $c['comments'][0]['text'] = 'Actually, never mind.';
            }), '# Ticket', 'r1'],
            'a retitled pull request' => [fn (array $c): array => tap($c, function (&$c) {
                $c['pull_request']['title'] = 'PROJ-2 Something else';
            }), '# Ticket', 'r1'],
            // Outside the review's own markers, so this is the author saying
            // something new about the change.
            'a rewritten description' => [fn (array $c): array => tap($c, function (&$c) {
                $c['pull_request']['description'] = 'Adds it, behind a flag.';
            }), '# Ticket', 'r1'],
            'a different publishing mode' => [fn (array $c): array => tap($c, function (&$c) {
                $c['settings']['mode'] = 'report';
            }), '# Ticket', 'r1'],
            'a different threshold' => [fn (array $c): array => tap($c, function (&$c) {
                $c['settings']['fail_on'] = 'high';
            }), '# Ticket', 'r1'],
            'a changed ticket' => [fn (array $c): array => $c, '# Ticket, with a new criterion', 'r1'],
            'a changed review (prompt, tools)' => [fn (array $c): array => $c, '# Ticket', 'r2'],
            // The model comes from a variable, so the recipe hash does not move
            // when it changes — this is what makes switching model re-review.
            'a different model' => [fn (array $c): array => tap($c, function (&$c) {
                $c['settings']['model'] = 'claude-opus-5';
            }), '# Ticket', 'r1'],
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    #[DataProvider('changes')]
    public function test_it_changes_for(callable $change, string $ticket, ?string $recipe): void
    {
        $this->assertNotSame(
            ReviewContext::inputHash($this->context(), '# Ticket', 'r1'),
            ReviewContext::inputHash($change($this->context()), $ticket, $recipe),
        );
    }
}
