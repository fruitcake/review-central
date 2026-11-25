<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FindingVerdictKind;
use App\Models\FindingVerdict;
use App\Models\Repository;
use App\Models\ReviewRule;

/**
 * Rules the team's own verdicts suggest: findings someone judged not to be a
 * problem, or wrong, with a reason, that no rule covers yet. Each is a proposal
 * for a person to word and agree to, never a rule by itself.
 */
class RuleProposals
{
    /** How many one answer holds at most. */
    public const LIMIT = 20;

    /**
     * @return array<int, array{from: string, finding: string, path: string|null, line: int|null, judged: string}>
     */
    public function for(Repository $repository): array
    {
        // A rule for every repository of the tenant covers its finding here too.
        $covered = ReviewRule::query()->for($repository)->whereNotNull('source')->pluck('source')->all();

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

                return $finding === null || in_array($source, $covered, true) ? null : [
                    'from' => $source,
                    'finding' => (string) ($finding['title'] ?? ''),
                    'path' => filled($finding['path'] ?? null) ? (string) $finding['path'] : null,
                    'line' => isset($finding['line']) ? (int) $finding['line'] : null,
                    'judged' => $verdict->explanation(),
                ];
            })
            ->filter()
            ->unique(fn (array $item): string => ($item['path'] ?? '').'|'.$item['finding'])
            ->take(self::LIMIT)
            ->values()
            ->all();
    }
}
