<?php

use App\Enums\TicketStyle;
use App\Exceptions\ActionRefused;
use App\Mcp\Tools\TicketAttachmentTool;
use App\Models\Connection;
use App\Models\Repository;
use App\Models\TicketSource;
use App\Services\TicketLookup;
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

    $this->task = ['gid' => '1220000000000001', 'workspace' => ['gid' => '1200000000000000'], 'name' => '#4512 - Export orders to CSV', 'notes' => 'Add a CSV download to the order overview.', 'permalink_url' => 'https://app.asana.com/0/1/1220000000000001'];

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
        ['gid' => '1220000000000009', 'name' => '#45120 - Something else'],
        ['gid' => '1220000000000008', 'name' => 'Follow-up to #4512'],
        ['gid' => $this->task['gid'], 'name' => $this->task['name']],
    ]);

    $ticket = ($this->ticket)('#4512 - Add an order export');

    expect($ticket)->toMatchArray(['reference' => '4512', 'source' => 'SHOP', 'url' => $this->task['permalink_url'], 'error' => null])
        ->and($ticket['markdown'])->toContain('# #4512 - Export orders to CSV')->toContain('Add a CSV download to the order overview.');

    // Never asked for as a custom ID, which Asana refuses for a bare number.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/custom_id/'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/workspaces/1200000000000000/typeahead')
        && $request['resource_type'] === 'task' && $request['query'] === '4512');
});

it('searches when the quick lookup does not return the task', function () {
    ($this->asana)([['gid' => '1220000000000008', 'name' => 'Follow-up to #4512']], [['gid' => $this->task['gid'], 'name' => $this->task['name']]]);

    expect(($this->ticket)('feat #4512 show notes'))->toMatchArray(['url' => $this->task['permalink_url'], 'error' => null]);
});

it('says no ticket matches when no task is named by the number, also where the search is not in the plan', function (array|int $search) {
    ($this->asana)([['gid' => '1220000000000009', 'name' => '#45120 - Something else']], $search);

    expect(($this->ticket)('#4512 - Add an order export'))->toMatchArray(['markdown' => null, 'error' => 'No ticket in SHOP matches this reference.']);
})->with(['nothing found' => [[]], 'no search in the plan' => [402]]);

it('says the ticket could not be read when the search itself fails', function () {
    ($this->asana)([], 500);

    expect(($this->ticket)('#4512 - Add an order export'))->toMatchArray(['markdown' => null, 'error' => 'The ticket could not be read from SHOP.']);
});

it('reads no task by its id from a workspace other than the connection\'s', function () {
    ($this->asana)();

    expect(($this->ticket)("#{$this->task['gid']} Export orders"))->toMatchArray(['error' => null]);

    $this->task['workspace']['gid'] = '1299999999999999';

    expect(($this->ticket)("#{$this->task['gid']} Export orders"))->toMatchArray(['markdown' => null, 'error' => 'No ticket in SHOP matches this reference.']);

    // Its subtasks were read for the first only.
    expect(Http::recorded(fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/subtasks')))->toHaveCount(1);
});

it('lists a task\'s files and reads one by its number, scaled down when it is a large image', function () {
    $large = imagecreatetruecolor(3000, 1000);
    ob_start();
    imagepng($large);
    $png = (string) ob_get_clean();

    Http::fake(function (Request $request) use ($png) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $request->url() === 'https://asana-files.example/screenshot.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            $request->url() === 'https://asana-files.example/export.log' => Http::response("ERROR export failed\n", 200, ['Content-Type' => 'text/plain']),
            str_ends_with($path, '/attachments') => Http::response(['data' => [
                ['gid' => '1', 'name' => 'screenshot.png', 'host' => 'asana', 'size' => 2048, 'download_url' => 'https://asana-files.example/screenshot.png'],
                ['gid' => '2', 'name' => 'export.log', 'host' => 'asana', 'size' => 20, 'download_url' => 'https://asana-files.example/export.log'],
                ['gid' => '3', 'name' => 'Design', 'host' => 'gdrive', 'view_url' => 'https://drive.google.com/file/d/abc'],
            ]]),
            str_ends_with($path, '/subtasks') => Http::response(['data' => [['gid' => '1220000000000002', 'name' => 'Add the button', 'completed' => false]]]),
            str_ends_with($path, '/stories') => Http::response(['data' => []]),
            str_ends_with($path, "/tasks/{$this->task['gid']}") => Http::response(['data' => $this->task + ['parent' => ['gid' => '1220000000000000', 'name' => 'Orders']]]),
            default => Http::response([], 404),
        };
    });

    $ticket = app(TicketResolver::class)->forReference($this->repository, $this->task['gid']);

    expect($ticket['markdown'])
        ->toContain("## Attachments\n\n1. screenshot.png (2 KB)\n2. export.log (20 bytes)\n3. Design (not readable here: https://drive.google.com/file/d/abc)")
        ->toContain('- [ ] Add the button (1220000000000002)')
        ->toContain('- **Parent:** Orders (1220000000000000)')
        ->and(array_column($ticket['attachments'], 'readable'))->toBe([true, true, false]);

    $image = app(TicketLookup::class)->attachment($this->repository, $this->task['gid'], 1);
    $scaled = $image->fitWithin(TicketAttachmentTool::MAX_IMAGE_SIDE);

    expect($image->isImage())->toBeTrue()
        ->and(getimagesizefromstring($scaled->contents)[0])->toBe(TicketAttachmentTool::MAX_IMAGE_SIDE)
        ->and(app(TicketLookup::class)->attachment($this->repository, $this->task['gid'], 2)->contents)->toBe("ERROR export failed\n");

    expect(fn () => app(TicketLookup::class)->attachment($this->repository, $this->task['gid'], 3))->toThrow(ActionRefused::class, 'Design is not kept in the tracker itself')
        ->and(fn () => app(TicketLookup::class)->attachment($this->repository, $this->task['gid'], 4))->toThrow(ActionRefused::class, 'has no attachment 4: it has 3.');

    // The file addresses are signed already: no token goes to them.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'asana-files.example') && $request->hasHeader('Authorization'));
});
