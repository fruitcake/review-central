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
