<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FindingVerdictKind;
use App\Enums\ReviewRuleKind;
use App\Models\FindingVerdict;
use App\Models\Repository;
use App\Models\ReviewRule;
use Illuminate\Support\Str;

/**
 * A repository's review rules as files for the repository itself, in the shared
 * format of Laravel Boost's project rules: one Markdown file per rule under
 * .ai/rules, with the globs it applies to as `paths` in its frontmatter. In the
 * repository they are read by every agent tool and reviewed like code, and a
 * rule in its own file changes without a merge conflict.
 */
class RuleFiles
{
    /** Where the files go in the repository. */
    public const DIRECTORY = '.ai/rules';

    /** How many proposals from dismissed findings one answer holds at most. */
    public const PROPOSALS = 20;

    /**
     * The rules Review Central keeps for the repository, as files.
     *
     * @return array<int, array{path: string, rule_id: int, scope: string, content: string}>
     */
    public function rules(Repository $repository): array
    {
        $taken = [];

        return ReviewRules::for($repository)
            ->map(fn (ReviewRule $rule): array => [
                'path' => $this->path($rule->title, $taken),
                'rule_id' => (int) $rule->getKey(),
                'scope' => $rule->repository_id === null ? 'every repository of the tenant' : 'this repository',
                'content' => $this->content($rule->paths ?: [], $rule->title, $rule->kind, $rule->body, $rule->kind === ReviewRuleKind::Check ? $rule->severity : null),
            ])
            ->values()
            ->all();
    }

    /**
     * Rules the team's own verdicts suggest: findings someone judged not to be a
     * problem, or wrong, with a reason, that no rule covers yet. Each is a
     * proposal for a person to word and agree to, never a rule by itself.
     *
     * @return array<int, array{path: string, from: string, content: string}>
     */
    public function proposals(Repository $repository): array
    {
        $covered = ReviewRule::query()->where('repository_id', $repository->getKey())->whereNotNull('source')->pluck('source')->all();
        $taken = array_map(fn (array $file): string => $file['path'], $this->rules($repository));

        return FindingVerdict::query()
            ->with(['run', 'user'])
            ->whereIn('verdict', [FindingVerdictKind::NotAProblem, FindingVerdictKind::Wrong])
            ->whereNotNull('reason')
            ->whereHas('run', fn ($query) => $query->where('repository_id', $repository->getKey()))
            ->latest('id')
            ->get()
            ->map(function (FindingVerdict $verdict) use ($covered): ?array {
                $finding = collect($verdict->run->findings ?? [])->first(fn (array $finding): bool => (string) ($finding['id'] ?? '') === $verdict->finding_id);
                $source = "{$verdict->run->uuid}:{$verdict->finding_id}";

                return $finding === null || in_array($source, $covered, true) ? null : ['finding' => $finding, 'verdict' => $verdict, 'source' => $source];
            })
            ->filter()
            ->unique(fn (array $item): string => ($item['finding']['path'] ?? '').'|'.($item['finding']['title'] ?? ''))
            ->take(self::PROPOSALS)
            ->map(function (array $item) use (&$taken): array {
                $finding = $item['finding'];
                $title = 'Not a defect: '.Str::limit((string) ($finding['title'] ?? 'a finding'), 120, '');
                $body = 'A review reported "'.($finding['title'] ?? '').'" at `'.($finding['path'] ?? '?').(isset($finding['line']) ? ':'.$finding['line'] : '').'`, and the team judged it: '.$item['verdict']->explanation().'. Word why this is not a defect here, and widen the path to where the same holds.';

                return [
                    'path' => $this->path($title, $taken),
                    'from' => $item['source'],
                    'content' => $this->content(filled($finding['path'] ?? null) ? [(string) $finding['path']] : [], $title, ReviewRuleKind::Convention, $body, null),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $paths
     */
    public function content(array $paths, string $title, ReviewRuleKind $kind, ?string $body, ?string $severity): string
    {
        $paths = $paths === [] ? ['**'] : $paths;
        $frontmatter = "---\npaths:\n".implode('', array_map(fn (string $path): string => '  - '.json_encode($path, JSON_UNESCAPED_SLASHES)."\n", $paths))."---\n";
        $meaning = $kind === ReviewRuleKind::Check
            ? 'A change that breaks this is a defect'.($severity === null ? '.' : ', of severity '.strtolower($severity).'.')
            : 'Not a defect here: a review does not report this, and code may rely on it.';

        return $frontmatter."\n# ".trim((string) preg_replace('/\s+/', ' ', $title))."\n\n{$meaning}".(filled($body) ? "\n\n".trim((string) $body) : '')."\n";
    }

    /**
     * A file name of its own for the title, among those already given.
     *
     * @param  array<int, string>  $taken
     */
    protected function path(string $title, array &$taken): string
    {
        $slug = Str::limit(Str::slug($title), 60, '') ?: 'rule';
        $path = self::DIRECTORY."/{$slug}.md";

        for ($number = 2; in_array($path, $taken, true); $number++) {
            $path = self::DIRECTORY."/{$slug}-{$number}.md";
        }

        $taken[] = $path;

        return $path;
    }
}
