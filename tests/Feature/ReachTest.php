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
