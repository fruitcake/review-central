<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Repository;
use App\Models\ReviewRun;
use Throwable;

/**
 * The end of a review: closes the run's record with what the review produced,
 * puts the review on the pull request and hands the review lock back.
 *
 * Every runner files here: the container review's report step, the diff review and
 * the security review.
 */
class ReviewFiling
{
    /**
     * A run with no record yet is recorded anyway, so nothing is lost when its
     * opening failed.
     *
     * @param  array<string, mixed>  $validated  the run's report: branch, commit, build, result, findings, usage and the like
     * @return array<string, mixed> the run's id and status, what was published and what the lock was handed to
     */
    public function file(Repository $repository, array $validated): array
    {
        $run = ReviewRun::firstOrNew([
            'repository_id' => $repository->getKey(),
            'commit' => $validated['commit'],
            'build_number' => $validated['build'] ?? null,
        ]);

        $run->fill([
            'tenant_id' => $repository->tenant_id,
            'runner' => $run->runner ?? $repository->runnerName(),
            'workspace' => $repository->workspace,
            'slug' => $repository->slug,
            'branch' => $validated['branch'],
            'pull_request_id' => $validated['pull_request_id'] ?? $run->pull_request_id,
        ]);

        foreach (['agent', 'provider', 'effort', 'advisor'] as $field) {
            if (array_key_exists($field, $validated)) {
                $run->{$field} = $validated[$field];
            }
        }

        if (isset($validated['custom'])) {
            $run->custom = (bool) $validated['custom'];
        }

        // The report carries the run's complete totals, read from the transcript, so
        // it replaces rather than adds. A report with nothing in it leaves whatever
        // is already recorded alone: an empty payload is "I have no numbers", not
        // "this run used nothing", and telemetry may have collected them live.
        if (($validated['usage'] ?? []) !== [] || isset($validated['model']) || isset($validated['cost'])) {
            $run->recordUsage(
                $validated['usage'] ?? $run->usage ?? [],
                $validated['model'] ?? null,
                isset($validated['cost']) ? (float) $validated['cost'] : ($run->cost === null ? null : (float) $run->cost),
            );
        }

        // A run the server never saw start has no start time; the report is all there
        // is. The column cannot be null, so one is invented - and remembered as
        // invented, so the duration is left unknown rather than reported as zero.
        $timed = $run->exists;

        if (! $timed) {
            $run->started_at = now();
        }
        $run->save();

        $publishes = (bool) ($validated['publish'] ?? true);
        $status = $validated['status'] ?? ReviewRun::STATUS_FINISHED;

        // The pull request's own review where its connection keeps reviews here: it
        // is the answer for its commit, only not posted. A later delivery for the same
        // commit finds it reviewed, as it would one that was posted.
        $keptHere = ! $publishes && ! $run->custom && ! rescue(fn (): bool => $repository->sourceControl()->publishes(), true, false);

        // Only a finished run is recorded as unpublished: a failed or timed-out one
        // published nothing either way, and its status says more.
        if (! $publishes && ! $keptHere && $status === ReviewRun::STATUS_FINISHED) {
            $status = ReviewRun::STATUS_UNPUBLISHED;
        }

        $findings = isset($validated['findings']) ? $this->findings($validated['findings']) : null;
        $summary = $validated['summary'] ?? null;

        // The rules for follow-up rounds, where the review did not keep to them itself.
        if ($findings !== null) {
            ['findings' => $findings, 'summary' => $summary] = app(ReviewFollowUps::class)->apply($run, $findings, $summary);
        }

        // Kept with the result it decides, so whoever reads the run later can tell
        // which of its findings held the merge.
        $run->fail_on = ReviewPublisher::failOnSpec($validated['fail_on'] ?? null);

        $run->finish(
            $status,
            $this->result($validated['result'] ?? null, $findings, $validated['fail_on'] ?? null),
            $summary,
            $findings,
            $timed,
            // The step's own measurement, used only when the server has no start of
            // its own to subtract from.
            isset($validated['duration']) ? (int) $validated['duration'] : null,
        );

        $this->costIt($run);
        $this->noteWhatMoved($repository, $run);

        // Before the lock is released, not after: releasing it lets a queued run
        // start, and a run that starts while these comments are half-written reads
        // an incomplete review as the state to carry forward.
        if ($publishes) {
            $publish = $this->publish(
                $repository,
                $run,
                $validated['mode'] ?? 'both',
                (bool) ($validated['create_tasks'] ?? true),
                $validated['fail_on'] ?? ReviewPublisher::DEFAULT_FAIL_ON,
                [
                    'changes' => $validated['changes'] ?? [],
                    'before_release' => $validated['before_release'] ?? [],
                    'manual_review' => $validated['manual_review'] ?? null,
                ],
            );

            // A review nobody can see is not an answer. Left as `finished` it would
            // satisfy the unchanged-inputs check on the next run, and a retry of the
            // very build that failed to publish would be skipped as already answered -
            // so the failure would be permanent. Recorded as failed instead: the cost
            // and the findings are kept, the run stays visible in the admin, and the
            // next run reviews and publishes again.
            if (ReviewPublisher::failed($publish)) {
                $run->status = ReviewRun::STATUS_FAILED;
                $run->save();
            }

        } else {
            $publish = ['published' => false, 'reason' => $keptHere ? 'the connection keeps its reviews here' : 'publishing was turned off'];
        }

        // The review is on the pull request by now, so the lock has done its job and
        // whatever was waiting can start rather than wait for the rest of the
        // job to wind down. The job releases again at its end and finds nothing to
        // do.
        $handover = $run->build_number === null
            ? null
            : ReviewHandover::release($repository, $validated['branch'], $run->commit, (string) $run->build_number);

        return [
            'id' => $run->getKey(),
            'status' => $run->status,
            'duration_seconds' => $run->duration_seconds,
            'total_tokens' => $run->totalTokens(),
            'superseded_by' => $run->superseded_by,
            'publish' => $publish,
            'handover' => $handover,
        ];
    }

    /**
     * Whether the review asks for changes: what the threshold says of its findings,
     * not what the report says of itself.
     *
     * The pull request is approved or held from this. A report is written by a
     * reviewer that read the pull request, and one that lists a blocking finding
     * and calls itself passed must not earn an approval.
     *
     * @param  array<int, array<string, mixed>>|null  $findings
     */
    protected function result(?string $reported, ?array $findings, ?string $failOn): ?string
    {
        // A report without findings at all says nothing to work it out from.
        if ($findings === null) {
            return $reported;
        }

        $failOn = ReviewPublisher::failOnSpec($failOn);

        foreach ($findings as $finding) {
            if (ReviewPublisher::blocks($finding, $failOn)) {
                return 'FAILED';
            }
        }

        return 'PASSED';
    }

    /**
     * The findings as every reader of a run compares them: a reviewer may write
     * "NEW" for new, "high" for HIGH or "8" for 8, and leave a new finding's
     * status out. The report step of a container review does the same before it
     * files; a review this server does itself has no such step.
     *
     * @param  array<mixed>  $findings
     * @return array<int, array<string, mixed>>
     */
    protected function findings(array $findings): array
    {
        return array_values(array_map(function (array $finding): array {
            $finding['status'] = strtolower((string) ($finding['status'] ?? 'new')) ?: 'new';

            // The server's to set, by the rules for follow-up rounds: never the reviewer's.
            unset($finding['advisory']);

            // An id is text and a title is text: anything else a reviewer writes there
            // would break whatever reads them after, publishing first.
            if (isset($finding['id'])) {
                is_scalar($finding['id']) ? $finding['id'] = (string) $finding['id'] : $finding['id'] = null;
            }

            if (isset($finding['title']) && ! is_string($finding['title'])) {
                $finding['title'] = is_scalar($finding['title']) ? (string) $finding['title'] : null;
            }

            if (isset($finding['severity'])) {
                $finding['severity'] = strtoupper((string) $finding['severity']);
            }

            if (isset($finding['category'])) {
                $finding['category'] = strtolower((string) $finding['category']);
            }

            if (is_string($finding['confidence'] ?? null)) {
                $finding['confidence'] = is_numeric($finding['confidence']) ? (int) $finding['confidence'] : null;
            }

            // Left out, a finding counts as certain; null would count as zero.
            if (! isset($finding['confidence'])) {
                unset($finding['confidence']);
            }

            return $finding;
        }, array_filter($findings, is_array(...))));
    }

    /**
     * Puts the review on the pull request.
     *
     * Never throws. The run is already recorded by the time this runs, and losing
     * that record — or failing to file the report, which is what releases the
     * review lock — would cost more than a review that did not get commented.
     * What happened goes back to the caller, so a failure shows up in the run's
     * log rather than only in the server's exception tracker.
     *
     * @param  array<string, mixed>  $notes
     * @return array<string, mixed>
     */
    protected function publish(Repository $repository, ReviewRun $run, string $mode, bool $createTasks, string $failOn, array $notes): array
    {
        try {
            return app(ReviewPublisher::class)->publish($repository, $run, $mode, $createTasks, $failOn, $notes);
        } catch (Throwable $exception) {
            report($exception);

            return ['published' => false, 'reason' => $exception->getMessage()];
        }
    }

    /**
     * Works the cost out from the tokens when the run could not report one.
     *
     * A review running over ACP reports its cost on the protocol stream, which the
     * run never sees — but the tokens are exact, so with a price list the
     * money is arithmetic. It is marked as estimated, because a figure someone
     * might act on should say whether it was measured or derived.
     */
    protected function costIt(ReviewRun $run): void
    {
        // A model the price list does not carry yet leaves the cost null. The daily
        // price sync comes back to it once the list has caught up, so this failing
        // is not final.
        $run->estimateCost();
    }

    /**
     * Records what the pull request moved to while this run was reviewing.
     *
     * The review is published either way — it is the baseline the next run reviews
     * from, so discarding it would cost more than it saves. This is the handover
     * note: the run says what it did not get to, instead of the next one being
     * assumed to notice.
     *
     * Best-effort. A review that happened must not be lost over bookkeeping.
     */
    protected function noteWhatMoved(Repository $repository, ReviewRun $run): void
    {
        if ($run->pull_request_id === null) {
            return;
        }

        try {
            $pullRequest = $repository->sourceControl()->getPullRequest((string) $run->pull_request_id);

            $head = $pullRequest['source']['commit']['hash'] ?? null;

            if (is_string($head) && $head !== '' && ! ReviewContext::isSameCommit($run->commit, $head)) {
                $run->superseded_by = $head;
                $run->save();
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
