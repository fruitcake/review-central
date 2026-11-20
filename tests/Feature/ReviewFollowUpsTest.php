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
    ($this->rounds)(1);
    $open = ['id' => 'F2', 'status' => 'open', 'severity' => 'MEDIUM', 'title' => 'Still open'];

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
    ($this->rounds)(3);
    $open = ['id' => 'F2', 'status' => 'open', 'severity' => 'HIGH', 'title' => 'Still open'];

    $applied = app(ReviewFollowUps::class)->apply(($this->current)(), [$this->high, $open], '1 high.');

    expect(array_column($applied['findings'], 'id'))->toBe(['F2'])
        ->and($applied['summary'])->toContain('Leaks invoices: high, set aside because this pull request has had its 2 follow-up rounds.');
});

it('passes a late follow-up whose only new finding is below HIGH', function () {
    ($this->rounds)(3);
    $run = ($this->run)(['status' => ReviewRun::STATUS_RUNNING, 'started_at' => now(), 'build_number' => 99]);
    $this->repository->integration->update(['settings' => ['workspace' => 'acme', ReadOnlyClient::SETTING => ReadOnlyClient::READ]]);

    app(ReviewFiling::class)->file($this->repository->refresh(), [
        'branch' => 'feature/export', 'commit' => $run->commit, 'build' => 99, 'pull_request_id' => 7,
        'result' => 'FAILED', 'summary' => '1 medium.', 'findings' => [$this->medium], 'publish' => false,
    ]);

    $filed = $run->refresh();

    expect($filed->result)->toBe('PASSED')
        ->and($filed->findings[0]['advisory'])->toBeTrue();
});
