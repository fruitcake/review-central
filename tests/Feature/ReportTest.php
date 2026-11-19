<?php

use App\Models\Repository;
use App\Models\ReviewRun;
use App\Runners\Container\Report;
use App\Runners\Container\RunLog;
use App\Runners\Container\Trial;
use App\Services\ReviewPublisher;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->sha = str_repeat('a', 40);
    $this->repository = Repository::factory()->create(['slug' => 'shop']);
    $this->work = storage_path('framework/testing/report');
    File::deleteDirectory($this->work);
    File::ensureDirectoryExists($this->work);

    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/comments*' => Http::response(['values' => [], 'id' => 900]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/tasks*' => Http::response(['values' => []]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/*' => Http::response([]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570' => Http::response([
            'id' => 570, 'title' => 'Add the export', 'description' => 'Adds the export.', 'state' => 'OPEN', 'draft' => false,
            'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => $this->sha]],
            'destination' => ['branch' => ['name' => 'main']],
        ]),
        'api.bitbucket.org/2.0/repositories/acme/shop/commit/*/reports/ai-review/annotations*' => Http::response(['size' => 1]),
        'api.bitbucket.org/*' => Http::response([]),
    ]);

    $this->finding = ['id' => 'F1', 'severity' => 'MEDIUM', 'status' => 'new', 'confidence' => 9, 'path' => 'app/Export.php', 'line' => 12, 'title' => 'Export shows other companies\' invoices', 'details' => 'Every invoice is exported.'];

    $this->leave = function (array $report, array $context = []): void {
        File::put("{$this->work}/ai-review.json", json_encode($report));
        File::put("{$this->work}/review-context.json", json_encode($context + ['pull_request' => ['id' => 570]]));
        File::put("{$this->work}/ai-usage.json", json_encode(['usage' => ['claude-opus-5-5' => ['input' => 1000, 'output' => 200, 'cache_read' => 5000]], 'cost' => 0.42]));
        touch("{$this->work}/.review-started", time() - 90);
    };

    $this->report = fn (?Trial $trial = null): bool => app(Report::class)
        ->run($this->repository, 'feature/export', $this->sha, 41, 570, $this->work, $trial, app(RunLog::class)->writer($this->repository->id, 41));

    $this->log = fn (): string => File::get(app(RunLog::class)->path($this->repository->id, 41));
});

afterEach(function () {
    File::deleteDirectory($this->work);
    File::delete(storage_path('framework/testing/report-secret.json'));
});

it('files what the reviewer wrote, posts it, and puts it on the commit, without a container', function () {
    ($this->leave)(['result' => 'PASSED', 'summary' => "1 medium.\n\nAdds the export.", 'changes' => ['Adds an export'], 'manual_review' => [], 'findings' => [$this->finding]]);

    expect(($this->report)())->toBeTrue();

    $run = ReviewRun::query()->sole();

    // The result is the threshold's, whatever the report called itself.
    expect($run->only(['status', 'result', 'build_number', 'pull_request_id', 'model']))->toBe([
        'status' => ReviewRun::STATUS_FINISHED, 'result' => 'FAILED', 'build_number' => 41, 'pull_request_id' => 570, 'model' => 'claude-opus-5-5',
    ])
        ->and((float) $run->cost)->toBe(0.42)
        ->and($run->usage['claude-opus-5-5'])->toMatchArray(['input' => 1000, 'output' => 200, 'cache_read' => 5000]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pullrequests/570/comments') && str_contains($request['content']['raw'], 'Export shows other companies'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/pullrequests/570/request-changes'));

    // Code Insights, straight from the worker: the report and the finding in the diff.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), "/commit/{$this->sha}/reports/ai-review")
        && $request['result'] === 'FAILED' && $request['data'][0] === ['title' => 'Blocking findings', 'type' => 'NUMBER', 'value' => 1]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/reports/ai-review/annotations')
        && $request[0]['external_id'] === 'ai-review-F1' && $request[0]['path'] === 'app/Export.php' && $request[0]['line'] === 12 && $request[0]['severity'] === 'MEDIUM');

    expect(($this->log)())->toContain('1 of 1 open finding(s) block the merge.', 'MEDIUM  app/Export.php:12  [blocks]', 'Published to the pull request:', 'Code Insights: the report is on the commit, with 1 annotation(s)');
});

it('publishes nothing when what the reviewer left is no report', function (Closure $leave, string $said) {
    $leave->call($this);

    expect(($this->report)())->toBeFalse()
        ->and(ReviewRun::query()->count())->toBe(0)
        ->and(($this->log)())->toContain($said);

    Http::assertNothingSent();
})->with([
    'nothing' => [fn () => null, 'left no ai-review.json'],
    'half a file' => [fn () => File::put("{$this->work}/ai-review.json", '{"summary": "1 med'), 'left no ai-review.json'],
    'a list' => [fn () => File::put("{$this->work}/ai-review.json", '[]'), 'left no ai-review.json'],
    'a link to a file of the worker\'s' => [function () {
        File::put(storage_path('framework/testing/report-secret.json'), '{"summary": "secret", "findings": []}');
        symlink(storage_path('framework/testing/report-secret.json'), "{$this->work}/ai-review.json");
    }, 'left no ai-review.json'],
    'a review it was told to stop' => [fn () => ($this->leave)(['skipped' => true, 'summary' => 'The context could not be read.', 'findings' => []]), 'The review was skipped: The context could not be read.'],
    'findings keyed by id' => [fn () => ($this->leave)(['summary' => 'x', 'findings' => ['F1' => ['title' => 'x']]]), 'its findings are not a list of findings'],
    'findings that are words' => [fn () => ($this->leave)(['summary' => 'x', 'findings' => ['Something is wrong']]), 'its findings are not a list of findings'],
]);

it('records a trial in full and keeps it off the pull request, numbered after what is there', function () {
    ($this->leave)(['summary' => '1 medium.', 'findings' => [$this->finding]], ['ignored_existing' => ['comments' => 2, 'findings' => 4, 'highest_finding_id' => 4]]);

    expect(($this->report)(new Trial(pullRequestId: 570, destination: 'main')))->toBeTrue();

    $run = ReviewRun::query()->sole();

    expect($run->only(['status', 'custom']))->toBe(['status' => ReviewRun::STATUS_UNPUBLISHED, 'custom' => true])
        ->and($run->findings[0]['id'])->toBe('F5')
        ->and(($this->log)())->toContain('Numbered this run\'s findings after F4', 'Trial: the review is recorded here and kept off the pull request.');

    // No comment, no verdict, no Code Insights report.
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('says so when Bitbucket refuses the Code Insights report, and the review stands', function () {
    // A fresh fake: a stub that was set earlier keeps answering.
    Http::swap(new Factory);
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/commit/*/reports/ai-review' => Http::response(['message' => 'The report could not be stored.'], 400),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/comments*' => Http::response(['values' => [], 'id' => 900]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570/tasks*' => Http::response(['values' => []]),
        'api.bitbucket.org/2.0/repositories/acme/shop/pullrequests/570' => Http::response([
            'id' => 570, 'title' => 'Add the export', 'description' => '', 'state' => 'OPEN', 'draft' => false,
            'source' => ['branch' => ['name' => 'feature/export'], 'commit' => ['hash' => $this->sha]],
            'destination' => ['branch' => ['name' => 'main']],
        ]),
        'api.bitbucket.org/*' => Http::response([]),
    ]);
    ($this->leave)(['summary' => 'No findings.', 'findings' => []]);

    expect(($this->report)())->toBeTrue()
        ->and(ReviewRun::query()->sole()->only(['status', 'result']))->toBe(['status' => ReviewRun::STATUS_FINISHED, 'result' => 'PASSED'])
        ->and(($this->log)())->toContain('Code Insights: the report was not published, or not all of it', 'The review itself is on the pull request.');
});

it('cuts the Code Insights report to the lengths Bitbucket takes', function () {
    $summary = str_repeat('The export shows other companies\' invoices. ', 80);
    ($this->leave)(['summary' => $summary, 'findings' => [['details' => str_repeat('d', 2500), 'title' => str_repeat('t', 600)] + $this->finding]]);

    expect(($this->report)())->toBeTrue();

    // One character too many and Bitbucket refuses the whole report.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/reports/ai-review')
        && mb_strlen($request['details']) === 2000 && str_ends_with($request['details'], '…'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/reports/ai-review/annotations')
        && mb_strlen($request[0]['summary']) === 450 && mb_strlen($request[0]['details']) === 2000);
});

it('puts a blank line before a list in the reviewer\'s text, so the host shows it as one', function () {
    $summary = "1 medium.\n\n**What holds up:**\n- **Trial offer:** Pi is offered to any tenant with a key.\n- **Keys:** each one reaches the container.\n\nNothing else.\n\n1. First\n2. Second\n\n```\ncode\n- not a list\n```";

    expect(ReviewPublisher::spaced($summary))->toBe(
        "1 medium.\n\n**What holds up:**\n\n- **Trial offer:** Pi is offered to any tenant with a key.\n- **Keys:** each one reaches the container.\n\nNothing else.\n\n1. First\n2. Second\n\n```\ncode\n- not a list\n```"
    )
        // What was right is left as it was.
        ->and(ReviewPublisher::spaced("Intro.\n\n- one\n  continued\n- two"))->toBe("Intro.\n\n- one\n  continued\n- two");
});

it('says what a review came to in the words a reader of the pull request uses', function (string $result, array $manual, string $words) {
    $run = new ReviewRun(['result' => $result, 'manual_review' => $manual, 'findings' => []]);

    expect(ReviewPublisher::verdictInWords($run))->toBe($words);
})->with([
    ['FAILED', [], 'Changes requested'],
    ['PASSED', [], 'Approved'],
    ['PASSED', ['Whether the migration ran in production.'], 'Manual review required'],
]);
