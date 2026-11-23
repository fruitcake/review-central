<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FindingVerdictKind;
use App\Enums\FindingVerdictReason;
use App\Exceptions\ActionRefused;
use App\Exceptions\RepositoryNotFound;
use App\Models\Repository;
use App\Models\ReviewRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settles a pull request's findings in one go, as a person would one by one: for
 * each, what they think of it (valid, not a problem, wrong) with why, and where it
 * needs one, a reply in its thread on the pull request, which the next review reads.
 */
class FindingSettlement
{
    public function __construct(protected PullRequestFeedback $feedback, protected FindingFeedback $verdicts) {}

    /**
     * Every verdict is checked before any is stored, and all are stored or none.
     * The replies follow; one that cannot be posted is said so, the rest still are.
     *
     * @param  string  $reviewId  the review the findings were read from
     * @param  array<int, array{finding_id: string, verdict: string, reason?: string|null, note?: string|null, reply?: string|null}>  $items
     * @return array{review: string, findings: array<int, array<string, mixed>>}
     *
     * @throws RepositoryNotFound
     * @throws ActionRefused
     */
    public function settle(User $user, Repository $repository, ?int $pullRequestId, ?string $branch, string $reviewId, array $items): array
    {
        $pullRequest = $this->feedback->resolve($repository, $pullRequestId, $branch);
        $run = $this->feedback->latestRun($repository, $pullRequest['id'])
            ?? throw new ActionRefused("Pull request #{$pullRequest['id']} has no review with findings yet.");

        // Finding ids start again at F1 in another review: a verdict on the F1 that
        // was read has to land on that review, not on one that came after it.
        if ($run->uuid !== $reviewId) {
            throw new ActionRefused("The newest review of pull request #{$pullRequest['id']} is {$run->uuid}, not {$reviewId}. Read it with pull-request-feedback and settle its findings. Nothing was recorded.");
        }

        $settled = array_map(fn (array $item): array => $this->parse($run, $item), $items);

        DB::transaction(function () use ($settled, $run, $user): void {
            foreach ($settled as $item) {
                $this->verdicts->set($run, $user, $item['finding_id'], $item['verdict'], $item['reason'], $item['note']);
            }
        });

        $replies = array_filter($settled, fn (array $item): bool => filled($item['reply']));
        $posted = $replies === [] ? [] : $this->reply($user, $repository, $pullRequest['id'], $run, $replies);

        return [
            'review' => $run->uuid,
            'findings' => array_map(fn (array $item): array => array_filter([
                'finding_id' => $item['finding_id'],
                'verdict' => $item['verdict']->value ?? 'none',
                'reason' => $item['reason']?->value,
                'replied' => filled($item['reply']) ? ($posted[$item['finding_id']] ?? null) === null : null,
                'reply_error' => $posted[$item['finding_id']] ?? null,
            ], fn (mixed $value): bool => $value !== null), $settled),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{finding_id: string, verdict: FindingVerdictKind|null, reason: FindingVerdictReason|null, note: string|null, reply: string|null}
     *
     * @throws ActionRefused
     */
    protected function parse(ReviewRun $run, array $item): array
    {
        $id = (string) ($item['finding_id'] ?? '');
        $verdict = FindingVerdictKind::tryFrom((string) ($item['verdict'] ?? ''));
        $reason = filled($item['reason'] ?? null) ? FindingVerdictReason::tryFrom((string) $item['reason']) : null;
        $note = filled($item['note'] ?? null) ? (string) $item['note'] : null;

        $refusal = match (true) {
            ! $run->hasFinding($id) => "The newest review has no finding {$id}.",
            $verdict === null && ($item['verdict'] ?? null) !== 'none' => "Finding {$id}: the verdict is valid, not_a_problem, wrong or none.",
            filled($item['reason'] ?? null) && $reason === null => "Finding {$id}: the reason is one of ".implode(', ', array_column(FindingVerdictReason::cases(), 'value')).'.',
            ($reason !== null || $note !== null) && ($verdict === null || $verdict->accepts()) => "Finding {$id}: a reason or note is for a finding that is not a problem or wrong.",
            default => null,
        };

        if ($refusal !== null) {
            throw new ActionRefused("{$refusal} Nothing was recorded.");
        }

        return ['finding_id' => $id, 'verdict' => $verdict, 'reason' => $reason, 'note' => $note, 'reply' => filled($item['reply'] ?? null) ? (string) $item['reply'] : null];
    }

    /**
     * Posts each reply under its finding's comment, or on the pull request naming
     * the finding when it has none there.
     *
     * @param  array<int, array{finding_id: string, reply: string|null}>  $items
     * @return array<string, string> what went wrong, by finding id
     */
    protected function reply(User $user, Repository $repository, int $pullRequestId, ReviewRun $run, array $items): array
    {
        // A trial posts nothing on the pull request: its F1 is not the F1 there.
        if ($run->custom) {
            return array_fill_keys(array_column($items, 'finding_id'), 'This review is a trial, which is not on the pull request: the reply was not posted.');
        }

        $errors = [];

        try {
            $host = $repository->sourceControl();
            $threads = $this->feedback->threads(ReviewContext::compactComments($host->getPullRequestComments((string) $pullRequestId), null));
        } catch (Throwable $exception) {
            return array_fill_keys(array_column($items, 'finding_id'), 'The pull request\'s comments could not be read: '.$exception->getMessage());
        }

        $titles = collect($run->findings ?? [])->pluck('title', 'id');

        foreach ($items as $item) {
            $id = $item['finding_id'];
            $body = self::body($user, (string) $item['reply']);
            $parent = $threads[$id]['comment_id'] ?? null;

            try {
                is_numeric($parent)
                    ? $host->addPullRequestComment((string) $pullRequestId, $body, parentId: (int) $parent)
                    : $host->addPullRequestComment((string) $pullRequestId, "**{$id}** · {$titles->get($id)}\n\n{$body}");
            } catch (Throwable $exception) {
                $errors[$id] = 'The reply could not be posted: '.$exception->getMessage();
            }
        }

        return $errors;
    }

    /**
     * A reply as Review Central posts it, on its own account: whose it is first, so
     * it can never open with a command such as /review or @review, and none of its
     * lines read as the review's own markers.
     */
    public static function body(User $user, string $reply): string
    {
        // The name too is the user's own text: on one line, and inert like the reply.
        $name = self::inert(trim((string) preg_replace('/\s+/', ' ', $user->name)));

        return "**{$name}**, via Review Central:\n\n".self::inert(trim($reply));
    }

    /**
     * Text in which no metadata marker reads as one, wherever it stands: the review
     * reads them at a line's start, GitHub's reply parent anywhere.
     */
    protected static function inert(string $text): string
    {
        // An escaped colon shows the same in Markdown, and parts the marker's "]:".
        return str_replace('[//]:', '[//]\\:', $text);
    }
}
