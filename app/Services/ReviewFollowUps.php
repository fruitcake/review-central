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
    public const NOT_CONVERGING = '/\bnot\s+converging\b|\bniet\s+(meer\s+)?(convergeert|convergerend)\b|\bconvergeert\s+(nog\s+)?niet\b/i';

    /**
     * @param  array<int, array<string, mixed>>  $findings  as normalised for filing
     * @return array{findings: array<int, array<string, mixed>>, summary: string|null}
     */
    public function apply(ReviewRun $run, array $findings, ?string $summary): array
    {
        // A connection that keeps its reviews here has no thread of findings for a
        // review to follow up on: each reads the change afresh, and nothing it says
        // reaches an agent that pushes again.
        if ($run->custom || $run->pull_request_id === null || ! rescue(fn (): bool => $run->repository->sourceControl()->publishes(), true, false)) {
            return ['findings' => $findings, 'summary' => $summary];
        }

        $earlier = ReviewRun::query()
            ->where('repository_id', $run->repository_id)
            ->where('pull_request_id', $run->pull_request_id)
            ->where('custom', false)
            // Only what was the pull request's answer: a run that published nothing
            // left no thread of findings to follow up on.
            ->where('status', ReviewRun::STATUS_FINISHED)
            ->whereKeyNot($run->getKey())
            ->where('started_at', '<=', $run->started_at ?? now())
            ->latest('started_at')
            ->get(['id', 'findings']);

        // A first review has nothing earlier: the rules are for the rounds after.
        if ($earlier->isEmpty()) {
            return ['findings' => $findings, 'summary' => $summary];
        }

        // Every finding id reported before, with whether its latest report left it
        // as not holding the merge: one left out for a round keeps what it was.
        $known = [];

        foreach ($earlier as $previous) {
            foreach (array_filter($previous->findings ?? [], is_array(...)) as $finding) {
                $id = (string) ($finding['id'] ?? '');

                if ($id !== '' && ! array_key_exists($id, $known)) {
                    $known[$id] = ($finding['advisory'] ?? false) === true;
                }
            }
        }

        // Said in the opening line, as the rule asks, not quoted further down.
        $notConverging = is_string($summary) && preg_match(self::NOT_CONVERGING, (string) (array_values(array_filter(preg_split('/\R/', $summary) ?: [], fn (string $line): bool => trim($line) !== ''))[0] ?? '')) === 1;
        $max = ReviewSettings::maxRounds($run->repository);
        $over = $earlier->count() > $max;
        $late = $earlier->count() >= self::ADVISORY_FROM;
        $kept = [];
        $set = [];
        $changed = false;

        foreach ($findings as $finding) {
            $high = in_array(strtoupper((string) ($finding['severity'] ?? '')), ReviewPublisher::HIGH_SEVERITIES, true);
            $id = (string) ($finding['id'] ?? '');
            // New as the server knows it: called open, but never reported, is new too.
            $new = ($finding['status'] ?? 'new') === 'new' || ! array_key_exists($id, $known);

            if ($new && ($over || (! $high && $notConverging))) {
                $set[] = '- '.($finding['title'] ?? ($id ?: 'A finding'))
                    .(filled($finding['path'] ?? null) ? " (`{$finding['path']}".(filled($finding['line'] ?? null) ? ":{$finding['line']}" : '').'`)' : '')
                    .': '.strtolower((string) ($finding['severity'] ?? '')).', set aside because '
                    .($over ? "this pull request has had its {$max} follow-up rounds" : 'the review is not converging').'.';
                $changed = true;

                continue;
            }

            if (! $high && (($new && $late) || ($known[$id] ?? false))) {
                $finding['advisory'] = true;
                $changed = true;
            }

            $kept[] = $finding;
        }

        if ($set !== []) {
            $summary = self::withOpenQuestions((string) $summary, $set);
        }

        // The count the summary opens with, as it now stands.
        if ($changed && is_string($summary)) {
            $summary = self::recounted($summary, $kept);
        }

        return ['findings' => $kept, 'summary' => $summary];
    }

    /**
     * The summary with its line of counts ("1 high, 2 medium") told again from the
     * findings that stand: what was set aside is gone from it, and "nothing
     * blocking" said where nothing holds the merge any more.
     *
     * @param  array<int, array<string, mixed>>  $findings
     */
    protected static function recounted(string $summary, array $findings): string
    {
        $open = array_filter($findings, fn (array $finding): bool => in_array($finding['status'] ?? 'new', ReviewPublisher::OPEN, true));
        $counts = [];

        foreach (['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'] as $severity) {
            $n = count(array_filter($open, fn (array $finding): bool => strtoupper((string) ($finding['severity'] ?? '')) === $severity));

            if ($n > 0) {
                $counts[] = $n.' '.strtolower($severity);
            }
        }

        $blocking = array_filter($open, fn (array $finding): bool => ReviewPublisher::blocks($finding, ReviewPublisher::DEFAULT_FAIL_ON));
        $line = $counts === [] ? 'No findings.' : implode(', ', $counts).($blocking === [] ? ', nothing blocking.' : '.');

        // OpenCodeReview counts by status: "…: 1 new, 0 still open, 2 fixed".
        $new = count(array_filter($open, fn (array $finding): bool => ($finding['status'] ?? 'new') === 'new'));
        $still = count($open) - $new;
        $summary = (string) preg_replace('/: \d+ new, \d+ still open, /', ": {$new} new, {$still} still open, ", $summary, 1);

        $pattern = '/^(No findings\.|\d+ (critical|high|medium|low)(, \d+ (critical|high|medium|low))*(, nothing blocking)?\.?)[ \t]*$/mi';

        return preg_match($pattern, $summary) === 1 ? (string) preg_replace($pattern, $line, $summary, 1) : $summary;
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
