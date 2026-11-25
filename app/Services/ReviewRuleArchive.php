<?php

declare(strict_types=1);

namespace App\Services;

use App\Connections\LlmDriver;
use App\Llm\Agents\RuleMatcher;
use App\Models\Repository;
use App\Models\ReviewRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Turns off the team's rules a repository now holds in rule files of its own, on
 * its main branch: .ai/rules and the other folders coding agents read. There the
 * review reads them as REPOSITORY RULES, so it would otherwise read them twice. A
 * small model matches them by what they say, so a file needs no id of a rule.
 *
 * Only a rule for the one repository: one for every repository of the tenant
 * still holds for the others. An archived rule stays, turned off, with the file it
 * was found in, and turning it on again keeps it on.
 */
class ReviewRuleArchive
{
    /** Where coding agents read a repository's rule files, as the review does. */
    public const DIRECTORIES = ['.ai/rules', '.claude/rules', '.github/instructions', '.cursor/rules'];

    /** How many rule files are read at most. */
    public const FILES = 80;

    /** How much of one rule file the model reads. */
    public const FILE_LENGTH = 12000;

    /** How much of the rule files the model reads in all. */
    public const BUDGET = 150000;

    /** How many rules one request asks about. */
    public const PER_REQUEST = 30;

    /**
     * @param  bool  $always  also when nothing changed since the last check
     * @return array{archived: array<int, array{id: int, title: string, file: string, note: string}>, checked: int, note: string}
     */
    public function check(Repository $repository, bool $always = false): array
    {
        $rules = ReviewRule::query()
            ->where('repository_id', $repository->getKey())
            ->where('enabled', true)
            // One a person turned on again after it was found is theirs to keep.
            ->whereNull('archived_to')
            ->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return self::outcome('No rule of this repository alone is on, so there is nothing to check.');
        }

        $connection = LlmConnections::small($repository->tenant);
        $driver = $connection?->driver();

        if ($connection === null || ! $driver instanceof LlmDriver) {
            return self::outcome('The team has no connection to a model for small checks.');
        }

        $files = $this->files($repository);

        if ($files === null) {
            return self::outcome('The rule files on the main branch could not be read.');
        }

        $fingerprint = sha1(json_encode([$files, $rules->map(fn (ReviewRule $rule): array => [$rule->getKey(), (string) $rule->updated_at])->all()]) ?: '');
        $key = "review-rules-check:{$repository->getKey()}";

        if (! $always && Cache::get($key) === $fingerprint) {
            return self::outcome('Neither the rule files on the main branch nor the rules changed since the last check.');
        }

        if ($files === []) {
            Cache::forever($key, $fingerprint);

            return self::outcome('The main branch has no rule files.', $rules->count());
        }

        $archived = [];

        foreach ($rules->chunk(self::PER_REQUEST) as $chunk) {
            [$material, $shown] = self::material($files, $chunk);
            $response = $driver->matchesRules($connection, $material);
            // Only a file the model read can hold a rule.
            $verdicts = RuleMatcher::read($response, $chunk->map(fn (ReviewRule $rule): string => (string) $rule->getKey())->all(), $shown);

            foreach ($chunk as $rule) {
                $verdict = $verdicts[(string) $rule->getKey()] ?? null;

                if ($verdict === null || $verdict['verdict'] !== 'in_repository' || $verdict['file'] === null) {
                    continue;
                }

                // Unless someone changed the rule while it was checked.
                $updated = ReviewRule::query()
                    ->whereKey($rule->getKey())
                    ->where('enabled', true)
                    ->whereNull('archived_to')
                    ->where('updated_at', $rule->updated_at)
                    ->update(['enabled' => false, 'archived_at' => now(), 'archived_to' => $verdict['file'], 'archived_note' => $verdict['note'] ?: null, 'updated_at' => now()]);

                if ($updated === 1) {
                    $archived[] = ['id' => (int) $rule->getKey(), 'title' => $rule->title, 'file' => $verdict['file'], 'note' => $verdict['note']];
                }
            }
        }

        // The rules left on, against these files: the same again next time is no news.
        $left = $rules->reject(fn (ReviewRule $rule): bool => in_array($rule->getKey(), array_column($archived, 'id'), true));
        Cache::forever($key, sha1(json_encode([$files, $left->map(fn (ReviewRule $rule): array => [$rule->getKey(), (string) $rule->fresh()?->updated_at])->values()->all()]) ?: ''));

        return [
            'archived' => $archived,
            'checked' => $rules->count(),
            'note' => $archived === [] ? 'None of the rules is in a rule file on the main branch yet.' : count($archived).' rule(s) are in a rule file on the main branch now, and turned off here.',
        ];
    }

    /**
     * The rule files on the main branch, by path, with their text; null when they
     * could not be listed. One that cannot be read is left out.
     *
     * @return array<string, string>|null
     */
    protected function files(Repository $repository): ?array
    {
        try {
            $source = $repository->sourceControl();
            $paths = $source->filesOnMainBranch(self::DIRECTORIES);

            if ($paths === null) {
                return null;
            }

            $files = [];

            foreach (array_slice(array_values(array_filter($paths, self::isRuleFile(...))), 0, self::FILES) as $path) {
                $text = $source->fileOnMainBranch($path);

                if (is_string($text)) {
                    $files[$path] = $text;
                }
            }

            ksort($files);

            return $files;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Whether an agent tool reads the file as a rule: Markdown, Cursor's .mdc, and
     * not Boost's index of them.
     */
    public static function isRuleFile(string $path): bool
    {
        return (str_ends_with($path, '.md') || str_ends_with($path, '.mdc')) && basename($path) !== 'index.md';
    }

    /**
     * What the model reads: the files, each whole as far as its share goes, and the rules.
     *
     * @param  array<string, string>  $files
     * @param  Collection<int, ReviewRule>  $rules
     * @return array{0: string, 1: array<int, string>} the text, and the paths of the files it shows
     */
    protected static function material(array $files, Collection $rules): array
    {
        $text = "# The rule files on the main branch\n";
        $used = 0;
        $shown = [];

        foreach ($files as $path => $content) {
            $content = mb_strcut($content, 0, self::FILE_LENGTH);

            if ($used + strlen($content) > self::BUDGET) {
                $text .= "\n## {$path}\n\n(not shown: over the length this check reads)\n";

                continue;
            }

            $used += strlen($content);
            $shown[] = $path;
            $text .= "\n## {$path}\n\n".trim($content)."\n";
        }

        $text .= "\n# The rules from the team's list\n";

        foreach ($rules as $rule) {
            $text .= "\n## {$rule->getKey()} · {$rule->kind->value} · ".trim((string) preg_replace('/\s+/', ' ', $rule->title))."\n\n"
                .'Paths: '.($rule->paths ? implode(', ', $rule->paths) : 'every file')."\n"
                .(filled($rule->body) ? "\n".trim((string) $rule->body)."\n" : '');
        }

        return [$text, $shown];
    }

    /**
     * @return array{archived: array<int, array{id: int, title: string, file: string, note: string}>, checked: int, note: string}
     */
    protected static function outcome(string $note, int $checked = 0): array
    {
        return ['archived' => [], 'checked' => $checked, 'note' => $note];
    }
}
