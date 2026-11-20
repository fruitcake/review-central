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
    // Called wrong on an earlier review, which the last one carried on.
    $earlier = ($this->run)([['id' => 'F3', 'status' => 'new', 'title' => 'Called no problem']], ['started_at' => now()->subDay()]);
    FindingVerdict::query()->create(['review_run_id' => $earlier->id, 'user_id' => $this->user->id, 'finding_id' => 'F3', 'verdict' => FindingVerdictKind::Wrong]);
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
        'api.bitbucket.org/2.0/repositories/acme/shop/src/main/app/Secret.php' => Http::response('', 403),
        'api.bitbucket.org/2.0/repositories/acme/shop/src/main/app/Broken.php' => Http::response('', 500),
    ]);
    $leak = ($this->finding)('F1', ['line' => 2]);
    // The same id in the same file, from another pull request.
    $other = ($this->finding)('F1', ['pull_request_id' => 571, 'line' => 1]);
    $gone = ($this->finding)('F3', ['path' => 'app/Old.php']);
    $refused = ($this->finding)('F5', ['path' => 'app/Secret.php']);
    $broken = ($this->finding)('F6', ['path' => 'app/Broken.php']);
    $nowhere = ($this->finding)('F4', ['path' => null]);

    FindingChecker::fake([['findings' => [
        ['id' => (string) $leak->id, 'verdict' => 'resolved', 'note' => 'It now scopes to the company.'],
        ['id' => (string) $other->id, 'verdict' => 'present', 'note' => 'Still no limit.'],
        ['id' => '999999', 'verdict' => 'resolved', 'note' => 'Not asked about.'],
    ]]]);

    // A file that fails to read leaves the others to be checked, in whatever order they come.
    expect(app(OpenFindings::class)->check($this->repository, OpenFinding::query()->orderByDesc('id')->get()))->toBe(3);

    expect($leak->refresh()->only(['status', 'note', 'closed_by']))->toBe(['status' => 'resolved', 'note' => 'It now scopes to the company.', 'closed_by' => 'check'])
        ->and($other->refresh()->status)->toBe('present')
        ->and($gone->refresh()->only(['status', 'note']))->toBe(['status' => 'resolved', 'note' => 'The file is no longer on the main branch.'])
        // Refused or failing is not gone.
        ->and($refused->refresh()->status)->toBe('open')
        ->and($broken->refresh()->status)->toBe('open')
        ->and($nowhere->refresh()->status)->toBe('open')
        ->and($nowhere->checked_at)->toBeNull();

    FindingChecker::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, '2: return Invoice::forCompany')
        && str_contains($prompt->prompt, "## {$leak->id} · MEDIUM · Finding F1") && str_contains($prompt->prompt, "## {$other->id} · MEDIUM"));
});

it('leaves a finding someone closed while it was being checked as they closed it', function () {
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop' => Http::response(['mainbranch' => ['name' => 'main']]),
        'api.bitbucket.org/2.0/repositories/acme/shop/src/main/app/Export.php' => Http::response("<?php\n"),
    ]);
    $finding = ($this->finding)('F1', ['updated_at' => now()->subMinute()]);
    $loaded = OpenFinding::query()->get();
    $finding->update(['status' => OpenFinding::DISMISSED, 'note' => 'Accepted']);
    FindingChecker::fake([['findings' => [['id' => (string) $finding->id, 'verdict' => 'present', 'note' => 'Still there.']]]]);

    expect(app(OpenFindings::class)->check($this->repository, $loaded))->toBe(0)
        ->and($finding->refresh()->only(['status', 'note']))->toBe(['status' => 'dismissed', 'note' => 'Accepted']);
});

it('reads a long file around the findings\' lines, and resolves nothing it cannot point at', function () {
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    $file = implode("\n", array_map(fn (int $line): string => $line === 2000 ? 'return Invoice::all(); // the leak' : str_repeat('x', 40), range(1, 3000)));
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop' => Http::response(['mainbranch' => ['name' => 'main']]),
        'api.bitbucket.org/2.0/repositories/acme/shop/src/main/app/Export.php' => Http::response($file),
    ]);
    $lined = ($this->finding)('F1', ['line' => 2000]);
    $unlined = ($this->finding)('F2', ['line' => null]);
    FindingChecker::fake([['findings' => [
        ['id' => (string) $lined->id, 'verdict' => 'present', 'note' => 'Line 2000 still has it.'],
        ['id' => (string) $unlined->id, 'verdict' => 'resolved', 'note' => 'Not in what was shown.'],
    ]]]);

    app(OpenFindings::class)->check($this->repository, OpenFinding::query()->get());

    expect($lined->refresh()->status)->toBe('present')
        ->and($unlined->refresh()->status)->toBe('unclear');

    FindingChecker::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, '2000: return Invoice::all(); // the leak')
        && str_contains($prompt->prompt, 'code not shown is not gone')
        && ! str_contains($prompt->prompt, "\n1: xxx"));
});

it('checks the backlog as often as the team says, and not at all when it says nothing', function () {
    Queue::fake();
    $fresh = ($this->finding)('F1', ['checked_at' => now()->subDays(2), 'status' => 'present']);
    $stale = ($this->finding)('F2', ['checked_at' => now()->subDays(8), 'status' => 'present']);
    ($this->finding)('F3', ['status' => 'resolved', 'checked_at' => null]);
    // Nothing to check it against.
    ($this->finding)('F4', ['path' => null]);

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

it('resolves nothing from excerpts of a long file, since the code may have moved out of them', function () {
    Connection::factory()->anthropic()->create(['tenant_id' => $this->repository->tenant_id]);
    // Short enough as bytes, too long once every line is numbered. The leak moved
    // from line 100 to line 900, out of the excerpt around its old line.
    $file = implode("\n", array_map(fn (int $line): string => $line === 900 ? 'return Invoice::all();' : 'x', range(1, 30000)));
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop' => Http::response(['mainbranch' => ['name' => 'main']]),
        'api.bitbucket.org/2.0/repositories/acme/shop/src/main/app/Export.php' => Http::response($file),
    ]);
    $moved = ($this->finding)('F1', ['line' => 100]);
    // Close by: one excerpt for both.
    $near = ($this->finding)('F3', ['line' => 150]);
    $pastEnd = ($this->finding)('F2', ['line' => 40000]);
    FindingChecker::fake([['findings' => [
        ['id' => (string) $moved->id, 'verdict' => 'resolved', 'note' => 'Not around line 100 any more.'],
        ['id' => (string) $near->id, 'verdict' => 'present', 'note' => 'Still there.'],
        ['id' => (string) $pastEnd->id, 'verdict' => 'resolved', 'note' => 'Not there.'],
    ]]]);

    app(OpenFindings::class)->check($this->repository, OpenFinding::query()->get());

    expect($moved->refresh()->status)->toBe('unclear')
        ->and($near->refresh()->status)->toBe('present')
        ->and($pastEnd->refresh()->status)->toBe('unclear');

    FindingChecker::assertPrompted(fn ($prompt): bool => substr_count($prompt->prompt, "\n…\n") === 0
        && str_contains($prompt->prompt, "\n20: x") && str_contains($prompt->prompt, "\n230: x") && ! str_contains($prompt->prompt, "\n231: x")
        && str_contains($prompt->prompt, 'code not shown is not gone'));
});
