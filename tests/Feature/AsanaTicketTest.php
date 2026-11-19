<?php

use App\Enums\TicketStyle;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\TicketSource;
use App\Tickets\TicketResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->repository = Repository::factory()->create();
    $asana = Connection::factory()->asana()->create(['tenant_id' => $this->repository->tenant_id]);

    TicketSource::query()->create([
        'tenant_id' => $this->repository->tenant_id, 'connection_id' => $asana->id, 'name' => 'SHOP', 'style' => TicketStyle::Number, 'position' => 1,
    ]);

    $this->task = ['gid' => '1220000000000001', 'name' => '#30929 - Opvolging beeindigen tijdsregistraties', 'notes' => 'Stop de registratie bij parkeren.', 'permalink_url' => 'https://app.asana.com/0/1/1220000000000001'];

    // Asana as it answers a number: nothing has it as its custom ID, and the search
    // by name also returns a longer number and a task that only mentions it.
    $this->asana = function (array $typeahead = [], array|int $search = []): void {
        Http::fake(function (Request $request) use ($typeahead, $search) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_contains($path, '/tasks/custom_id/') => Http::response(['errors' => [['message' => 'custom_id: Not a recognized ID']]], 400),
                str_ends_with($path, '/typeahead') => Http::response(['data' => $typeahead]),
                str_ends_with($path, '/tasks/search') => is_int($search) ? Http::response([], $search) : Http::response(['data' => $search]),
                str_ends_with($path, '/subtasks'), str_ends_with($path, '/stories') => Http::response(['data' => []]),
                str_ends_with($path, "/tasks/{$this->task['gid']}") => Http::response(['data' => $this->task]),
                default => Http::response([], 404),
            };
        });
    };

    $this->ticket = fn (string $title): ?array => app(TicketResolver::class)->forTitle($this->repository, $title);
});

it('finds the task whose name starts with the number the title names', function () {
    ($this->asana)([
        ['gid' => '1220000000000009', 'name' => '#309290 - Iets anders'],
        ['gid' => '1220000000000008', 'name' => 'Vervolg op #30929'],
        ['gid' => $this->task['gid'], 'name' => $this->task['name']],
    ]);

    $ticket = ($this->ticket)('#30929 - End time registrations on park, exception and stop');

    expect($ticket)->toMatchArray(['reference' => '30929', 'source' => 'SHOP', 'url' => $this->task['permalink_url'], 'error' => null])
        ->and($ticket['markdown'])->toContain('# #30929 - Opvolging beeindigen tijdsregistraties')->toContain('Stop de registratie bij parkeren.');

    // Never asked for as a custom ID, which Asana refuses for a bare number.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/custom_id/'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/workspaces/942482817767910/typeahead')
        && $request['resource_type'] === 'task' && $request['query'] === '30929');
});

it('searches when the quick lookup does not return the task', function () {
    ($this->asana)([['gid' => '1220000000000008', 'name' => 'Vervolg op #30929']], [['gid' => $this->task['gid'], 'name' => $this->task['name']]]);

    expect(($this->ticket)('feat #30929 show notes'))->toMatchArray(['url' => $this->task['permalink_url'], 'error' => null]);
});

it('says no ticket matches when no task is named by the number, also where the search is not in the plan', function (array|int $search) {
    ($this->asana)([['gid' => '1220000000000009', 'name' => '#309290 - Iets anders']], $search);

    expect(($this->ticket)('#30929 - End time registrations'))->toMatchArray(['markdown' => null, 'error' => 'No ticket in SHOP matches this reference.']);
})->with(['nothing found' => [[]], 'no search in the plan' => [402]]);

it('says the ticket could not be read when the search itself fails', function () {
    ($this->asana)([], 500);

    expect(($this->ticket)('#30929 - End time registrations'))->toMatchArray(['markdown' => null, 'error' => 'The ticket could not be read from SHOP.']);
});
