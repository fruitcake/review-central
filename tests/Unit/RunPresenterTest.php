<?php

use App\Filament\RunPresenter;
use App\Models\ReviewRun;
use Tests\TestCase;

// The labels go through the translator.
uses(TestCase::class);

it('says how a run ended', function (string $status, ?string $result, array $expected) {
    expect(RunPresenter::outcome(new ReviewRun(['status' => $status, 'result' => $result])))->toBe($expected);
})->with([
    'approved' => ['finished', 'PASSED', ['label' => 'Approved', 'tone' => 'approved']],
    'changes requested' => ['finished', 'FAILED', ['label' => 'Changes requested', 'tone' => 'changes']],
    'still running' => ['running', null, ['label' => 'Reviewing…', 'tone' => 'running']],
    'never reported' => ['timeout', null, ['label' => 'Timed out', 'tone' => 'failed']],
    'stopped' => ['failed', null, ['label' => 'Did not finish', 'tone' => 'failed']],
    'turned away' => ['pending', null, ['label' => 'Waiting its turn', 'tone' => 'running']],
    'started, not begun' => ['queued', null, ['label' => 'Queued', 'tone' => 'running']],
    'kept off the pull request' => ['unpublished', 'PASSED', ['label' => 'Trial, not posted', 'tone' => 'neutral']],
]);

it('counts the findings that are still open by severity, worst first', function () {
    $run = new ReviewRun(['findings' => [
        ['id' => 'F1', 'severity' => 'LOW', 'status' => 'new'],
        ['id' => 'F2', 'severity' => 'HIGH', 'status' => 'open'],
        ['id' => 'F3', 'severity' => 'HIGH', 'status' => 'fixed'],
        ['id' => 'F4', 'severity' => 'LOW', 'status' => 'open'],
    ]]);

    expect(RunPresenter::openBySeverity($run))->toBe(['high' => 1, 'low' => 2]);
});

it('writes a duration without wrapping at the hour', function (?int $seconds, ?string $expected) {
    expect(RunPresenter::duration($seconds))->toBe($expected);
})->with([[null, null], [0, '00:00'], [101, '01:41'], [3599, '59:59'], [3725, '1:02:05']]);
