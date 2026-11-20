<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ReviewRun;

/**
 * Holds a follow-up review to the rules for follow-up rounds that its own text
 * gives it, where the model does not keep to them itself. A review that keeps
 * finding something new never ends, least of all when a coding agent answers
 * every finding with another push.
 *
 * - Once the review says it is not converging, only a new HIGH or CRITICAL is
 *   kept; the rest go under Open questions, a line each, for a person.
 * - From the third follow-up on, a new finding below HIGH no longer holds the
 *   merge. It is still reported, and stays that way in the rounds after.
 * - After the team's most rounds, five unless set, nothing new is recorded at
 *   all: the open findings are still checked, and anything new goes under Open
 *   questions for a person.
 *
 * Trials are left alone: they are no round of the pull request's review.
 */
class ReviewFollowUps
{
    /** The follow-up from which a new finding below HIGH no longer holds the merge. */
    public const ADVISORY_FROM = 3;

    /** The follow-up rounds that may add findings, unless the team sets otherwise. */
    public const MAX_ROUNDS = 5;

    /** How the review says it is not converging, in English and Dutch. */
    public const NOT_CONVERGING = '/\bnot\s+converging\b|\bniet\s+(meer\s+)?(convergeert|convergerend)\b/i';

    /**
     * @param  array<int, array<string, mixed>>  $findings  as normalised for filing
     * @return array{findings: array<int, array<string, mixed>>, summary: string|null}
     */
    public function apply(ReviewRun $run, array $findings, ?string $summary): array
    {
        if ($run->custom || $run->pull_request_id === null) {
            return ['findings' => $findings, 'summary' => $summary];
        }

        $earlier = ReviewRun::query()
            ->where('repository_id', $run->repository_id)
            ->where('pull_request_id', $run->pull_request_id)
            ->where('custom', false)
            ->whereIn('status', [ReviewRun::STATUS_FINISHED, ReviewRun::STATUS_UNPUBLISHED])
            ->whereKeyNot($run->getKey())
            ->where('started_at', '<=', $run->started_at ?? now())
            ->latest('started_at')
            ->get(['id', 'findings']);

        // A first review has nothing earlier: the rules are for the rounds after.
        if ($earlier->isEmpty()) {
            return ['findings' => $findings, 'summary' => $summary];
        }

        // What a round before left as not holding the merge, by finding id.
        $advisory = collect($earlier->first()->findings ?? [])
            ->filter(fn (array $finding): bool => ($finding['advisory'] ?? false) === true)
            ->pluck('id')
            ->all();

        $notConverging = is_string($summary) && preg_match(self::NOT_CONVERGING, $summary) === 1;
        $max = ReviewSettings::maxRounds($run->repository);
        $over = $earlier->count() > $max;
        $late = $earlier->count() >= self::ADVISORY_FROM;
        $kept = [];
        $set = [];

        foreach ($findings as $finding) {
            $high = in_array(strtoupper((string) ($finding['severity'] ?? '')), ReviewPublisher::HIGH_SEVERITIES, true);
            $new = ($finding['status'] ?? 'new') === 'new';

            if ($new && ($over || (! $high && $notConverging))) {
                $set[] = '- '.($finding['title'] ?? $finding['id'] ?? 'A finding')
                    .(filled($finding['path'] ?? null) ? " (`{$finding['path']}".(filled($finding['line'] ?? null) ? ":{$finding['line']}" : '').'`)' : '')
                    .': '.strtolower((string) ($finding['severity'] ?? '')).', set aside because '
                    .($over ? "this pull request has had its {$max} follow-up rounds" : 'the review is not converging').'.';

                continue;
            }

            if (! $high && (($new && $late) || in_array($finding['id'] ?? null, $advisory, true))) {
                $finding['advisory'] = true;
            }

            $kept[] = $finding;
        }

        if ($set !== []) {
            $summary = self::withOpenQuestions((string) $summary, $set);
        }

        return ['findings' => $kept, 'summary' => $summary];
    }

    /**
     * The summary with lines added under its Open questions, which it gets when it
     * has none.
     *
     * @param  array<int, string>  $lines
     */
    protected static function withOpenQuestions(string $summary, array $lines): string
    {
        $block = implode("\n", $lines);

        if (preg_match('/^### Open questions[ \t]*$/mi', $summary, $match, PREG_OFFSET_CAPTURE) === 1) {
            $at = $match[0][1] + strlen($match[0][0]);
            $rest = ltrim(substr($summary, $at));

            return substr($summary, 0, $at)."\n\n".$block.($rest === '' ? '' : "\n".$rest);
        }

        return rtrim($summary)."\n\n### Open questions\n\n".$block;
    }
}
