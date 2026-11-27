<?php

require_once __DIR__.'/../../resources/review/skill/code-index-models.php';

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/code-index-models-'.bin2hex(random_bytes(4));
    mkdir("{$this->dir}/Models/Concerns", 0777, true);
});

afterEach(function () {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($this->dir);
});

it('reads each relation\'s related model in full, as the file imports or names it', function () {
    file_put_contents("{$this->dir}/Models/Course.php", <<<'PHP'
        <?php

        namespace App\Models;

        use App\Models\People\Member as Person;
        use App\Domain\{Lesson, Teacher as Instructor};
        use Illuminate\Database\Eloquent\Collection;
        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\Relations\BelongsToMany;

        class Course extends Model
        {
            use Concerns\BelongsToClub;

            /** @return BelongsToMany<Person, $this> */
            public function participants(): BelongsToMany
            {
                return $this->belongsToMany(Person::class)->withPivot('start_at');
            }

            public function lessons()
            {
                return $this->hasMany(Lesson::class);
            }

            public function parent()
            {
                return $this->belongsTo(static::class, 'parent_id');
            }

            public function instructor()
            {
                return $this->belongsTo(Instructor::class);
            }

            /** Made from a relation, but no relation itself. */
            public function upcomingLessons(): Collection
            {
                return $this->hasMany(Lesson::class)->where('date', '>', now())->get();
            }

            public function firstLesson()
            {
                return $this->hasMany(Lesson::class)->oldest()->first();
            }

            public function label(): string
            {
                return $this->name;
            }
        }
        PHP);
    file_put_contents("{$this->dir}/Models/Concerns/BelongsToClub.php", <<<'PHP'
        <?php

        namespace App\Models\Concerns;

        trait BelongsToClub
        {
            public function club()
            {
                return $this->belongsTo(\App\Models\Club::class);
            }

            public function sameClub()
            {
                return $this->hasMany(self::class, 'club_id', 'club_id');
            }
        }
        PHP);

    expect(code_index_models($this->dir)['relations'])->toEqual([
        'app\models\course' => [
            'participants' => ['method' => 'participants', 'relation' => 'Illuminate\Database\Eloquent\Relations\BelongsToMany', 'related' => 'App\Models\People\Member', 'many' => true],
            'lessons' => ['method' => 'lessons', 'relation' => 'Illuminate\Database\Eloquent\Relations\HasMany', 'related' => 'App\Domain\Lesson', 'many' => true],
            // Which class static is, the call it is made on says: a subclass's parent is the subclass.
            'parent' => ['method' => 'parent', 'relation' => 'Illuminate\Database\Eloquent\Relations\BelongsTo', 'related' => 'static', 'many' => false],
            'instructor' => ['method' => 'instructor', 'relation' => 'Illuminate\Database\Eloquent\Relations\BelongsTo', 'related' => 'App\Domain\Teacher', 'many' => false],
        ],
        'app\models\concerns\belongstoclub' => [
            'club' => ['method' => 'club', 'relation' => 'Illuminate\Database\Eloquent\Relations\BelongsTo', 'related' => 'App\Models\Club', 'many' => false],
            'sameclub' => ['method' => 'sameClub', 'relation' => 'Illuminate\Database\Eloquent\Relations\HasMany', 'related' => 'self', 'many' => true],
        ],
    ]);
});

it('knows a local scope by the name it is called with, prefixed or marked as one', function () {
    file_put_contents("{$this->dir}/Models/Course.php", <<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Attributes\Scope;
        use Illuminate\Database\Eloquent\Builder;

        class Course
        {
            public function scopeCurrentClub(Builder $query): void {}

            #[Scope]
            protected function published(Builder $query): void {}

            protected function draft(Builder $query): void {}
        }
        PHP);

    $models = code_index_models($this->dir);

    // A method named as one is the scope only where #[Scope] marks it, which the worker asks per model.
    expect($models['scopes'])->toEqualCanonicalizing(['currentclub', 'published'])
        ->and($models['marked'])->toBe(['app\models\course' => ['published']]);
});

it('reads nothing where there is no app directory', function () {
    expect(code_index_models("{$this->dir}/missing"))->toBe(['scopes' => [], 'marked' => [], 'relations' => []]);
});

it('reads which model each guard signs users in as, and which is the default', function () {
    mkdir("{$this->dir}/config");
    file_put_contents("{$this->dir}/config/auth.php", <<<'PHP'
        <?php

        use App\Models\Member;

        return [
            'defaults' => ['guard' => env('AUTH_GUARD', 'member'), 'passwords' => 'users'],
            'guards' => [
                'web' => ['driver' => 'session', 'provider' => 'users'],
                'member' => ['driver' => 'session', 'provider' => 'members'],
                // 'admin' => ['driver' => 'session', 'provider' => 'admins'],
            ],
            'providers' => [
                'users' => ['driver' => 'eloquent', 'model' => env('AUTH_MODEL', App\Models\User::class)],
                'members' => ['driver' => 'custom', 'model' => Member::class],
                /* 'admins' => ['driver' => 'eloquent', 'model' => App\Models\Admin::class], */
            ],
        ];
        PHP);

    expect(code_index_auth_models($this->dir))->toBe([
        'default' => ['App\Models\Member'],
        'all' => ['App\Models\User', 'App\Models\Member'],
    ]);
});

it('takes App\Models\User where the application has no auth configuration, and nothing where it has neither', function () {
    expect(code_index_auth_models($this->dir))->toBe(['default' => [], 'all' => []]);

    mkdir("{$this->dir}/app/Models", 0777, true);
    file_put_contents("{$this->dir}/app/Models/User.php", "<?php\n\nnamespace App\\Models;\n\nclass User\n{\n}\n");

    expect(code_index_auth_models($this->dir))->toBe(['default' => ['App\Models\User'], 'all' => ['App\Models\User']]);
});
