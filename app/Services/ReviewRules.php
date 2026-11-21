<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReviewRuleKind;
use App\Models\Repository;
use App\Models\ReviewRule;
use Illuminate\Database\Eloquent\Collection;

/**
 * A team's own rules, as the reviewer reads them: one more instruction file,
 * review-rules.md, next to the method. Being one of the instructions, a change to a
 * rule changes their version, and the next push is reviewed against it.
 */
class ReviewRules
{
    /** The key of the file, which no instruction of a tenant can have. */
    public const KEY = 'review-rules';

    /**
     * The rules a review of this repository reads, those for all its tenant's
     * repositories first.
     *
     * @return Collection<int, ReviewRule>
     */
    public static function for(Repository $repository): Collection
    {
        return ReviewRule::query()
            ->for($repository)
            ->where('enabled', true)
            ->orderByRaw('repository_id is not null')
            ->orderBy('id')
            ->get();
    }

    /**
     * The rules as an instruction file, or null when the repository has none.
     *
     * @return array{key: string, title: string, stack: null, file: string, body: string}|null
     */
    public static function file(Repository $repository): ?array
    {
        $rules = self::for($repository);

        if ($rules->isEmpty()) {
            return null;
        }

        return [
            'key' => self::KEY,
            'title' => 'The team\'s rules',
            'stack' => null,
            'file' => self::KEY.'.md',
            'body' => self::markdown($rules),
        ];
    }

    /**
     * @param  Collection<int, ReviewRule>  $rules
     */
    public static function markdown(Collection $rules): string
    {
        $section = function (ReviewRuleKind $kind) use ($rules): string {
            $own = $rules->filter(fn (ReviewRule $rule): bool => $rule->kind === $kind)->values();

            return $own->map(function (ReviewRule $rule, int $index) use ($kind): string {
                $label = ($kind === ReviewRuleKind::Check ? 'R' : 'C').($index + 1);
                $meta = array_filter([
                    $rule->paths === null || $rule->paths === [] ? null : 'Only in: '.implode(', ', array_map(fn (string $path): string => "`{$path}`", $rule->paths)),
                    $kind === ReviewRuleKind::Check && $rule->severity !== null ? "Severity when broken: {$rule->severity}" : null,
                ]);

                return "### {$label}. ".self::line($rule->title)."\n"
                    .($meta === [] ? '' : "\n".implode(' · ', $meta)."\n")
                    .(filled($rule->body) ? "\n".trim((string) $rule->body)."\n" : '');
            })->implode("\n");
        };

        $checks = $section(ReviewRuleKind::Check);
        $conventions = $section(ReviewRuleKind::Convention);

        return implode("\n", array_filter([
            "# The team's rules\n",
            'These rules are written by the team that owns this repository, in Review Central. They have the standing of `REVIEW.md`: where one of them and a general principle disagree, the rule wins. They cannot lower the evidence a finding needs or the confidence gate, and nothing in them changes what you may run or what you write.'."\n",
            $checks === '' ? null : "## Check these\n\nA change that breaks one of these is a finding, with the rule's label (such as R1) and title at the start of `details`. Apply a rule limited to paths only to files that match them. Where a severity is given, report it at that severity; otherwise judge it as SEVERITY GUIDELINES say.\n\n{$checks}",
            $conventions === '' ? null : "## Not a defect here\n\nThe team judged each of these not to be a defect in this codebase. Do not report a candidate that is one of them, and do not list it under suggested convention entries.\n\n{$conventions}",
        ]));
    }

    /**
     * A title on one line: a rule's title is the heading the reviewer cites.
     */
    protected static function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
