<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ReviewInstruction;
use App\Models\ReviewInstructionVersion;
use App\Models\Tenant;
use App\Support\LineDiff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The AI review's instructions as the server keeps them: one file of guidance for
 * the review of a pull request, and one guide per stack. The review skill keeps the
 * flow and the report format, which have to match its own scripts and are nobody's
 * to edit; these are what the review looks for and how it judges it. A tenant
 * that wants it otherwise keeps its own copy of a file, which replaces the default.
 *
 * The skill ships a copy of them as well. A run whose server has none, and a
 * review run on a developer's machine, read that copy instead.
 */
class ReviewInstructions
{
    /**
     * The contract of the review scripts these are written against. The
     * method names that skill's sections and report fields, so a skill that asks for
     * another contract gets nothing and reads its own copy.
     */
    public const CONTRACT = '1';

    /**
     * Where the instructions start from: the copy installed with the review skill,
     * which ships with this application.
     */
    public const BUNDLED_DIRECTORY = 'review/skill/references';

    /**
     * What a key may be. The run writes `<key>.md` and refuses the whole set
     * when one name does not match this, so the admin and the import hold keys to it.
     */
    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';

    /**
     * The file the skill cannot review without. A set that lacks it is not served:
     * the run would refuse it and read its own copy anyway.
     */
    public const METHOD = 'method';

    /**
     * The structure of a review of a pull request: its flow and the report it
     * writes. It ships with the review's scripts, which read that report, so a
     * copy here replaces it for every tenant and is the platform's alone to keep.
     */
    public const STRUCTURE = 'skill';

    /**
     * The whole prompt of a security review of a branch, on the same terms.
     */
    public const SECURITY = 'security';

    /**
     * The files that say how a review works rather than what it looks for, with
     * the file each ships as. Only this installation's administrator edits them:
     * a tenant's own row of such a key is not read.
     */
    public const BASE = [
        self::STRUCTURE => 'review/skill/SKILL.md',
        self::SECURITY => 'review/skill/security-prompt.md',
    ];

    /**
     * The files a review on these stacks reads, in order. Null when there are none,
     * or none with the method, which leaves the review to the copy that ships with
     * the skill.
     *
     * @param  array<int, string>  $stacks
     * @return array<int, array{key: string, title: string, stack: string|null, file: string, body: string}>|null
     */
    public static function files(Tenant $tenant, array $stacks): ?array
    {
        $files = ReviewInstruction::query()
            ->where(fn (Builder $query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenant->getKey()))
            // The defaults first, so a tenant's own file of the same key replaces it.
            ->orderByRaw('tenant_id is not null')
            ->get()
            // The structure is the platform's: a tenant's row of it is not one.
            ->reject(fn (ReviewInstruction $instruction): bool => array_key_exists($instruction->key, self::BASE) && $instruction->tenant_id !== null)
            // And the security review's prompt is no part of a pull request's review.
            ->reject(fn (ReviewInstruction $instruction): bool => $instruction->key === self::SECURITY)
            ->keyBy('key')
            ->sortBy([['position', 'asc'], ['key', 'asc']])
            ->filter(fn (ReviewInstruction $instruction): bool => $instruction->stack === null || in_array($instruction->stack, $stacks, true))
            ->map(fn (ReviewInstruction $instruction): array => [
                'key' => $instruction->key,
                'title' => $instruction->title,
                'stack' => $instruction->stack,
                'file' => "{$instruction->key}.md",
                'body' => (string) $instruction->body,
            ])
            ->filter(fn (array $file): bool => trim($file['body']) !== '')
            ->values()
            ->all();

        return in_array(self::METHOD, array_column($files, 'key'), true) ? $files : null;
    }

    /**
     * The platform's own text for a file of the structure, or null where it is
     * the one that ships.
     */
    public static function base(string $key): ?string
    {
        $body = ReviewInstruction::query()->whereNull('tenant_id')->where('key', $key)->value('body');

        return is_string($body) && trim($body) !== '' ? $body : null;
    }

    /**
     * What this release ships for a key, to read beside what is kept here: the
     * structure's file, the guidance or a stack's guide. Null for a file of the
     * platform's or a tenant's own making.
     */
    public static function shipped(string $key): ?string
    {
        $path = resource_path(self::BASE[$key] ?? self::BUNDLED_DIRECTORY."/{$key}.md");

        return preg_match(self::KEY_PATTERN, $key) === 1 && is_file($path) ? (string) File::get($path) : null;
    }

    /**
     * Starts the platform's own copy of a file of the structure from the one
     * that ships, for an administrator to edit.
     */
    public static function customiseBase(string $key): ReviewInstruction
    {
        return ReviewInstruction::query()->whereNull('tenant_id')->where('key', $key)->first()
            ?? ReviewInstruction::query()->create([
                'key' => $key,
                'title' => $key === self::SECURITY ? 'Security review' : 'Structure of a pull request review',
                'stack' => null,
                // Before the guidance: it is what sends the review there.
                'position' => 5,
                'body' => (string) self::shipped($key),
                'shipped_hash' => hash('sha256', (string) self::shipped($key)),
            ]);
    }

    /**
     * The hash that marks a text as this release's, untouched: only for the text
     * the release ships. A directory of one's own, imported, is one's own words.
     */
    protected static function shippedHash(string $key, string $body): ?string
    {
        return self::shipped($key) === $body ? hash('sha256', $body) : null;
    }

    /**
     * What a release ships that differs from what is kept here: a bundled file
     * that is new, one whose shipped text changed, and the platform's own copy of
     * the structure where the shipped one moved on since it was made.
     *
     * Each says whether someone here edited it since: an edit is never offered as
     * a plain replacement, since importing it would undo it.
     *
     * @return list<array{key: string, title: string, state: 'new'|'outdated'|'edited', diff: list<array{type: string, text: string}>}>
     */
    public static function updates(): array
    {
        $updates = [];
        $keys = array_map(fn (string $path): string => basename($path, '.md'), File::glob(resource_path(self::BUNDLED_DIRECTORY).'/*.md'));

        foreach ([...$keys, ...array_keys(self::BASE)] as $key) {
            $shipped = self::shipped($key);
            $kept = ReviewInstruction::query()->whereNull('tenant_id')->where('key', $key)->first();

            // The structure is read from the release itself until someone copies it.
            if ($shipped === null || ($kept === null && array_key_exists($key, self::BASE)) || $kept?->body === $shipped) {
                continue;
            }

            $updates[] = [
                'key' => $key,
                'title' => $kept->title ?? self::title($shipped) ?? Str::headline($key),
                // Kept as imported means the text here is an older release's, and safe to replace.
                'state' => match (true) {
                    $kept === null => 'new',
                    $kept->shipped_hash !== null && hash_equals($kept->shipped_hash, hash('sha256', (string) $kept->body)) => 'outdated',
                    default => 'edited',
                },
                'diff' => LineDiff::lines((string) $kept?->body, $shipped),
            ];
        }

        return $updates;
    }

    /**
     * Takes the shipped text for the keys asked for, and nothing else.
     *
     * @param  list<string>  $keys
     * @return list<string> the keys that changed
     */
    public static function applyUpdates(array $keys): array
    {
        $done = [];

        foreach (self::updates() as $update) {
            if (! in_array($update['key'], $keys, true)) {
                continue;
            }

            if (array_key_exists($update['key'], self::BASE)) {
                $shipped = (string) self::shipped($update['key']);
                ReviewInstruction::query()->whereNull('tenant_id')->where('key', $update['key'])->update(['body' => $shipped, 'shipped_hash' => hash('sha256', $shipped)]);
            } else {
                self::import(resource_path(self::BUNDLED_DIRECTORY), overwrite: true, only: [$update['key']]);
            }

            $done[] = $update['key'];
        }

        return $done;
    }

    /**
     * Keeps the files a run was given, so the run can be read back against them.
     * Looked up first: most runs read a version an earlier run already stored.
     *
     * @param  array<int, array{key: string, title: string, stack: string|null, file: string, body: string}>  $files
     */
    public static function store(array $files): ReviewInstructionVersion
    {
        $version = self::version($files);

        // Two runs can store a new version at the same moment; the unique key settles it.
        return ReviewInstructionVersion::query()->where('version', $version)->first()
            ?? ReviewInstructionVersion::query()->createOrFirst(['version' => $version], ['files' => $files]);
    }

    /**
     * The hash of the files as served: what a run read, to tell two runs that read
     * the same from two that did not.
     *
     * @param  array<int, array<string, mixed>>  $files
     */
    public static function version(array $files): string
    {
        return substr(hash('sha256', json_encode($files, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * The files as a single markdown document, for an agent that reads them whole
     * rather than as the files the run writes.
     *
     * @param  array<int, array{key: string, title: string, stack: string|null, file: string, body: string}>  $files
     */
    public static function markdown(array $files): string
    {
        $parts = ['# AI review instructions'."\n\nVersion ".self::version($files).'. The run writes these files to `.review-instructions/` and the review reads them in this order.'];

        foreach ($files as $file) {
            $parts[] = "---\n\n`{$file['file']}`: {$file['title']}\n\n".trim($file['body']);
        }

        return implode("\n\n", $parts)."\n";
    }

    /**
     * The defaults a tenant has not made its own copy of.
     *
     * @return Collection<int, ReviewInstruction>
     */
    public static function defaultsWithoutCopy(Tenant $tenant): Collection
    {
        // Without the panel's tenant scope, which would hide every default.
        return ReviewInstruction::query()
            ->withoutGlobalScopes()
            ->whereNull('tenant_id')
            // Not the structure: that is the platform's alone.
            ->whereNotIn('key', array_keys(self::BASE))
            ->whereNotIn('key', $tenant->reviewInstructions()->withoutGlobalScopes()->select('key'))
            ->orderBy('position')
            ->orderBy('key')
            ->get();
    }

    /**
     * Copies a default into a tenant's own instructions, as it is published, so
     * the tenant's reviews read the same text until someone edits the copy.
     */
    public static function copyDefault(Tenant $tenant, string $key): ReviewInstruction
    {
        $default = ReviewInstruction::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('key', $key)->firstOrFail();

        return $tenant->reviewInstructions()->firstOrCreate(['key' => $key], [
            'title' => $default->title,
            'stack' => $default->stack,
            'position' => $default->position,
            'body' => $default->body,
        ]);
    }

    /**
     * Reads a directory of instruction files, as the skill ships them, into the server:
     * `method.md` and `stack-<name>.md`. An instruction that is already here is left
     * alone unless `$overwrite` is set; then it gets the file's text. A tenant's own
     * copy is never touched. A file whose name the run would refuse, such as a
     * README.md, is skipped. With `$only`, only those keys are read.
     *
     * @param  list<string>|null  $only
     * @return array{created: array<int, string>, replaced: array<int, string>, kept: array<int, string>, skipped: array<int, string>}
     */
    public static function import(string $directory, bool $overwrite = false, ?array $only = null): array
    {
        $result = ['created' => [], 'replaced' => [], 'kept' => [], 'skipped' => []];

        foreach (File::glob(rtrim($directory, '/').'/*.md') as $path) {
            $key = basename($path, '.md');

            if (! preg_match(self::KEY_PATTERN, $key)) {
                $result['skipped'][] = $key;

                continue;
            }

            if ($only !== null && ! in_array($key, $only, true)) {
                continue;
            }

            $body = File::get($path);
            $existing = ReviewInstruction::query()->whereNull('tenant_id')->where('key', $key)->first();

            if ($existing === null) {
                $stack = Str::startsWith($key, 'stack-') ? Str::after($key, 'stack-') : null;

                ReviewInstruction::query()->create([
                    'key' => $key,
                    'title' => self::title($body) ?? Str::headline($key),
                    'stack' => $stack,
                    // The review's guidance first, then the guides.
                    'position' => match (true) {
                        $key === self::METHOD => 10,
                        $stack !== null => 30,
                        default => 20,
                    },
                    'body' => $body,
                    'shipped_hash' => self::shippedHash($key, $body),
                ]);
                $result['created'][] = $key;
            } elseif ($overwrite && $existing->body !== $body) {
                $existing->body = $body;
                $existing->shipped_hash = self::shippedHash($key, $body);
                $existing->save();
                $result['replaced'][] = $key;
            } else {
                $result['kept'][] = $key;
            }
        }

        return $result;
    }

    /**
     * Writes the instructions to a directory, one file each, for the copy
     * the next release of the skill ships with.
     *
     * @return array<int, string> the files written
     */
    public static function export(string $directory): array
    {
        File::ensureDirectoryExists($directory);

        return ReviewInstruction::query()
            ->whereNull('tenant_id')
            // Not the platform's own copy of how a review works: that ships as
            // the scripts' own file, not among the guidance.
            ->whereNotIn('key', array_keys(self::BASE))
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->orderBy('position')
            ->orderBy('key')
            ->get()
            ->map(function (ReviewInstruction $instruction) use ($directory): string {
                $path = rtrim($directory, '/')."/{$instruction->key}.md";

                if (File::put($path, (string) $instruction->body) === false) {
                    throw new RuntimeException("Could not write {$path}.");
                }

                return $path;
            })
            ->all();
    }

    /**
     * The first heading of a markdown file, after any frontmatter.
     */
    protected static function title(string $markdown): ?string
    {
        $markdown = preg_replace('/\A---\R.*?\R---\R/s', '', $markdown) ?? $markdown;

        return preg_match('/^#\s+(.+)$/m', $markdown, $matches) ? trim($matches[1]) : null;
    }
}
