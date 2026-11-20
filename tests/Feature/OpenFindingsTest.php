<?php

use App\Enums\FindingVerdictKind;
use App\Filament\App\Pages\ReviewDefaults;
use App\Filament\App\Resources\OpenFindings\Pages\ListOpenFindings;
use App\Jobs\CheckOpenFindings;
use App\Llm\Agents\FindingChecker;
use App\Models\Connection;
use App\Models\FindingVerdict;
use App\Models\OpenFinding;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\User;
use App\Services\OpenFindings;
use App\Services\ReviewRequest;
use App\Services\ReviewSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->repository = Repository::factory()->create(['slug' => 'shop']);
    $this->user = User::factory()->create(['name' => 'Sam']);
    $this->user->tenants()->attach($this->repository->tenant, ['role' => 'admin']);
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->repository->tenant);
    Filament::bootCurrentPanel();

    $this->run = fn (array $findings, array $attributes = []): ReviewRun => ReviewRun::query()->create($attributes + [
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'commit' => str_repeat('a', 40), 'pull_request_id' => 570, 'status' => ReviewRun::STATUS_FINISHED,
        'started_at' => now(), 'findings' => $findings,
    ]);

    $this->finding = fn (string $id, array $attributes = []): OpenFinding => OpenFinding::query()->create($attributes + [
        'tenant_id' => $this->repository->tenant_id, 'repository_id' => $this->repository->id, 'pull_request_id' => 570,
        'finding_id' => $id, 'severity' => 'MEDIUM', 'title' => "Finding {$id}", 'path' => 'app/Export.php', 'line' => 12, 'details' => 'Every invoice is exported.',
    ]);
});

it('keeps what the last review still had open when the pull request is merged', function () {
    $run = ($this->run)([
        ['id' => 'F1', 'status' => 'new', 'severity' => 'medium', 'title' => 'Export leaks invoices', 'path' => 'app/Export.php', 'line' => 12, 'details' => 'Every invoice.'],
        ['id' => 'F2', 'status' => 'fixed', 'title' => 'Fixed already'],
        ['id' => 'F3', 'status' => 'open', 'title' => 'Called no problem'],
        ['id' => 'F4', 'status' => 'open', 'title' => 'No rate limit'],
    ], ['started_at' => now()->subHour()]);
    FindingVerdict::query()->create(['review_run_id' => $run->id, 'user_id' => $this->user->id, 'finding_id' => 'F3', 'verdict' => FindingVerdictKind::NotAProblem]);
    // A trial is no review of the pull request.
    ($this->run)([['id' => 'F9', 'status' => 'new', 'title' => 'From a trial']], ['custom' => true]);

    app(ReviewRequest::class)->sync($this->repository, 570, ['id' => 570, 'state' => 'MERGED', 'merge_commit' => ['hash' => str_repeat('m', 40)]]);
    // A second delivery adds nothing.
    app(ReviewRequest::class)->sync($this->repository, 570, ['id' => 570, 'state' => 'MERGED', 'merge_commit' => ['hash' => str_repeat('m', 40)]]);

    expect(OpenFinding::query()->orderBy('finding_id')->pluck('finding_id')->all())->toBe(['F1', 'F4'])
        ->and(OpenFinding::query()->where('finding_id', 'F1')->sole()->only(['severity', 'path', 'line', 'status', 'merge_commit', 'review_run_id']))
        ->toBe(['severity' => 'MEDIUM', 'path' => 'app/Export.php', 'line' => 12, 'status' => 'open', 'merge_commit' => str_repeat('m', 40), 'review_run_id' => $run->id]);
});

it('checks each file once against the main branch, and resolves what is in a file that is gone', function () {
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop' => Http::response(['mainbranch' => ['name' => 'main']]),
        'api.bitbucket.org/2.0/repositories/acme/shop/src/main/app/Export.php' => Http::response("<?php\nreturn Invoice::forCompany(\$company)->get();\n"),
        'api.bitbucket.org/2.0/repositories/acme/shop/src/main/app/Old.php' => Http::response('', 404),
    ]);
    $leak = ($this->finding)('F1');
    $limit = ($this->finding)('F2', ['line' => 30]);
    $gone = ($this->finding)('F3', ['path' => 'app/Old.php']);
    $nowhere = ($this->finding)('F4', ['path' => null]);

    FindingChecker::fake([['findings' => [
        ['id' => 'F1', 'verdict' => 'resolved', 'note' => 'It now scopes to the company.'],
        ['id' => 'F2', 'verdict' => 'present', 'note' => 'Still no limit.'],
        ['id' => 'F7', 'verdict' => 'resolved', 'note' => 'Not asked about.'],
    ]]]);

    expect(app(OpenFindings::class)->check($this->repository, OpenFinding::query()->get()))->toBe(3);

    expect($leak->refresh()->only(['status', 'note', 'closed_by']))->toBe(['status' => 'resolved', 'note' => 'It now scopes to the company.', 'closed_by' => 'check'])
        ->and($limit->refresh()->status)->toBe('present')
        ->and($gone->refresh()->only(['status', 'note']))->toBe(['status' => 'resolved', 'note' => 'The file is no longer on the main branch.'])
        ->and($nowhere->refresh()->status)->toBe('open')
        ->and($nowhere->checked_at)->toBeNull();

    FindingChecker::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Invoice::forCompany')
        && str_contains($prompt->prompt, '## F1 · MEDIUM · Finding F1') && str_contains($prompt->prompt, '## F2'));
});

it('checks the backlog as often as the team says, and not at all when it says nothing', function () {
    Queue::fake();
    $fresh = ($this->finding)('F1', ['checked_at' => now()->subDays(2), 'status' => 'present']);
    $stale = ($this->finding)('F2', ['checked_at' => now()->subDays(8), 'status' => 'present']);
    ($this->finding)('F3', ['status' => 'resolved', 'checked_at' => null]);

    expect(OpenFindings::due($this->repository))->toHaveCount(0);

    ReviewSettings::saveDefaults($this->repository->tenant, ['findings_check' => 'weekly']);

    expect(OpenFindings::due($this->repository->refresh())->modelKeys())->toBe([$stale->id]);

    $this->artisan('review:check-open-findings')->assertSuccessful();
    Queue::assertPushed(CheckOpenFindings::class, fn (CheckOpenFindings $job): bool => $job->repositoryId === $this->repository->id && $job->ids === null);
});

it('lists the backlog, and lets an admin check, close and reopen findings', function () {
    Queue::fake();
    $finding = ($this->finding)('F1');
    $resolved = ($this->finding)('F2', ['status' => 'resolved', 'title' => 'Was fixed']);

    livewire(ListOpenFindings::class)
        ->assertCanSeeTableRecords([$finding])
        ->assertCanNotSeeTableRecords([$resolved])
        ->callAction(TestAction::make('check')->table($finding))
        ->callAction(TestAction::make('close')->table($finding), ['reason' => 'Accepted for now'])
        ->assertHasNoActionErrors();

    Queue::assertPushed(CheckOpenFindings::class, fn (CheckOpenFindings $job): bool => $job->ids === [$finding->id]);
    expect($finding->refresh()->only(['status', 'note', 'closed_by']))->toBe(['status' => 'dismissed', 'note' => 'Accepted for now', 'closed_by' => 'Sam']);

    livewire(ListOpenFindings::class)
        ->filterTable('status', 'dismissed')
        ->callAction(TestAction::make('reopen')->table($finding));

    expect($finding->refresh()->status)->toBe('open');
});

it('shows a member the backlog without letting them change it', function () {
    $member = User::factory()->create();
    $member->tenants()->attach($this->repository->tenant, ['role' => 'member']);
    $this->actingAs($member);
    $finding = ($this->finding)('F1');

    livewire(ListOpenFindings::class)
        ->assertCanSeeTableRecords([$finding])
        ->assertActionHidden(TestAction::make('close')->table($finding))
        ->assertActionHidden(TestAction::make('checkAll')->table());
});

it('sets how often they are checked under Review defaults', function () {
    livewire(ReviewDefaults::class)
        ->fillForm(['findings_check' => 'monthly'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(ReviewSettings::defaults($this->repository->tenant)->findings_check)->toBe('monthly');
});
