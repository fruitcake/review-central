<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FindingVerdictKind;
use App\Enums\FindingVerdictReason;
use App\Models\FindingVerdict;
use App\Models\ReviewRating;
use App\Models\ReviewRun;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * What people said of the findings: on a review's own page, one finding at a
 * time, and on the rating page, where each is called fair or not.
 */
class FindingFeedback
{
    /**
     * Stores what a user thinks of a finding, replacing what they said before.
     * Giving the same verdict again takes it back.
     *
     * @throws InvalidArgumentException when the run has no such finding
     */
    public function give(ReviewRun $run, User $user, string $findingId, FindingVerdictKind $verdict): ?FindingVerdictKind
    {
        $this->mustHave($run, $findingId);

        $key = ['review_run_id' => $run->getKey(), 'user_id' => $user->getKey(), 'finding_id' => $findingId];
        $existing = FindingVerdict::query()->where($key)->first();

        if ($existing?->verdict === $verdict) {
            $existing->delete();

            return null;
        }

        // A changed verdict leaves the reason for the old one behind.
        FindingVerdict::query()->updateOrCreate($key, ['verdict' => $verdict, 'reason' => null, 'note' => null]);

        return $verdict;
    }

    /**
     * Stores what a user thinks of a finding, replacing what they said before;
     * null takes it back. Unlike give(), the same verdict twice stays given, for a
     * caller that says what it should be rather than clicking.
     *
     * A reason and a note say why a finding was dismissed; a valid one has neither.
     *
     * @throws InvalidArgumentException when the run has no such finding, or a valid one is given a reason
     */
    public function set(ReviewRun $run, User $user, string $findingId, ?FindingVerdictKind $verdict, ?FindingVerdictReason $reason = null, ?string $note = null): void
    {
        $this->mustHave($run, $findingId);

        if (($reason !== null || filled($note)) && ($verdict === null || $verdict->accepts())) {
            throw new InvalidArgumentException("Finding {$findingId}: a reason is for a finding that is not a problem or wrong, not for one judged valid.");
        }

        $key = ['review_run_id' => $run->getKey(), 'user_id' => $user->getKey(), 'finding_id' => $findingId];

        $verdict === null
            ? FindingVerdict::query()->where($key)->delete()
            : FindingVerdict::query()->updateOrCreate($key, ['verdict' => $verdict, 'reason' => $reason, 'note' => filled($note) ? $note : null]);
    }

    /**
     * Why the user dismissed a finding, given after the verdict, as on the review's
     * page; null takes the reason back. Nothing for a finding they did not dismiss.
     */
    public function explain(ReviewRun $run, User $user, string $findingId, ?FindingVerdictReason $reason): void
    {
        FindingVerdict::query()
            ->where(['review_run_id' => $run->getKey(), 'user_id' => $user->getKey(), 'finding_id' => $findingId])
            ->whereIn('verdict', [FindingVerdictKind::NotAProblem, FindingVerdictKind::Wrong])
            ->update(['reason' => $reason]);
    }

    /**
     * @throws InvalidArgumentException when the run has no such finding
     */
    protected function mustHave(ReviewRun $run, string $findingId): void
    {
        if (! $run->hasFinding($findingId)) {
            throw new InvalidArgumentException("This review has no finding {$findingId}.");
        }
    }

    /**
     * @return array<string, FindingVerdictKind> by finding id
     */
    public function of(ReviewRun $run, User $user): array
    {
        return FindingVerdict::query()
            ->where('review_run_id', $run->getKey())
            ->where('user_id', $user->getKey())
            ->get()
            ->mapWithKeys(fn (FindingVerdict $verdict): array => [$verdict->finding_id => $verdict->verdict])
            ->all();
    }

    /**
     * Why the user dismissed each finding they gave a reason for.
     *
     * @return array<string, FindingVerdictReason> by finding id
     */
    public function reasons(ReviewRun $run, User $user): array
    {
        return FindingVerdict::query()
            ->where('review_run_id', $run->getKey())
            ->where('user_id', $user->getKey())
            ->whereNotNull('reason')
            ->get()
            ->mapWithKeys(fn (FindingVerdict $verdict): array => [$verdict->finding_id => $verdict->reason])
            ->all();
    }

    /**
     * Every opinion on the findings of these runs, from both places. Several
     * opinions on one finding are several opinions, and each counts.
     *
     * @param  Collection<int, ReviewRun>  $runs  with their findings
     * @return array<int, array<int, array{category: string, fair: bool}>> by run id
     */
    public function forRuns(Collection $runs): array
    {
        $byRun = $runs->keyBy('id');
        $category = fn (int $run, string $finding): string => strtolower((string) (collect($byRun[$run]->findings ?? [])->pluck('category', 'id')[$finding] ?? ''));
        $opinions = [];

        foreach (ReviewRating::query()->whereIn('review_run_id', $byRun->keys())->get(['review_run_id', 'findings']) as $rating) {
            foreach ($rating->findings ?? [] as $id => $fair) {
                $opinions[$rating->review_run_id][] = ['category' => $category($rating->review_run_id, (string) $id), 'fair' => (bool) $fair];
            }
        }

        foreach (FindingVerdict::query()->whereIn('review_run_id', $byRun->keys())->get() as $verdict) {
            $opinions[$verdict->review_run_id][] = ['category' => $category($verdict->review_run_id, $verdict->finding_id), 'fair' => $verdict->verdict->accepts()];
        }

        return $opinions;
    }
}
