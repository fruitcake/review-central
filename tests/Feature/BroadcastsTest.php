<?php

use App\Events\ReviewLogGrew;
use App\Events\ReviewRunChanged;
use App\Filament\App\Resources\ReviewRuns\Pages\ListReviewRuns;
use App\Filament\App\Resources\ReviewRuns\Pages\ViewReviewRun;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\Tenant;
use App\Models\User;
use App\Runners\Container\RunLog;
use App\Services\Broadcasts;
use Filament\Facades\Filament;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Support\Facades\Event;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $connection = Connection::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->repository = Repository::factory()->create(['tenant_id' => $this->tenant->id, 'connection_id' => $connection->id, 'slug' => 'shop', 'review_runner' => 'docker']);

    $this->user = User::factory()->create();
    $this->user->tenants()->attach($this->tenant);
    $this->actingAs($this->user);

    $this->run = fn (array $attributes = []): ReviewRun => ReviewRun::query()->create($attributes + [
        'tenant_id' => $this->tenant->id, 'repository_id' => $this->repository->id, 'workspace' => 'acme', 'slug' => 'shop',
        'branch' => 'feature/export', 'pull_request_id' => 7, 'commit' => str_repeat('a', 40), 'build_number' => 41,
        'runner' => 'docker', 'status' => ReviewRun::STATUS_RUNNING, 'started_at' => now(),
    ]);

    $this->pusher = fn () => config([
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher.key' => 'app-key',
        'broadcasting.connections.pusher.secret' => 'app-secret',
        'broadcasting.connections.pusher.app_id' => '1',
        'broadcasting.connections.pusher.options.cluster' => 'eu',
    ]);

    $this->mercure = fn () => config([
        'broadcasting.default' => 'mercure',
        'broadcasting.connections.mercure.url' => 'http://hub.internal/.well-known/mercure',
        'broadcasting.connections.mercure.public_url' => 'https://review.example.com/.well-known/mercure',
        'broadcasting.connections.mercure.secret' => str_repeat('s', 32),
    ]);
});

it('leaves the pages to ask by themselves without a broadcaster', function (string $connection) {
    config(['broadcasting.default' => $connection]);
    Event::fake([ReviewRunChanged::class, ReviewLogGrew::class]);

    $run = ($this->run)();
    app(RunLog::class)->writer($this->repository->id, 41)->note('Reading the diff');

    expect(Broadcasts::enabled())->toBeFalse()->and(Broadcasts::echoOptions())->toBeNull();
    Event::assertNotDispatched(ReviewRunChanged::class);
    Event::assertNotDispatched(ReviewLogGrew::class);

    $this->get("/app/acme/review-runs/{$run->uuid}")->assertOk()
        ->assertDontSee('laravel-echo.js')
        ->assertSee('wire:poll.3s', false);

    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->tenant);

    livewire(ViewReviewRun::class, ['record' => $run->uuid])->assertDontSee('echo-private');
})->with(['null', 'log']);

it('makes Echo in the browser for the broadcaster the environment names', function (Closure $configure, array $options) {
    $configure->call($this);
    Event::fake([ReviewRunChanged::class]);

    expect(Broadcasts::echoOptions())->toBe($options);

    $this->get('/app/acme')->assertOk()
        ->assertSee('js/laravel-echo.js', false)
        ->assertSee('new window.Echo.default(', false)
        ->assertSee($options['broadcaster']);

    // Nothing to listen to before signing in.
    auth()->logout();
    $this->get('/app/login')->assertOk()->assertDontSee('laravel-echo.js');
})->with([
    'pusher' => [fn () => ($this->pusher)(), ['broadcaster' => 'pusher', 'key' => 'app-key', 'cluster' => 'eu', 'forceTLS' => true, 'enabledTransports' => ['ws', 'wss']]],
    'mercure' => [fn () => ($this->mercure)(), ['broadcaster' => 'mercure', 'host' => 'https://review.example.com/.well-known/mercure']],
]);

it('says that a review started or ended, and not what it cost', function () {
    ($this->pusher)();
    Event::fake([ReviewRunChanged::class]);

    $run = ($this->run)();

    Event::assertDispatchedTimes(ReviewRunChanged::class, 1);

    $run->update(['cost' => 1.5]);

    Event::assertDispatchedTimes(ReviewRunChanged::class, 1);

    $run->update(['status' => ReviewRun::STATUS_FINISHED, 'result' => 'PASSED']);

    Event::assertDispatchedTimes(ReviewRunChanged::class, 2);
    Event::assertDispatched(fn (ReviewRunChanged $event): bool => $event->tenantId === $this->tenant->id
        && $event->broadcastOn()->name === "private-tenants.{$this->tenant->id}"
        && $event->broadcastAs() === 'review.changed'
        // Only which review, and whether one still runs for the logo: the page reads the rest from this server.
        && $event->broadcastWith() === ['run' => $run->uuid, 'running' => false]);
});

it('says that a log grew, at most once a second and without what was printed', function () {
    ($this->pusher)();
    Event::fake([ReviewLogGrew::class]);

    $writer = app(RunLog::class)->writer($this->repository->id, 41);
    $writer->write('out', "one\n");
    $writer->write('out', "two\nthree\n");

    Event::assertDispatchedTimes(ReviewLogGrew::class, 1);
    Event::assertDispatched(fn (ReviewLogGrew $event): bool => $event->broadcastOn()->name === "private-review-logs.{$this->repository->id}.41"
        && $event->broadcastWith() === []);

    // What came within that second is told of when the step ends, not dropped.
    $writer->flush();

    Event::assertDispatchedTimes(ReviewLogGrew::class, 2);

    // And nothing more when nothing was written since.
    $writer->flush();

    Event::assertDispatchedTimes(ReviewLogGrew::class, 2);

    usleep(1_100_000);
    $writer->write('out', "four\n");

    Event::assertDispatchedTimes(ReviewLogGrew::class, 3);
});

it('has the review pages listen for their tenant, and a review for its own log', function () {
    ($this->pusher)();
    Event::fake([ReviewRunChanged::class]);
    $run = ($this->run)();

    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->tenant);
    Filament::bootCurrentPanel();

    $tenant = "echo-private:tenants.{$this->tenant->id},.review.changed";
    $log = "echo-private:review-logs.{$this->repository->id}.41,.log.grew";

    expect(livewire(ListReviewRuns::class)->effects['listeners'] ?? [])->toContain($tenant);
    expect(livewire(ViewReviewRun::class, ['record' => $run->uuid])->effects['listeners'] ?? [])->toContain($tenant)->toContain($log);

    app(RunLog::class)->writer($this->repository->id, 41)->note('Reading the diff');

    // Told when to look, and still asking now and then in case one was missed.
    $this->get("/app/acme/review-runs/{$run->uuid}")->assertOk()->assertSee('wire:poll.10s', false);
});

it('lets only those who may open the tenant listen', function () {
    ($this->pusher)();

    // The channels are given to the broadcaster the application starts with, which
    // in a test is none; this one was chosen after.
    require base_path('routes/channels.php');

    $listen = fn (string $channel) => $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel]);

    $listen("private-tenants.{$this->tenant->id}")->assertOk()->assertJsonStructure(['auth']);
    $listen("private-review-logs.{$this->repository->id}.41")->assertOk();

    $other = Tenant::factory()->create();
    $theirs = Repository::factory()->create(['tenant_id' => $other->id]);

    $listen("private-tenants.{$other->id}")->assertForbidden();
    $listen("private-review-logs.{$theirs->id}.41")->assertForbidden();
    $listen('private-review-logs.999999.41')->assertForbidden();

    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $listen("private-tenants.{$other->id}")->assertOk();
});

it('lets a page listen through a Mercure hub too, with a token for the channels it may hear', function () {
    ($this->mercure)();
    // The hub's cookie only reaches a hub on the site's own domain, which in a test is localhost.
    config(['broadcasting.connections.mercure.public_url' => 'https://localhost/.well-known/mercure']);
    require base_path('routes/channels.php');

    $other = Tenant::factory()->create();

    $response = $this->postJson('/broadcasting/auth', ['channel_names' => ["private-tenants.{$this->tenant->id}", "private-tenants.{$other->id}"]])->assertOk();

    $channels = collect($response->json('channel_names'))->keyBy('name');

    expect($channels["private-tenants.{$this->tenant->id}"])->not->toHaveKey('denied')
        ->and($channels["private-tenants.{$other->id}"]['denied'])->toBeTrue();

    // The hub reads who may subscribe from a cookie this answer sets.
    expect($response->headers->getCookies())->not->toBeEmpty();
});

it('sends a broadcast as it happens, so it does not wait behind the review on the queue', function () {
    expect(new ReviewRunChanged(1, 'uuid'))->toBeInstanceOf(ShouldBroadcastNow::class)->toBeInstanceOf(ShouldRescue::class)
        ->and(new ReviewLogGrew(1, 41))->toBeInstanceOf(ShouldBroadcastNow::class)->toBeInstanceOf(ShouldRescue::class);
});
