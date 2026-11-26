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
        }
        PHP);

    expect(code_index_models($this->dir)['relations'])->toEqual([
        'app\models\course' => [
            'participants' => ['relation' => 'Illuminate\Database\Eloquent\Relations\BelongsToMany', 'related' => 'App\Models\People\Member', 'many' => true],
            'lessons' => ['relation' => 'Illuminate\Database\Eloquent\Relations\HasMany', 'related' => 'App\Models\Lesson', 'many' => true],
            'parent' => ['relation' => 'Illuminate\Database\Eloquent\Relations\BelongsTo', 'related' => 'App\Models\Course', 'many' => false],
        ],
        'app\models\concerns\belongstoclub' => [
            'club' => ['relation' => 'Illuminate\Database\Eloquent\Relations\BelongsTo', 'related' => 'App\Models\Club', 'many' => false],
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

    expect(code_index_models($this->dir)['scopes'])->toEqualCanonicalizing(['currentclub', 'published']);
});

it('reads nothing where there is no app directory', function () {
    expect(code_index_models("{$this->dir}/missing"))->toBe(['scopes' => [], 'relations' => []]);
});
