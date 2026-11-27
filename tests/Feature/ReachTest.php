<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/reach');
    File::deleteDirectory($this->dir);
    File::ensureDirectoryExists($this->dir);

    $this->git = fn (string ...$args) => Process::path($this->dir)->run(['git', '-c', 'user.name=t', '-c', 'user.email=t@example.com', ...$args])->throw();
    $this->put = function (string $path, string $text): void {
        File::ensureDirectoryExists(dirname("{$this->dir}/{$path}"));
        File::put("{$this->dir}/{$path}", $text);
    };
    $this->reach = fn (): string => Process::path($this->dir)
        ->run(['php', realpath(__DIR__.'/../../resources/review/skill/reach.php'), 'main', 'HEAD'])->throw()->output();

    ($this->git)('init', '-q', '-b', 'main');

    // A lesson that checks who may book, and records a participant; one way in books, the other pays.
    ($this->put)('app/Models/Lesson.php', <<<'PHP'
        <?php

        class Lesson
        {
            public function hasRegisterError($member)
            {
                return $this->moduleAccessError($member);
            }

            public function moduleAccessError($member)
            {
                return null;
            }

            public function createParticipant($member)
            {
                $this->participants()->create(['member_id' => $member->id]);
            }
        }
        PHP);
    ($this->put)('app/Http/Controllers/BookingController.php', <<<'PHP'
        <?php

        class BookingController
        {
            public function store(Lesson $lesson)
            {
                if ($lesson->hasRegisterError(auth()->user())) {
                    abort(403);
                }

                $lesson->createParticipant(auth()->user());
            }
        }
        PHP);
    ($this->put)('app/Services/PaymentService.php', <<<'PHP'
        <?php

        class PaymentService
        {
            public function pay(Lesson $lesson, $member)
            {
                $lesson->createParticipant($member);
            }
        }
        PHP);
    ($this->put)('app/Http/Controllers/PaymentController.php', <<<'PHP'
        <?php

        class PaymentController
        {
            public function pay(Lesson $lesson, PaymentService $payments)
            {
                $payments->pay($lesson, auth()->user());
            }
        }
        PHP);
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Lessons');
    ($this->git)('checkout', '-qb', 'feature');
});

afterEach(fn () => File::deleteDirectory(storage_path('framework/testing/reach')));

it('names the entry points that reach a check the change makes, and those that reach the same class\'s writes without it', function () {
    // The change makes the check refuse members who are not invited.
    $model = File::get("{$this->dir}/app/Models/Lesson.php");
    ($this->put)('app/Models/Lesson.php', str_replace('return null;', "return \$this->invited(\$member) ? null : 'Not invited';", $model));
    ($this->git)('commit', '-qam', 'Only invited members');

    $reach = ($this->reach)();

    expect($reach)
        ->toContain('REACH:')
        ->toContain('`app/Models/Lesson.php::moduleAccessError` is reached from 1 entry point(s), such as `app/Http/Controllers/BookingController.php::store`')
        // Paying records a participant without the check, two calls away.
        ->toContain('- `app/Http/Controllers/PaymentController.php::pay`, through `createParticipant`')
        ->not->toContain('`app/Http/Controllers/BookingController.php::store`, through');
});

it('says of an entry point its route, and whether any of its middleware signs anyone in', function () {
    ($this->put)('routes/web.php', "<?php\n\nRoute::middleware('auth')->group(function () {\n    Route::post('book/{lesson}', [BookingController::class, 'store']);\n});\n\nRoute::post('pay/{lesson}', [PaymentController::class, 'pay'])->middleware('throttle:10');\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Routes');
    ($this->put)('app/Models/Lesson.php', str_replace('return null;', "return \$this->invited(\$member) ? null : 'Not invited';", File::get("{$this->dir}/app/Models/Lesson.php")));
    ($this->git)('commit', '-qam', 'Only invited members');

    expect(($this->reach)())
        ->toContain('- `app/Http/Controllers/PaymentController.php::pay` (POST /pay/{lesson}; no middleware that signs anyone in: web, throttle:10), through `createParticipant`');
});

it('puts a route no one signs in for before an authenticated one, and calls a stack it cannot read unknown', function (string $routes, string $said) {
    ($this->put)('routes/web.php', "<?php\n\n{$routes}\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Routes');
    ($this->put)('app/Models/Lesson.php', str_replace('return null;', "return \$this->invited(\$member) ? null : 'Not invited';", File::get("{$this->dir}/app/Models/Lesson.php")));
    ($this->git)('commit', '-qam', 'Only invited members');

    expect(($this->reach)())->toContain("- `app/Http/Controllers/PaymentController.php::pay` ({$said}), through `createParticipant`");
})->with([
    'public after authenticated' => ["Route::post('a/pay', [PaymentController::class, 'pay'])->middleware('auth');\nRoute::post('pay', [PaymentController::class, 'pay']);", 'POST /pay, and 1 more route(s); no middleware that signs anyone in: web'],
    'session check only' => ["Route::post('pay', [PaymentController::class, 'pay'])->middleware('auth.session');", 'POST /pay; no middleware that signs anyone in: web, auth.session'],
    'read from configuration' => ["Route::post('pay', [PaymentController::class, 'pay'])->middleware(config('pay.middleware'));", 'POST /pay; middleware the route files do not let be read, besides web'],
]);

it('says of a Livewire action the page it runs on, and that its own request runs less', function () {
    ($this->put)('app/Livewire/Booking.php', "<?php\n\nclass Booking\n{\n    public function pay(Lesson \$lesson)\n    {\n        \$lesson->createParticipant(auth()->user());\n    }\n}\n");
    ($this->put)('routes/web.php', "<?php\n\nRoute::get('booking', Booking::class)->middleware('auth');\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Booking');
    ($this->put)('app/Models/Lesson.php', str_replace('return null;', "return \$this->invited(\$member) ? null : 'Not invited';", File::get("{$this->dir}/app/Models/Lesson.php")));
    ($this->git)('commit', '-qam', 'Only invited members');

    expect(($this->reach)())->toContain("- `app/Livewire/Booking.php::pay` (on the page GET|HEAD /booking; behind auth: an action's own request runs only the middleware Livewire persists), through `createParticipant`");
});

it('says nothing when the change makes no check', function () {
    ($this->put)('app/Services/PaymentService.php', str_replace('$lesson->createParticipant($member);', "\$lesson->createParticipant(\$member);\n        logger('paid');", File::get("{$this->dir}/app/Services/PaymentService.php")));
    ($this->git)('commit', '-qam', 'Log payments');

    expect(($this->reach)())->toBe('');
});

it('gives every reviewer the reach of a Laravel change', function () {
    foreach (['run-claude.sh', 'run-codex.sh', 'run-pi.sh'] as $script) {
        expect((string) file_get_contents(__DIR__."/../../resources/review/skill/{$script}"))->toContain('reviewer_reach');
    }
});

it('tells two route closures in one file apart, so a checked one does not hide an unchecked one', function () {
    ($this->put)('routes/web.php', <<<'PHP'
        <?php

        Route::post('/book/{lesson}', function (Lesson $lesson) {
            abort_if($lesson->hasRegisterError(auth()->user()), 403);
            $lesson->createParticipant(auth()->user());
        });

        Route::post('/pay/{lesson}', function (Lesson $lesson) {
            $lesson->createParticipant(auth()->user());
        });
        PHP);
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Routes');
    ($this->put)('app/Models/Lesson.php', str_replace('return null;', "return \$this->invited(\$member) ? null : 'Not invited';", File::get("{$this->dir}/app/Models/Lesson.php")));
    ($this->git)('commit', '-qam', 'Only invited members');

    expect(($this->reach)())
        ->toContain('- `routes/web.php::(closure at line 8)`, through `createParticipant`')
        ->not->toContain('- `routes/web.php::(closure at line 3)`, through');
});

it('says nothing when only a property after an unchanged check changes', function () {
    $lesson = fn (string $table): string => "<?php\n\nclass Lesson\n{\n    public function moduleAccessError(\$member)\n    {\n        return null;\n    }\n\n    protected \$table = '{$table}';\n}\n";
    ($this->put)('app/Models/Lesson.php', $lesson('lessons'));
    ($this->git)('commit', '-qam', 'Base');
    ($this->put)('app/Models/Lesson.php', $lesson('group_lessons'));
    ($this->git)('commit', '-qam', 'Rename the table');

    expect(($this->reach)())->toBe('');
});

it('follows the code index when the review built one, with calls through a parent and not a sibling\'s own', function () {
    ($this->put)('app/Models/Lesson.php', str_replace('return null;', "return \$this->invited(\$member) ? null : 'Not invited';", File::get("{$this->dir}/app/Models/Lesson.php")));
    ($this->git)('commit', '-qam', 'Only invited members');

    // As code-index-worker.php writes it: calls resolved by type, every name in lower case.
    $index = "{$this->dir}/.index";
    File::ensureDirectoryExists($index);
    File::put("{$index}/edges.tsv", implode("\n", [
        "bookingcontroller::store\tlesson::hasregistererror",
        "lesson::hasregistererror\tlesson::moduleaccesserror",
        "bookingcontroller::store\tlesson::createparticipant",
        "paymentservice::pay\tlesson::createparticipant",
        "paymentcontroller::pay\tpaymentservice::pay",
        // Through the parent: from outside the family it can land on Lesson's; from a sibling it is the sibling's own.
        "invoicecontroller::store\trecord::createparticipant",
        "course::enrol\trecord::createparticipant",
        "coursecontroller::store\tcourse::enrol",
    ])."\n");
    File::put("{$index}/classes.tsv", implode("\n", [
        "lesson\tapp/Models/Lesson.php", "bookingcontroller\tapp/Http/Controllers/BookingController.php",
        "paymentservice\tapp/Services/PaymentService.php", "paymentcontroller\tapp/Http/Controllers/PaymentController.php",
        "invoicecontroller\tapp/Http/Controllers/InvoiceController.php", "course\tapp/Models/Course.php",
        "coursecontroller\tapp/Http/Controllers/CourseController.php",
    ])."\n");
    File::put("{$index}/ancestors.tsv", "lesson\trecord\ncourse\trecord\n");

    $reach = Process::path($this->dir)->env(['REACH_INDEX' => $index])
        ->run(['php', realpath(__DIR__.'/../../resources/review/skill/reach.php'), 'main', 'HEAD'])->throw()->output();

    expect($reach)
        ->toContain('from a code index that resolves each call by the type')
        ->toContain('- `app/Http/Controllers/PaymentController.php::pay`, through `createParticipant`')
        ->toContain('- `app/Http/Controllers/InvoiceController.php::store`, through `createParticipant`')
        ->not->toContain('CourseController')
        ->not->toContain('`app/Http/Controllers/BookingController.php::store`, through');
});

it('lets the sandbox take the code index setting from the server', function () {
    expect((string) file_get_contents(__DIR__.'/../../resources/review/skill/review-settings.sh'))->toContain(' REVIEW_CODE_INDEX ')
        ->and((string) file_get_contents(__DIR__.'/../../resources/review/skill/reviewer-env.sh'))->toContain('reviewer_code_index "$_php"');
});

it('installs no Mago whose download does not match its checksum', function () {
    // A curl that hands back something else than the release.
    File::ensureDirectoryExists("{$this->dir}/bin");
    File::put("{$this->dir}/bin/curl", "#!/bin/sh\nwhile [ \$# -gt 0 ]; do [ \"\$1\" = -o ] && { echo tampered > \"\$2\"; shift; }; shift; done\n");
    chmod("{$this->dir}/bin/curl", 0755);
    $skill = realpath(__DIR__.'/../../resources/review/skill');

    $run = Process::path($this->dir)
        ->env(['HOME' => "{$this->dir}/home", 'PATH' => "{$this->dir}/bin:".getenv('PATH'), 'HERE' => $skill])
        ->run(['sh', '-c', ". {$skill}/reviewer-env.sh; HERE={$skill}; reviewer_mago php"]);

    expect($run->successful())->toBeFalse()
        ->and($run->output())->toBe('')
        ->and(File::exists("{$this->dir}/home/.tools/code-index-1.61.0"))->toBeFalse();
});

it('tells two route closures in one file apart with the code index too', function () {
    ($this->put)('routes/web.php', <<<'PHP'
        <?php

        Route::post('/book/{lesson}', function (Lesson $lesson) {
            abort_if($lesson->hasRegisterError(auth()->user()), 403);
            $lesson->createParticipant(auth()->user());
        });

        Route::post('/pay/{lesson}', function (Lesson $lesson) {
            $lesson->createParticipant(auth()->user());
        });
        PHP);
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Routes');
    ($this->put)('app/Models/Lesson.php', str_replace('return null;', "return \$this->invited(\$member) ? null : 'Not invited';", File::get("{$this->dir}/app/Models/Lesson.php")));
    ($this->git)('commit', '-qam', 'Only invited members');

    // The index knows a route file's closures only as the file.
    $index = "{$this->dir}/.index";
    File::ensureDirectoryExists($index);
    File::put("{$index}/edges.tsv", "file:routes/web.php\tlesson::hasregistererror\nlesson::hasregistererror\tlesson::moduleaccesserror\nfile:routes/web.php\tlesson::createparticipant\n");
    File::put("{$index}/classes.tsv", "lesson\tapp/Models/Lesson.php\n");
    File::put("{$index}/ancestors.tsv", '');

    expect(Process::path($this->dir)->env(['REACH_INDEX' => $index])->run(['php', realpath(__DIR__.'/../../resources/review/skill/reach.php'), 'main', 'HEAD'])->throw()->output())
        ->toContain('- `routes/web.php::(closure at line 8)`, through `createParticipant`')
        ->not->toContain('- `routes/web.php::(closure at line 3)`, through');
});

it('gives no reach when the team turned it off', function () {
    ($this->put)('app/Models/Lesson.php', str_replace('return null;', "return \$this->invited(\$member) ? null : 'Not invited';", File::get("{$this->dir}/app/Models/Lesson.php")));
    ($this->git)('commit', '-qam', 'Only invited members');
    ($this->git)('update-ref', 'refs/remotes/origin/main', 'main');
    File::put("{$this->dir}/review-context.json", json_encode(['settings' => ['stack' => ['laravel']], 'pull_request' => ['destination_branch' => 'main']]));
    $skill = realpath(__DIR__.'/../../resources/review/skill');
    $reach = fn (array $env): string => Process::path($this->dir)->env($env)->run(['sh', '-c', ". {$skill}/reviewer-env.sh; HERE={$skill}; reviewer_reach"])->throw()->output();

    expect($reach([]))->toContain('REACH:')
        ->and($reach(['REVIEW_REACH' => 'false']))->toBe('');
});

it('builds the code index only for a change to PHP in app/, and goes by name when it cannot', function () {
    ($this->git)('update-ref', 'refs/remotes/origin/main', 'main');
    File::put("{$this->dir}/review-context.json", json_encode(['settings' => ['stack' => ['laravel']], 'pull_request' => ['destination_branch' => 'main']]));
    // A curl that leaves a mark, and fails the download.
    File::ensureDirectoryExists("{$this->dir}/bin");
    File::put("{$this->dir}/bin/curl", "#!/bin/sh\ntouch \"{$this->dir}/curl-called\"\nexit 22\n");
    chmod("{$this->dir}/bin/curl", 0755);
    $skill = realpath(__DIR__.'/../../resources/review/skill');
    $reach = fn () => Process::path($this->dir)
        ->env(['REVIEW_CODE_INDEX' => 'true', 'HOME' => "{$this->dir}/home", 'PATH' => "{$this->dir}/bin:".getenv('PATH')])
        ->run(['sh', '-c', ". {$skill}/reviewer-env.sh; HERE={$skill}; reviewer_reach"])->throw();

    // No PHP in app/ changed: nothing is installed or built.
    ($this->put)('README.md', "Lessons\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Readme');
    $quiet = $reach();

    expect($quiet->output())->toBe('')
        ->and($quiet->errorOutput())->toContain('Code index: not built, as the change touches no PHP in app/.')
        // Its own phase in the run's log, on stderr: stdout is the prompt.
        ->and($quiet->errorOutput())->toContain('##[phase] reach')
        ->and($quiet->errorOutput())->toContain('Reach: nothing to say about this change, so the reviewer gets no REACH.')
        ->and(File::exists("{$this->dir}/curl-called"))->toBeFalse();

    // PHP in app/ changed without a check: the index is wanted for the calls with more than one implementation.
    ($this->put)('app/Services/PaymentService.php', str_replace('$lesson->createParticipant($member);', "\$lesson->createParticipant(\$member);\n        logger('paid');", File::get("{$this->dir}/app/Services/PaymentService.php")));
    ($this->git)('commit', '-qam', 'Log payments');

    expect($reach()->output())->toBe('')
        ->and(File::exists("{$this->dir}/curl-called"))->toBeTrue();
    File::delete("{$this->dir}/curl-called");

    // A check changed, and Mago cannot be had: the reach by name.
    ($this->put)('app/Models/Lesson.php', str_replace('return null;', "return \$this->invited(\$member) ? null : 'Not invited';", File::get("{$this->dir}/app/Models/Lesson.php")));
    ($this->git)('commit', '-qam', 'Only invited members');
    $byName = $reach();

    expect($byName->output())->toContain('by method name')
        // The log has what the reviewer got, line for line.
        ->and($byName->errorOutput())->toContain('Reach: 1 check(s), 1 way(s) in listed, 0 call(s) with more than one implementation, 0 thing(s) the guards rely on; as the reviewer gets it:')
        ->and($byName->errorOutput())->toContain('  |   - `app/Http/Controllers/PaymentController.php::pay`, through `createParticipant`')
        ->and(File::exists("{$this->dir}/curl-called"))->toBeTrue();
});

it('names, with the code index, the calls of a changed method that can land on more than one implementation', function () {
    // The gateways are there before the change.
    ($this->git)('checkout', '-q', 'main');
    ($this->put)('app/Payments/Gateway.php', "<?php\n\ninterface Gateway\n{\n    public function refund(\$amount);\n}\n");
    foreach (['Card', 'Cash'] as $kind) {
        ($this->put)("app/Payments/{$kind}Gateway.php", "<?php\n\nclass {$kind}Gateway implements Gateway\n{\n    public static function init()\n    {\n        return new self;\n    }\n\n    public function charge(\$amount)\n    {\n    }\n\n    public function refund(\$amount)\n    {\n    }\n}\n");
    }
    ($this->put)('app/Payments/Receipt.php', "<?php\n\nclass Receipt\n{\n    public function print()\n    {\n    }\n}\n");
    ($this->put)('app/Payments/Ledger.php', "<?php\n\nclass Ledger\n{\n    public function book(\$gateway)\n    {\n        \$gateway->refund(1);\n    }\n}\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Gateways');
    ($this->git)('checkout', '-q', 'feature');
    ($this->git)('rebase', '-q', 'main');
    // The change charges through a factory that returns one gateway or the other, and books through a helper.
    ($this->put)('app/Services/PaymentService.php', str_replace('$lesson->createParticipant($member);', "\$lesson->createParticipant(\$member);\n        Gateways::for(\$member)->charge(10);\n        (new Receipt)->print();\n        (new Ledger)->book(null);", File::get("{$this->dir}/app/Services/PaymentService.php")));
    ($this->git)('commit', '-qam', 'Charge');

    $index = "{$this->dir}/.index";
    File::ensureDirectoryExists($index);
    File::put("{$index}/edges.tsv", implode("\n", [
        "paymentservice::pay\tlesson::createparticipant",
        "paymentservice::pay\tcardgateway::charge",
        "paymentservice::pay\tcashgateway::charge",
        "paymentservice::pay\treceipt::print",
        "paymentservice::pay\tledger::book",
        "paymentservice::pay\tpaymentservice::\$table",
        "ledger::book\tgateway::refund",
    ])."\n");
    File::put("{$index}/classes.tsv", implode("\n", [
        "lesson\tapp/Models/Lesson.php", "paymentservice\tapp/Services/PaymentService.php",
        "gateway\tapp/Payments/Gateway.php", "cardgateway\tapp/Payments/CardGateway.php",
        "cashgateway\tapp/Payments/CashGateway.php", "receipt\tapp/Payments/Receipt.php", "ledger\tapp/Payments/Ledger.php",
    ])."\n");
    File::put("{$index}/ancestors.tsv", "cardgateway\tgateway\ncashgateway\tgateway\n");

    $reach = Process::path($this->dir)->env(['REACH_INDEX' => $index])
        ->run(['php', realpath(__DIR__.'/../../resources/review/skill/reach.php'), 'main', 'HEAD'])->throw()->output();

    expect($reach)
        ->toContain('REACH:')
        // No check changed, so nothing about checks.
        ->not->toContain('For each check this change adds or changes')
        ->toContain('- `app/Services/PaymentService.php::pay`, which this change changes, calls `charge` on `CardGateway` (`app/Payments/CardGateway.php:10`) or `CashGateway` (`app/Payments/CashGateway.php:10`).')
        // A call one step on, through an interface: the implementations, not the declaration without a body.
        ->toContain('- `app/Payments/Ledger.php::book`, which the changed `pay` calls, calls `refund` on `CardGateway` (`app/Payments/CardGateway.php:14`) or `CashGateway` (`app/Payments/CashGateway.php:14`).')
        ->not->toContain('`print`')
        ->not->toContain('createParticipant');
});

it('tells separate calls of one name, inherited bodies, interfaces and parent:: apart', function () {
    ($this->git)('checkout', '-q', 'main');
    ($this->put)('app/Payments/Gateway.php', "<?php\n\ninterface Gateway\n{\n    public function refund(\$amount);\n}\n");
    // Card runs the refund it inherits from a parent outside the interface; cash has its own.
    ($this->put)('app/Payments/BaseGateway.php', "<?php\n\nclass BaseGateway\n{\n    public function refund(\$amount)\n    {\n    }\n}\n");
    ($this->put)('app/Payments/CardGateway.php', "<?php\n\nclass CardGateway extends BaseGateway implements Gateway\n{\n    public function charge(\$amount)\n    {\n    }\n}\n");
    ($this->put)('app/Payments/CashGateway.php', "<?php\n\nclass CashGateway implements Gateway\n{\n    public function charge(\$amount)\n    {\n    }\n\n    public function refund(\$amount)\n    {\n    }\n}\n");
    ($this->put)('app/Payments/Processor.php', "<?php\n\ninterface Processor\n{\n    public function run();\n}\n");
    ($this->put)('app/Payments/QueueProcessor.php', "<?php\n\nclass QueueProcessor implements Processor\n{\n    public function run()\n    {\n        Gateways::any()->charge(1);\n    }\n}\n");
    ($this->put)('app/Payments/Step.php', "<?php\n\nclass Step\n{\n    public function run(Step \$next)\n    {\n    }\n}\n");
    ($this->put)('app/Payments/LastStep.php', "<?php\n\nclass LastStep extends Step\n{\n    public function run(Step \$next)\n    {\n    }\n}\n");
    ($this->put)('app/Payments/OnlyParentStep.php', "<?php\n\nclass OnlyParentStep extends Step\n{\n    public function run(Step \$next)\n    {\n    }\n}\n");
    // A sibling's override never runs for $this.
    ($this->put)('app/Payments/BaseReport.php', "<?php\n\nclass BaseReport\n{\n    public function respond()\n    {\n    }\n}\n");
    ($this->put)('app/Payments/DayReport.php', "<?php\n\nclass DayReport extends BaseReport\n{\n    public function show()\n    {\n    }\n}\n");
    ($this->put)('app/Payments/WeekReport.php', "<?php\n\nclass WeekReport extends BaseReport\n{\n    public function respond()\n    {\n    }\n}\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Payments');
    ($this->git)('checkout', '-q', 'feature');
    ($this->git)('rebase', '-q', 'main');

    // The change: two separate charges, a refund through the interface, a processor
    // through its interface, and two overrides, one of which calls only parent::run().
    ($this->put)('app/Services/PaymentService.php', str_replace('$lesson->createParticipant($member);', "\$lesson->createParticipant(\$member);\n        \$card->charge(1);\n        \$cash->charge(2);\n        \$gateway->refund(3);\n        \$processor->run();", File::get("{$this->dir}/app/Services/PaymentService.php")));
    ($this->put)('app/Payments/LastStep.php', "<?php\n\nclass LastStep extends Step\n{\n    public function run(Step \$next)\n    {\n        parent::run(\$next);\n        \$next->run(\$this);\n    }\n}\n");
    ($this->put)('app/Payments/OnlyParentStep.php', "<?php\n\nclass OnlyParentStep extends Step\n{\n    public function run(Step \$next)\n    {\n        parent::run(\$next);\n    }\n}\n");
    ($this->put)('app/Payments/DayReport.php', "<?php\n\nclass DayReport extends BaseReport\n{\n    public function show()\n    {\n        return \$this->respond();\n    }\n}\n");
    ($this->git)('commit', '-qam', 'Pay');

    $index = "{$this->dir}/.index";
    File::ensureDirectoryExists($index);
    File::put("{$index}/edges.tsv", implode("\n", [
        "paymentservice::pay\tcardgateway::charge",
        "paymentservice::pay\tcashgateway::charge",
        "paymentservice::pay\tgateway::refund",
        "paymentservice::pay\tprocessor::run",
        "queueprocessor::run\tcardgateway::charge",
        "queueprocessor::run\tcashgateway::charge",
        "laststep::run\tstep::run",
        "onlyparentstep::run\tstep::run",
        "dayreport::show\tbasereport::respond",
    ])."\n");
    File::put("{$index}/classes.tsv", implode("\n", array_map(
        fn (string $class): string => strtolower($class)."\tapp/Payments/{$class}.php",
        ['Gateway', 'BaseGateway', 'CardGateway', 'CashGateway', 'Processor', 'QueueProcessor', 'Step', 'LastStep', 'OnlyParentStep', 'BaseReport', 'DayReport', 'WeekReport'],
    ))."\npaymentservice\tapp/Services/PaymentService.php\n");
    File::put("{$index}/ancestors.tsv", "cardgateway\tbasegateway\ncardgateway\tgateway\ncashgateway\tgateway\nqueueprocessor\tprocessor\nlaststep\tstep\nonlyparentstep\tstep\ndayreport\tbasereport\nweekreport\tbasereport\n");

    $reach = Process::path($this->dir)->env(['REACH_INDEX' => $index])
        ->run(['php', realpath(__DIR__.'/../../resources/review/skill/reach.php'), 'main', 'HEAD'])->throw()->output();

    expect($reach)
        // Two calls of one name are not one call that can run either.
        ->not->toContain('PaymentService.php::pay`, which this change changes, calls `charge`')
        // The body a class inherits counts, from a parent outside the interface.
        ->toContain('calls `refund` on `BaseGateway` (`app/Payments/BaseGateway.php:5`, as `CardGateway`) or `CashGateway` (`app/Payments/CashGateway.php:9`).')
        // Through an interface on into the body that runs.
        ->toContain('- `app/Payments/QueueProcessor.php::run`, which the changed `pay` calls, calls `charge` on `CardGateway`')
        // A call on another object of the parent's type is no parent:: call; parent:: alone is.
        ->toContain('- `app/Payments/LastStep.php::run`, which this change changes, calls `run` on `LastStep` (`app/Payments/LastStep.php:5`) or `OnlyParentStep` (`app/Payments/OnlyParentStep.php:5`) or `Step` (`app/Payments/Step.php:5`).')
        ->not->toContain('OnlyParentStep.php::run`, which this change changes')
        ->not->toContain('calls `respond`');
});

it('follows each body with the classes that run it, magic methods included, and counts no call in a comment', function () {
    $class = fn (string $name, string $body, string $extends = ''): string => "<?php\n\nclass {$name}{$extends}\n{\n{$body}}\n";
    $method = fn (string $signature, string $code = ''): string => "    public function {$signature}\n    {\n".($code === '' ? '' : "        {$code}\n")."    }\n";
    $files = [
        'Gateway' => "<?php\n\ninterface Gateway\n{\n}\n",
        'CardGateway' => $class('CardGateway', $method('charge($amount)').$method('settle($amount)')),
        'CashGateway' => $class('CashGateway', $method('charge($amount)').$method('settle($amount)')),
        'BaseStep' => $class('BaseStep', $method('run()')),
        // A sibling override that charges: it never runs for the child's parent::run().
        'SiblingStep' => $class('SiblingStep', $method('run()', 'Gateways::any()->charge(1);'), ' extends BaseStep'),
        'ChildStep' => $class('ChildStep', $method('run()'), ' extends BaseStep'),
        'Processor' => "<?php\n\ninterface Processor\n{\n    public function run();\n}\n",
        // The processor's run is inherited, and $this in it is a CardRunner: OtherRunner's respond never runs.
        'BaseRunner' => $class('BaseRunner', $method('run()', '$this->respond();').$method('respond()')),
        'CardRunner' => $class('CardRunner', '', ' extends BaseRunner implements Processor'),
        'OtherRunner' => $class('OtherRunner', $method('respond()'), ' extends BaseRunner'),
        'Runner' => $class('Runner', $method('__invoke()', 'Gateways::any()->settle(1);')),
        'PayDesk' => $class('PayDesk', $method('pay(Processor $processor, Runner $runner)')),
        // Two interfaces, one call: both runners share the base's run, each with its own reply.
        'Sender' => "<?php\n\ninterface Sender\n{\n    public function run();\n}\n",
        'Mailer' => "<?php\n\ninterface Mailer\n{\n    public function run();\n}\n",
        'BaseSender' => $class('BaseSender', $method('run()', '$this->reply();')),
        'MailSender' => $class('MailSender', $method('reply()'), ' extends BaseSender implements Sender'),
        'TextSender' => $class('TextSender', $method('reply()'), ' extends BaseSender implements Mailer'),
        'Outbox' => $class('Outbox', $method('send($sender)')),
        'Refunder' => $class('Refunder', $method('refund()')),
    ];
    ($this->git)('checkout', '-q', 'main');
    foreach ($files as $name => $text) {
        ($this->put)("app/Desk/{$name}.php", $text);
    }
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Desk');
    ($this->git)('checkout', '-q', 'feature');
    ($this->git)('rebase', '-q', 'main');
    ($this->put)('app/Desk/ChildStep.php', $class('ChildStep', $method('run()', 'parent::run();'), ' extends BaseStep'));
    ($this->put)('app/Desk/PayDesk.php', $class('PayDesk', $method('pay(Processor $processor, Runner $runner)', "\$processor->run();\n        \$runner();")));
    ($this->put)('app/Desk/Outbox.php', $class('Outbox', $method('send($sender)', '$sender->run();')));
    ($this->put)('app/Desk/Refunder.php', $class('Refunder', $method('refund()', "// \$gateway->charge(1) was the old way\n        Gateways::any()->charge(2);")));
    ($this->git)('commit', '-qam', 'Desk changes');

    $index = "{$this->dir}/.index";
    File::ensureDirectoryExists($index);
    File::put("{$index}/edges.tsv", implode("\n", [
        "childstep::run\tbasestep::run",
        "siblingstep::run\tcardgateway::charge",
        "siblingstep::run\tcashgateway::charge",
        "paydesk::pay\tprocessor::run",
        "paydesk::pay\trunner::__invoke",
        "baserunner::run\tbaserunner::respond",
        "runner::__invoke\tcardgateway::settle",
        "runner::__invoke\tcashgateway::settle",
        "outbox::send\tsender::run",
        "outbox::send\tmailer::run",
        "basesender::run\tbasesender::reply",
        "refunder::refund\tcardgateway::charge",
        "refunder::refund\tcashgateway::charge",
    ])."\n");
    File::put("{$index}/classes.tsv", implode("\n", array_map(fn (string $name): string => strtolower($name)."\tapp/Desk/{$name}.php", array_keys($files)))."\n");
    File::put("{$index}/ancestors.tsv", "siblingstep\tbasestep\nchildstep\tbasestep\ncardrunner\tbaserunner\ncardrunner\tprocessor\notherrunner\tbaserunner\nmailsender\tbasesender\nmailsender\tsender\ntextsender\tbasesender\ntextsender\tmailer\n");

    $reach = Process::path($this->dir)->env(['REACH_INDEX' => $index])
        ->run(['php', realpath(__DIR__.'/../../resources/review/skill/reach.php'), 'main', 'HEAD'])->throw()->output();

    expect($reach)
        // parent::run() runs the parent's body, so the sibling's charge is not reached.
        ->not->toContain('SiblingStep')
        // $this in the inherited run is a CardRunner, which runs BaseRunner's respond only.
        ->not->toContain('`respond`')
        // Through __invoke into its body.
        ->toContain('- `app/Desk/Runner.php::__invoke`, which the changed `pay` calls, calls `settle` on `CardGateway`')
        // A body two targets of one call share runs for the classes of both.
        ->toContain('- `app/Desk/BaseSender.php::run`, which the changed `send` calls, calls `reply` on `MailSender` (`app/Desk/MailSender.php:5`) or `TextSender` (`app/Desk/TextSender.php:5`).')
        // A call in a comment is no second call.
        ->toContain('- `app/Desk/Refunder.php::refund`, which this change changes, calls `charge` on `CardGateway`');
});

it('names, with the code index, what the change\'s guards rely on: the writers of what a check reads, the calls a lock holds and the counter a success clears', function () {
    ($this->git)('checkout', '-q', 'main');
    ($this->put)('app/Models/Payment.php', "<?php\n\nnamespace App\\Models;\n\nclass Payment\n{\n    public const PIN = 'pin';\n    public const ONLINE = 'online';\n}\n");
    ($this->put)('app/Services/Till.php', "<?php\n\nnamespace App\\Services;\n\nuse App\\Models\\Payment;\n\nclass Till\n{\n    public function book(\$reference)\n    {\n        \$payment = new Payment;\n        \$payment->reference = \$reference;\n        \$payment->save();\n    }\n}\n");
    ($this->put)('app/Http/Controllers/TerminalController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\Till;\n\nclass TerminalController\n{\n    public function paid(Till \$till)\n    {\n        \$till->book(request('transaction'));\n    }\n}\n");
    ($this->put)('app/Http/Controllers/OnlineController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass OnlineController\n{\n    public function pay(\$order)\n    {\n    }\n\n    public function login(\$key)\n    {\n    }\n}\n");
    // Reads the column into an array it returns: no writer.
    ($this->put)('app/Http/Resources/PaymentResource.php', "<?php\n\nnamespace App\\Http\\Resources;\n\nuse Illuminate\\Support\\Collection;\n\nclass PaymentResource\n{\n    public function toArray(\$payment)\n    {\n        return ['reference' => \$payment->reference];\n    }\n\n    public function toCollection(\$payment)\n    {\n        return Collection::make(['reference' => \$payment->reference]);\n    }\n}\n");
    // Writes in other shapes: static:: on the model, a fully qualified name without an import,
    // inside a wrapper's arguments, made and saved; and one that only reads an element.
    ($this->put)('app/Models/Payment.php', "<?php\n\nnamespace App\\Models;\n\nclass Payment\n{\n    public const PIN = 'pin';\n    public const ONLINE = 'online';\n\n    public static function copyFrom(\$other)\n    {\n        return static::create(['reference' => \$other->reference]);\n    }\n}\n");
    ($this->put)('app/Services/Shapes.php', <<<'PHP'
        <?php

        namespace App\Services;

        class Shapes
        {
            public function qualified($reference)
            {
                \App\Models\Payment::create(['reference' => $reference]);
            }

            public function wrapped($reference)
            {
                return new Receipt(\App\Models\Payment::create(['reference' => $reference]));
            }

            public function madeAndSaved($reference)
            {
                $copy = \App\Models\Payment::make(['reference' => $reference]);
                $copy->save();
            }

            public function onlyTheAmount($payment, $target)
            {
                $attributes = ['reference' => $payment->reference, 'amount' => 1];
                $target->update(['amount' => $attributes['amount']]);
            }
        }
        PHP);
    // Writes the column through an array of attributes it holds first.
    ($this->put)('app/Services/Import.php', "<?php\n\nnamespace App\\Services;\n\nuse App\\Models\\Payment;\n\nclass Import\n{\n    public function row(\$line)\n    {\n        \$attributes = ['type' => Payment::PIN, 'reference' => \$line[3]];\n\n        return Payment::create(\$attributes);\n    }\n}\n");
    // A lock whose lifetime the change edits on a line of its own.
    ($this->put)('app/Services/Refunds.php', "<?php\n\nnamespace App\\Services;\n\nuse Acme\\Sdk\\Client;\nuse Illuminate\\Support\\Facades\\Cache;\n\nclass Refunds\n{\n    public function refund(\$order)\n    {\n        Cache::lock(\n            'refund:'.\$order,\n            300\n        )->block(5, fn () => (new Client)->refund(\$order));\n    }\n}\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Payments');
    ($this->git)('checkout', '-q', 'feature');
    ($this->git)('rebase', '-q', 'main');
    ($this->put)('app/Http/Controllers/OnlineController.php', <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        use App\Models\Payment;
        use Acme\Sdk\Client;
        use Illuminate\Support\Facades\Cache;
        use Illuminate\Support\Facades\RateLimiter;

        class OnlineController
        {
            public function pay($order)
            {
                $lock = Cache::lock('pay:'.$order, 30);
                $lock->block(5);
                $booked = Payment::where('type', Payment::ONLINE)
                    ->where('reference', $order)
                    ->exists();
                (new Client)->verify($order);
            }

            public function login($key)
            {
                RateLimiter::hit('login:'.request()->ip());
                RateLimiter::clear(
                    'login:'.request()->ip()
                );
                RateLimiter::clear(hash('sha256', strtolower(request()->input('email'))));
            }
        }
        PHP);
    ($this->put)('app/Services/Refunds.php', str_replace('            300', '            30', File::get("{$this->dir}/app/Services/Refunds.php")));
    ($this->git)('commit', '-qam', 'Pay once, verified');

    $index = "{$this->dir}/.index";
    File::ensureDirectoryExists($index);
    File::put("{$index}/edges.tsv", implode("\n", [
        "app\\http\\controllers\\onlinecontroller::pay\tapp\\models\\payment::\$reference",
        "app\\http\\controllers\\onlinecontroller::pay\tacme\\sdk\\client::verify",
        "app\\services\\till::book\tapp\\models\\payment::\$reference",
        "app\\http\\resources\\paymentresource::toarray\tapp\\models\\payment::\$reference",
        "app\\http\\resources\\paymentresource::tocollection\tapp\\models\\payment::\$reference",
        "app\\services\\import::row\tapp\\models\\payment::\$reference",
        "app\\models\\payment::copyfrom\tapp\\models\\payment::\$reference",
        "app\\services\\shapes::qualified\tapp\\models\\payment::\$reference",
        "app\\services\\shapes::wrapped\tapp\\models\\payment::\$reference",
        "app\\services\\shapes::madeandsaved\tapp\\models\\payment::\$reference",
        "app\\services\\shapes::onlytheamount\tapp\\models\\payment::\$reference",
        "app\\services\\refunds::refund\tacme\\sdk\\client::refund",
        "app\\http\\controllers\\terminalcontroller::paid\tapp\\services\\till::book",
    ])."\n");
    File::put("{$index}/classes.tsv", implode("\n", [
        "app\\models\\payment\tapp/Models/Payment.php", "app\\services\\till\tapp/Services/Till.php",
        "app\\http\\controllers\\terminalcontroller\tapp/Http/Controllers/TerminalController.php", "app\\http\\controllers\\onlinecontroller\tapp/Http/Controllers/OnlineController.php",
        "app\\http\\resources\\paymentresource\tapp/Http/Resources/PaymentResource.php", "app\\services\\refunds\tapp/Services/Refunds.php",
        "app\\services\\import\tapp/Services/Import.php", "app\\services\\shapes\tapp/Services/Shapes.php",
    ])."\n");
    File::put("{$index}/ancestors.tsv", '');

    $reach = Process::path($this->dir)->env(['REACH_INDEX' => $index])
        ->run(['php', realpath(__DIR__.'/../../resources/review/skill/reach.php'), 'main', 'HEAD'])->throw()->output();

    expect($reach)
        ->toContain("What the change's guards rely on outside them:")
        // The check counts one type of row; another writer of the column writes others.
        ->toContain('- `app/Http/Controllers/OnlineController.php::pay` checks `Payment` rows by `reference` (line 16), counting only those with `type` = `Payment::ONLINE`. These write `reference` on `Payment`: `app/Services/Till.php::book` (line 12), called from `app/Http/Controllers/TerminalController.php::paid`;')
        ->not->toContain('PaymentResource')
        ->toContain('`app/Models/Payment.php::copyFrom` (line 12)')
        ->toContain('`app/Services/Shapes.php::qualified` (line 9)')
        ->toContain('`app/Services/Shapes.php::wrapped` (line 14)')
        ->toContain('`app/Services/Shapes.php::madeAndSaved` (line 19)')
        // An element read from an array is not the array written.
        ->not->toContain('onlyTheAmount')
        // Through the attributes it holds first.
        ->toContain('`app/Services/Import.php::row` (line 13)')
        // A key normalized in nested calls.
        ->toContain('clears `hash(\'sha256\', strtolower(request()->input(\'email\')))`')
        ->toContain('takes lock \'pay:\' for 30 s (line 14), and the method also makes calls that leave the application: `OnlineController::pay` calls `acme\sdk`.')
        // A lifetime changed on a line of its own.
        ->toContain('- `app/Services/Refunds.php::refund` takes lock \'refund:\' for 30 s (line 12)')
        ->toContain('- `app/Http/Controllers/OnlineController.php::login` clears `\'login:\'.request()->ip()` (line 25), and failures are counted in `\'login:\'.request()->ip()` (line 24).')
        ->not->toContain('`type` on `Payment`');
});

it('takes a policy\'s methods, and a method a changed line guards, as checks', function () {
    ($this->put)('app/Policies/LessonPolicy.php', "<?php\n\nclass LessonPolicy\n{\n    public function book(\$user, Lesson \$lesson)\n    {\n        return true;\n    }\n}\n");
    ($this->put)('app/Http/Controllers/LessonController.php', <<<'PHP'
        <?php

        class LessonController
        {
            public function store(Lesson $lesson)
            {
                $lesson->participants()->create(['member_id' => auth()->id()]);
            }

            public function update(Lesson $lesson)
            {
                $lesson->participants()->update(['paid' => true]);
            }

            public function destroy(Lesson $lesson)
            {
                abort_unless(auth()->user()->isAdmin(), 403);
                $lesson->participants()->delete();
            }
        }
        PHP);
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Lessons');

    // The policy decides by the lesson now, and store checks who may book.
    ($this->put)('app/Policies/LessonPolicy.php', str_replace('return true;', 'return $lesson->open;', File::get("{$this->dir}/app/Policies/LessonPolicy.php")));
    ($this->put)('app/Http/Controllers/LessonController.php', str_replace("    {\n        \$lesson->participants()->create", "    {\n        abort_if(! \$lesson->open, 403);\n        \$lesson->participants()->create", File::get("{$this->dir}/app/Http/Controllers/LessonController.php")));
    ($this->git)('commit', '-qam', 'Only open lessons');

    expect(($this->reach)())
        ->toContain('`app/Policies/LessonPolicy.php::book`')
        ->toContain('`app/Http/Controllers/LessonController.php::store`')
        // A sibling action that writes without a guard; one with its own guard is left out.
        ->toContain('- `app/Http/Controllers/LessonController.php::update`, which writes itself, with no guard of its own')
        ->not->toContain('LessonController.php::destroy`, which');
});

it('does not list an action that reaches the check through a helper as one without it', function () {
    ($this->put)('app/Http/Controllers/BookingFlowController.php', <<<'PHP'
        <?php

        class BookingFlowController
        {
            public function store(Lesson $lesson)
            {
                $this->enforceAccess($lesson);
                $lesson->participants()->create(['member_id' => auth()->id()]);
            }

            public function update(Lesson $lesson)
            {
                $lesson->participants()->update(['paid' => true]);
            }

            protected function enforceAccess(Lesson $lesson)
            {
                abort_unless($this->canBook($lesson), 403);
            }

            public function canBook(Lesson $lesson)
            {
                return true;
            }
        }
        PHP);
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Booking flow');
    ($this->put)('app/Http/Controllers/BookingFlowController.php', str_replace("        return true;\n", "        return \$lesson->open;\n", File::get("{$this->dir}/app/Http/Controllers/BookingFlowController.php")));
    ($this->git)('commit', '-qam', 'Only open lessons');

    expect(($this->reach)())
        ->toContain('- `app/Http/Controllers/BookingFlowController.php::update`, which writes itself, with no guard of its own')
        ->not->toContain('BookingFlowController.php::store`, which');
});

it('names, with the code index, the ways in that add to the models a changed check reads without passing it', function () {
    // A group of courses whose members are on a list; the check reads the list and the group.
    ($this->put)('app/Models/Group.php', <<<'PHP'
        <?php

        namespace App\Models;

        class Group extends Model
        {
            public function participants()
            {
                return $this->belongsToMany(Member::class);
            }
        }
        PHP);
    ($this->put)('app/Models/AllowedMember.php', "<?php\n\nnamespace App\\Models;\n\nclass AllowedMember extends Model\n{\n}\n");
    ($this->put)('app/Services/GroupAccess.php', <<<'PHP'
        <?php

        namespace App\Services;

        use App\Models\AllowedMember;
        use App\Models\Group;

        class GroupAccess
        {
            public function allows($group, $member)
            {
                return $this->isOpen($group) || AllowedMember::query()->where('member_id', $member->id)->exists();
            }

            private function isOpen($group)
            {
                return Group::query()->whereKey($group->id)->where('open', true)->exists();
            }
        }
        PHP);
    // Joins with the check; joins without it; and a form that adds through its parent's update.
    ($this->put)('app/Http/Controllers/JoinController.php', <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        class JoinController
        {
            public function store(Group $group, GroupAccess $access)
            {
                abort_unless($access->allows($group, auth()->user()), 403);
                $group->participants()->attach(auth()->id());
            }
        }
        PHP);
    ($this->put)('app/Http/Controllers/SignupController.php', <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        class SignupController
        {
            public function store(Group $group)
            {
                $member = Member::create(request()->all());
                $group->participants()
                    ->attach($member->id);
            }
        }
        PHP);
    ($this->put)('app/Http/Controllers/CrudController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass CrudController\n{\n    public function update(\$item)\n    {\n        \$this->handleForm(\$item);\n    }\n\n    protected function handleForm(\$item)\n    {\n    }\n}\n");
    ($this->put)('app/Http/Controllers/GroupController.php', <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        use App\Models\AllowedMember;

        class GroupController extends CrudController
        {
            protected function handleForm($group)
            {
                AllowedMember::create(['group_id' => $group->id]);
            }
        }
        PHP);
    ($this->put)('app/Http/Controllers/InvoiceController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass InvoiceController extends CrudController\n{\n}\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Groups');
    ($this->put)('app/Services/GroupAccess.php', str_replace("where('open', true)", "where('open', true)->whereNull('closed_at')", File::get("{$this->dir}/app/Services/GroupAccess.php")));
    ($this->git)('commit', '-qam', 'A closed group is not open');

    $index = "{$this->dir}/.index";
    File::ensureDirectoryExists($index);
    File::put("{$index}/edges.tsv", implode("\n", [
        "app\\http\\controllers\\joincontroller::store\tapp\\services\\groupaccess::allows",
        "app\\http\\controllers\\joincontroller::store\tapp\\models\\group::participants",
        "app\\http\\controllers\\signupcontroller::store\tapp\\models\\group::participants",
        "app\\http\\controllers\\crudcontroller::update\tapp\\http\\controllers\\crudcontroller::handleform",
    ])."\n");
    File::put("{$index}/classes.tsv", implode("\n", [
        "app\\models\\group\tapp/Models/Group.php", "app\\models\\allowedmember\tapp/Models/AllowedMember.php",
        "app\\services\\groupaccess\tapp/Services/GroupAccess.php", "app\\http\\controllers\\joincontroller\tapp/Http/Controllers/JoinController.php",
        "app\\http\\controllers\\signupcontroller\tapp/Http/Controllers/SignupController.php", "app\\http\\controllers\\crudcontroller\tapp/Http/Controllers/CrudController.php",
        "app\\http\\controllers\\groupcontroller\tapp/Http/Controllers/GroupController.php", "app\\http\\controllers\\invoicecontroller\tapp/Http/Controllers/InvoiceController.php",
    ])."\n");
    File::put("{$index}/ancestors.tsv", implode("\n", [
        "app\\models\\group\tilluminate\\database\\eloquent\\model", "app\\models\\allowedmember\tilluminate\\database\\eloquent\\model",
        "app\\http\\controllers\\groupcontroller\tapp\\http\\controllers\\crudcontroller", "app\\http\\controllers\\invoicecontroller\tapp\\http\\controllers\\crudcontroller",
    ])."\n");

    $reach = Process::path($this->dir)->env(['REACH_INDEX' => $index])
        ->run(['php', realpath(__DIR__.'/../../resources/review/skill/reach.php'), 'main', 'HEAD'])->throw()->output();

    expect($reach)
        ->toContain('`allows` reads `AllowedMember`, `Group`. These add to it without passing `allows`:')
        ->toContain('- `app/Http/Controllers/SignupController.php::store`, with `Group->participants()->attach()` at line 10')
        // The parent's update runs the form for this class only, and the form is no way in itself.
        ->toContain('- `app/Http/Controllers/GroupController.php::update`, through `handleForm`, with `AllowedMember::create()` at `app/Http/Controllers/GroupController.php:11`')
        ->not->toContain('InvoiceController')
        ->not->toContain('CrudController.php::update`, through')
        ->not->toContain('CrudController.php::update`, with')
        ->not->toContain('GroupController.php::handleForm`,')
        ->not->toContain('JoinController.php::store`, with');
});

it('finds the data a check reads through a trait\'s relation and a renamed import, and names a model added to everywhere with a count', function () {
    ($this->put)('app/Models/Concerns/HasParticipants.php', "<?php\n\nnamespace App\\Models\\Concerns;\n\ntrait HasParticipants\n{\n    public function participants()\n    {\n        return \$this->belongsToMany(\\App\\Models\\Member::class);\n    }\n}\n");
    ($this->put)('app/Models/Group.php', "<?php\n\nnamespace App\\Models;\n\nclass Group extends Model\n{\n    use Concerns\\HasParticipants;\n}\n");
    ($this->put)('app/Models/Event.php', "<?php\n\nnamespace App\\Models;\n\nclass Event extends Model\n{\n    use Concerns\\HasParticipants;\n}\n");
    ($this->put)('app/Models/AllowedMember.php', "<?php\n\nnamespace App\\Models;\n\nclass AllowedMember extends Model\n{\n}\n");
    ($this->put)('app/Models/Log.php', "<?php\n\nnamespace App\\Models;\n\nclass Log extends Model\n{\n}\n");
    ($this->put)('app/Services/GroupAccess.php', "<?php\n\nnamespace App\\Services;\n\nuse App\\Models\\AllowedMember;\nuse App\\Models\\Group;\nuse App\\Models\\Log;\n\nclass GroupAccess\n{\n    public function allows(\$group, \$member)\n    {\n        Log::query()->count();\n\n        return Group::query()->whereKey(\$group->id)->exists() && AllowedMember::query()->exists();\n    }\n}\n");
    ($this->put)('app/Http/Controllers/SignupController.php', <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        use App\Models\AllowedMember as Allowed;
        use App\Models\Event;
        use App\Models\Group;

        class SignupController
        {
            public function store($id)
            {
                Group::find($id)->participants()->attach(auth()->id());
            }

            public function both($id)
            {
                Group::find($id)->participants()->count();
                Event::find($id)->participants()->attach(auth()->id());
            }

            public function mixed($id)
            {
                Group::find($id)->participants()->count();
                Event::find($id)->participants()->attach(auth()->id());
            }

            public function grant()
            {
                Allowed::create(['member_id' => auth()->id()]);
            }

            public function invite()
            {
                \App\Models\AllowedMember::create(['member_id' => request('member')]);
            }
        }
        PHP);
    ($this->put)('app/Http/Controllers/EventController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Models\\Event;\n\nclass EventController\n{\n    public function join(\$id)\n    {\n        Event::find(\$id)->participants()->attach(auth()->id());\n    }\n}\n");

    // Every request writes a log line: a model added to everywhere.
    $logs = '';

    foreach (range(1, 11) as $number) {
        $logs .= "    public function write{$number}()\n    {\n        \\App\\Models\\Log::create([]);\n    }\n\n";
    }

    ($this->put)('app/Http/Controllers/LogController.php', "<?php\n\nnamespace App\\Http\\Controllers;\n\nclass LogController\n{\n{$logs}}\n");
    ($this->git)('add', '-A');
    ($this->git)('commit', '-qm', 'Groups');
    ($this->put)('app/Services/GroupAccess.php', str_replace('->exists() &&', "->where('open', true)->exists() &&", File::get("{$this->dir}/app/Services/GroupAccess.php")));
    ($this->git)('commit', '-qam', 'Only open groups');

    $index = "{$this->dir}/.index";
    File::ensureDirectoryExists($index);
    // A trait's relation is known by the trait; the model the call is on, by the other calls the method makes.
    File::put("{$index}/edges.tsv", implode("\n", [
        "app\\http\\controllers\\signupcontroller::store\tapp\\models\\group::find",
        "app\\http\\controllers\\signupcontroller::store\tapp\\models\\concerns\\hasparticipants::participants",
        "app\\http\\controllers\\signupcontroller::both\tapp\\models\\group::participants",
        "app\\http\\controllers\\signupcontroller::both\tapp\\models\\event::participants",
        // Both through the trait: the index cannot say which model the attach is on.
        "app\\http\\controllers\\signupcontroller::mixed\tapp\\models\\group::find",
        "app\\http\\controllers\\signupcontroller::mixed\tapp\\models\\event::find",
        "app\\http\\controllers\\signupcontroller::mixed\tapp\\models\\concerns\\hasparticipants::participants",
        "app\\http\\controllers\\eventcontroller::join\tapp\\models\\event::find",
        "app\\http\\controllers\\eventcontroller::join\tapp\\models\\concerns\\hasparticipants::participants",
    ])."\n");
    File::put("{$index}/classes.tsv", implode("\n", [
        "app\\models\\group\tapp/Models/Group.php", "app\\models\\event\tapp/Models/Event.php", "app\\models\\log\tapp/Models/Log.php",
        "app\\models\\allowedmember\tapp/Models/AllowedMember.php", "app\\models\\concerns\\hasparticipants\tapp/Models/Concerns/HasParticipants.php",
        "app\\services\\groupaccess\tapp/Services/GroupAccess.php", "app\\http\\controllers\\signupcontroller\tapp/Http/Controllers/SignupController.php",
        "app\\http\\controllers\\eventcontroller\tapp/Http/Controllers/EventController.php", "app\\http\\controllers\\logcontroller\tapp/Http/Controllers/LogController.php",
    ])."\n");
    File::put("{$index}/ancestors.tsv", implode("\n", array_map(fn (string $model): string => "app\\models\\{$model}\tilluminate\\database\\eloquent\\model", ['group', 'event', 'log', 'allowedmember']))."\n");

    $reach = Process::path($this->dir)->env(['REACH_INDEX' => $index])
        ->run(['php', realpath(__DIR__.'/../../resources/review/skill/reach.php'), 'main', 'HEAD'])->throw()->output();

    expect($reach)
        ->toContain('`allows` reads `Log`, `Group`, `AllowedMember` (of which `Log` (11 methods) are added to in too many places to list)')
        // A trait's relation, called on a value the index may not type: said so.
        ->toContain('- `app/Http/Controllers/SignupController.php::store`, with `Group->participants()->attach()` at line 13, if it is called on `Group`: `HasParticipants` gives other classes this relation too')
        ->toContain('- `app/Http/Controllers/SignupController.php::both`, with `Group->participants()->attach()` at line 19, or on `Event`, which it calls it on as well')
        ->toContain('- `app/Http/Controllers/SignupController.php::mixed`, with `Group->participants()->attach()` at line 25, or on `Event`, which it calls it on as well')
        ->toContain('- `app/Http/Controllers/SignupController.php::grant`, with `AllowedMember::create()` at line 30')
        ->toContain('- `app/Http/Controllers/SignupController.php::invite`, with `AllowedMember::create()` at line 35')
        // Event has the same trait; joining one is not joining a group.
        ->not->toContain('EventController')
        ->not->toContain('LogController');
});
