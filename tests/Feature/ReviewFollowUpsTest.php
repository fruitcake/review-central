<?php

use App\Models\Repository;
use App\Models\ReviewRun;
use App\Services\ReviewFiling;
use App\Services\ReviewFollowUps;
use App\Services\ReviewPublisher;
use App\Services\ReviewSettings;
use App\SourceControl\ReadOnlyClient;

beforeEach(function () {
    $this->repository = Repository::factory()->create(['slug' => 'shop']);

    $this->run = fn (array $attributes = []): ReviewRun => ReviewRun::query()->forceCreate($attributes + [
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'pull_request_id' => 7, 'commit' => str_repeat('a', 40), 'status' => ReviewRun::STATUS_FINISHED,
        'started_at' => now()->subHours(10), 'findings' => [],
    ]);

    // Rounds before this one, oldest first, the last with the findings given.
    $this->rounds = function (int $count, array $findings = []): void {
        foreach (range(1, $count) as $n) {
            ($this->run)(['started_at' => now()->subHours(10 - $n), 'findings' => $n === $count ? $findings : []]);
        }
    };

    $this->current = fn (): ReviewRun => ($this->run)(['status' => ReviewRun::STATUS_RUNNING, 'started_at' => now()]);
    $this->medium = ['id' => 'F5', 'status' => 'new', 'severity' => 'MEDIUM', 'title' => 'Busy file', 'path' => 'app/Export.php', 'line' => 12];
    $this->high = ['id' => 'F6', 'status' => 'new', 'severity' => 'HIGH', 'title' => 'Leaks invoices'];
});

it('leaves a first review and a trial as they are', function () {
    $first = ($this->current)();

    expect(app(ReviewFollowUps::class)->apply($first, [$this->medium], 'The review is not converging.')['findings'])->toBe([$this->medium]);

    ($this->rounds)(4);
    $trial = ($this->run)(['custom' => true, 'started_at' => now()]);

    expect(app(ReviewFollowUps::class)->apply($trial, [$this->medium], 'The review is not converging.')['findings'])->toBe([$this->medium]);
});

it('keeps only new HIGHs once the review says it is not converging, and sets the rest under Open questions', function () {
    $open = ['id' => 'F2', 'status' => 'open', 'severity' => 'MEDIUM', 'title' => 'Still open'];
    ($this->rounds)(1, [$open]);

    $applied = app(ReviewFollowUps::class)->apply(($this->current)(), [$this->medium, $this->high, $open], "The review is not converging: look at the approach.\n\n1 high, 2 medium.\n\n### Open questions\n\n- Is the cache shared?");

    expect(array_column($applied['findings'], 'id'))->toBe(['F6', 'F2'])
        ->and($applied['summary'])->toContain("### Open questions\n\n- Busy file (`app/Export.php:12`): medium, set aside because the review is not converging.\n- Is the cache shared?");
});

it('lets a new finding below HIGH hold no merge from the third follow-up on, in the rounds after too', function () {
    ($this->rounds)(3, [['id' => 'F4', 'severity' => 'MEDIUM', 'status' => 'new', 'advisory' => true]]);
    $carried = ['id' => 'F4', 'status' => 'open', 'severity' => 'MEDIUM', 'title' => 'From the round before'];

    $findings = app(ReviewFollowUps::class)->apply(($this->current)(), [$this->medium, $this->high, $carried], '2 medium, 1 high.')['findings'];

    expect(collect($findings)->pluck('advisory', 'id')->all())->toBe(['F5' => true, 'F6' => null, 'F4' => true])
        ->and(ReviewPublisher::blocks($findings[0], ReviewPublisher::DEFAULT_FAIL_ON))->toBeFalse()
        ->and(ReviewPublisher::blocks($findings[1], ReviewPublisher::DEFAULT_FAIL_ON))->toBeTrue();
});

it('records nothing new after the team\'s most follow-up rounds, a HIGH neither', function () {
    ReviewSettings::saveDefaults($this->repository->tenant, ['max_rounds' => 2]);
    $open = ['id' => 'F2', 'status' => 'open', 'severity' => 'HIGH', 'title' => 'Still open'];
    ($this->rounds)(3, [$open]);

    $applied = app(ReviewFollowUps::class)->apply(($this->current)(), [$this->high, $open], '1 high.');

    expect(array_column($applied['findings'], 'id'))->toBe(['F2'])
        ->and($applied['summary'])->toContain('Leaks invoices: high, set aside because this pull request has had its 2 follow-up rounds.');
});

it('passes a late follow-up whose only new finding is below HIGH', function () {
    ($this->rounds)(3);
    $run = ($this->run)(['status' => ReviewRun::STATUS_RUNNING, 'started_at' => now(), 'build_number' => 99]);
    app(ReviewFiling::class)->file($this->repository->refresh(), [
        'branch' => 'feature/export', 'commit' => $run->commit, 'build' => 99, 'pull_request_id' => 7,
        'result' => 'FAILED', 'summary' => '1 medium.', 'findings' => [$this->medium], 'publish' => false,
    ]);

    $filed = $run->refresh();

    expect($filed->result)->toBe('PASSED')
        ->and($filed->findings[0]['advisory'])->toBeTrue();
});

it('leaves a connection that keeps its reviews here alone, where every review reads the change afresh', function () {
    $this->repository->integration->update(['settings' => ['workspace' => 'acme', ReadOnlyClient::SETTING => ReadOnlyClient::READ]]);
    ReviewSettings::saveDefaults($this->repository->tenant, ['max_rounds' => 1]);
    ($this->rounds)(3);

    expect(app(ReviewFollowUps::class)->apply(($this->current)()->refresh(), [$this->high], '1 high.')['findings'])->toBe([$this->high]);
});

it('takes no advisory from the reviewer, and holds the merge on a first review\'s HIGH', function () {
    $run = ($this->run)(['status' => ReviewRun::STATUS_RUNNING, 'started_at' => now(), 'build_number' => 98]);

    app(ReviewFiling::class)->file($this->repository->refresh(), [
        'branch' => 'feature/export', 'commit' => $run->commit, 'build' => 98, 'pull_request_id' => 7,
        'result' => 'PASSED', 'summary' => '1 high.', 'findings' => [$this->high + ['advisory' => true]], 'publish' => false,
    ]);

    expect($run->refresh()->result)->toBe('FAILED')
        ->and($run->findings[0])->not->toHaveKey('advisory');
});

it('counts a finding called open that was never reported as new, keeps advisory across a round it was left out of, and recounts the summary', function () {
    ReviewSettings::saveDefaults($this->repository->tenant, ['max_rounds' => 3]);
    // F4 was advisory two rounds ago and left out of the last one.
    ($this->run)(['started_at' => now()->subHours(9), 'findings' => []]);
    ($this->run)(['started_at' => now()->subHours(8), 'findings' => []]);
    ($this->run)(['started_at' => now()->subHours(7), 'findings' => [['id' => 'F4', 'severity' => 'MEDIUM', 'status' => 'new', 'advisory' => true]]]);
    ($this->run)(['started_at' => now()->subHours(6), 'findings' => []]);
    $back = ['id' => 'F4', 'status' => 'open', 'severity' => 'MEDIUM', 'title' => 'Back again'];
    $claimed = ['id' => 'F9', 'status' => 'open', 'severity' => 'HIGH', 'title' => 'Never reported'];

    $applied = app(ReviewFollowUps::class)->apply(($this->current)(), [$back, $claimed], "1 high, 1 medium.\n\nThe rest.");

    expect(array_column($applied['findings'], 'id'))->toBe(['F4'])
        ->and($applied['findings'][0]['advisory'])->toBeTrue()
        ->and($applied['summary'])->toStartWith("1 medium, nothing blocking.\n\nThe rest.")
        ->and($applied['summary'])->toContain('Never reported: high, set aside because this pull request has had its 3 follow-up rounds.');
});

it('hears the review say it is not converging in Dutch', function () {
    ($this->rounds)(1);

    expect(app(ReviewFollowUps::class)->apply(($this->current)(), [$this->medium], "De review convergeert niet.\n\n1 medium.")['findings'])->toBe([]);
});

it('hears only a not-converging line at the opening, and recounts OpenCodeReview\'s summary by status', function () {
    ReviewSettings::saveDefaults($this->repository->tenant, ['max_rounds' => 1]);
    $open = ['id' => 'F2', 'status' => 'open', 'severity' => 'MEDIUM', 'title' => 'Still open'];
    ($this->rounds)(1, [$open]);

    $quoted = app(ReviewFollowUps::class)->apply(($this->current)(), [$this->medium], "1 medium.\n\nThe agent wrote \"not converging\" in its commit.");

    expect(array_column($quoted['findings'], 'id'))->toBe(['F5']);

    ($this->rounds)(1);
    $ocr = app(ReviewFollowUps::class)->apply(($this->current)(), [$this->high, $open], 'OpenCodeReview (gpt) reviewed 2 file(s) changed since the last review: 1 new, 1 still open, 0 fixed.');

    expect($ocr['summary'])->toStartWith('OpenCodeReview (gpt) reviewed 2 file(s) changed since the last review: 0 new, 1 still open, 0 fixed.');
});

it('does not count runs that published nothing as rounds', function () {
    ReviewSettings::saveDefaults($this->repository->tenant, ['max_rounds' => 1]);
    foreach (range(1, 3) as $n) {
        ($this->run)(['status' => ReviewRun::STATUS_UNPUBLISHED, 'started_at' => now()->subHours($n)]);
    }

    expect(app(ReviewFollowUps::class)->apply(($this->current)(), [$this->high], '1 high.')['findings'])->toBe([$this->high]);
});
