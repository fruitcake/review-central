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

it('builds the code index only for a change that touches a check, and goes by name when it cannot', function () {
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

    // No check changed: nothing is installed or built.
    ($this->put)('app/Services/PaymentService.php', str_replace('$lesson->createParticipant($member);', "\$lesson->createParticipant(\$member);\n        logger('paid');", File::get("{$this->dir}/app/Services/PaymentService.php")));
    ($this->git)('commit', '-qam', 'Log payments');
    $quiet = $reach();

    expect($quiet->output())->toBe('')
        ->and($quiet->errorOutput())->toContain('Code index: not built, as the change touches no check.')
        // Its own phase in the run's log, on stderr: stdout is the prompt.
        ->and($quiet->errorOutput())->toContain('##[phase] reach')
        ->and(File::exists("{$this->dir}/curl-called"))->toBeFalse();

    // A check changed, and Mago cannot be had: the reach by name.
    ($this->put)('app/Models/Lesson.php', str_replace('return null;', "return \$this->invited(\$member) ? null : 'Not invited';", File::get("{$this->dir}/app/Models/Lesson.php")));
    ($this->git)('commit', '-qam', 'Only invited members');
    $byName = $reach();

    expect($byName->output())->toContain('by method name')
        ->and(File::exists("{$this->dir}/curl-called"))->toBeTrue();
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
        ->not->toContain('CrudController.php::update')
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
        ->toContain('- `app/Http/Controllers/SignupController.php::store`, with `Group->participants()->attach()` at line 13')
        ->toContain('- `app/Http/Controllers/SignupController.php::both`, with `Group->participants()->attach()` at line 19, or on `Event`, which it calls it on as well')
        ->toContain('- `app/Http/Controllers/SignupController.php::grant`, with `AllowedMember::create()` at line 24')
        ->toContain('- `app/Http/Controllers/SignupController.php::invite`, with `AllowedMember::create()` at line 29')
        // Event has the same trait; joining one is not joining a group.
        ->not->toContain('EventController')
        ->not->toContain('LogController');
});
