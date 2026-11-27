<?php

use App\Enums\ReviewRuleKind;
use App\Models\ReviewRule;
use App\Services\ReviewRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/team-rules');
    File::deleteDirectory($this->dir);
    File::ensureDirectoryExists($this->dir);

    $this->git = fn (string ...$args) => Process::path($this->dir)->run(['git', '-c', 'user.name=t', '-c', 'user.email=t@example.com', ...$args])->throw();
    $this->put = function (string $path, string $text): void {
        File::ensureDirectoryExists(dirname("{$this->dir}/{$path}"));
        File::put("{$this->dir}/{$path}", $text);
    };
    $this->filter = fn (string $base = 'main') => Process::path($this->dir)
        ->run(['php', realpath(__DIR__.'/../../resources/review/skill/team-rules.php'), '.review-instructions', $base, 'HEAD']);

    ($this->git)('init', '-q', '-b', 'main');
    ($this->put)('app/Payments/Pay.php', '<?php');
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Base');
    ($this->git)('checkout', '-qb', 'feature');
    ($this->put)('app/Payments/Pay.php', '<?php // changed');
    ($this->git)('commit', '-qam', 'Pay');

    // The team's rules as the review server writes them.
    $rule = fn (ReviewRuleKind $kind, string $title, ?array $paths): ReviewRule => new ReviewRule(['kind' => $kind, 'title' => $title, 'paths' => $paths, 'body' => 'Why.']);
    ($this->put)('.review-instructions/review-rules.md', ReviewRules::markdown(new Collection([
        $rule(ReviewRuleKind::Check, 'Payments check who may book', ['app/Payments/**']),
        $rule(ReviewRuleKind::Check, 'Exports stay per company', ['app/Exports/**', 'resources/views/exports']),
        $rule(ReviewRuleKind::Check, 'Money is integer cents', null),
        $rule(ReviewRuleKind::Convention, 'Datatables may run raw SQL', ['app/DataTables/**']),
    ])));
});

afterEach(fn () => File::deleteDirectory(storage_path('framework/testing/team-rules')));

it('leaves out the team\'s rules limited to paths no changed file matches, and keeps every label as it was', function () {
    $run = ($this->filter)();
    $rules = File::get("{$this->dir}/.review-instructions/review-rules.md");

    expect($run->successful())->toBeTrue()
        ->and($rules)->toContain('### R1. Payments check who may book')
        ->and($rules)->toContain('### R3. Money is integer cents')
        ->and($rules)->not->toContain('Exports stay per company')
        // A section with none of its rules left goes, heading and all.
        ->and($rules)->not->toContain('## Not a defect here')
        ->and($rules)->toContain('left out: 2 of 4')
        ->and($run->errorOutput())->toContain('Team rules: 2 of 4 left out');
});

it('keeps a rule whole when its own text has headings, and leaves out another', function () {
    $rule = fn (ReviewRuleKind $kind, string $title, ?array $paths, string $body): ReviewRule => new ReviewRule(['kind' => $kind, 'title' => $title, 'paths' => $paths, 'body' => $body]);
    ($this->put)('.review-instructions/review-rules.md', ReviewRules::markdown(new Collection([
        $rule(ReviewRuleKind::Check, 'Payments check who may book', ['app/Payments/**'], "Every way into a payment checks the booking.\n\n## Exceptions\n\nThe kiosk books for a member who stands in front of it."),
        $rule(ReviewRuleKind::Convention, 'Datatables may run raw SQL', ['app/DataTables/**'], 'They are read-only.'),
    ])));

    ($this->filter)();
    $rules = File::get("{$this->dir}/.review-instructions/review-rules.md");

    expect($rules)->toContain("## Exceptions\n\nThe kiosk books for a member who stands in front of it.")
        ->and($rules)->not->toContain('Datatables may run raw SQL')
        ->and($rules)->toContain('left out: 1 of 2');
});

it('keeps every rule when the change\'s files cannot be told', function () {
    $before = File::get("{$this->dir}/.review-instructions/review-rules.md");

    expect(($this->filter)('no-such-branch')->successful())->toBeTrue()
        ->and(File::get("{$this->dir}/.review-instructions/review-rules.md"))->toBe($before);
});
