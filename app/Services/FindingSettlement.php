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
     * @param  array<int, array{finding_id: string, verdict: string, reason?: string|null, note?: string|null, reply?: string|null}>  $items
     * @return array{review: string, findings: array<int, array<string, mixed>>}
     *
     * @throws RepositoryNotFound
     * @throws ActionRefused
     */
    public function settle(User $user, Repository $repository, ?int $pullRequestId, ?string $branch, array $items): array
    {
        $pullRequest = $this->feedback->resolve($repository, $pullRequestId, $branch);
        $run = $this->feedback->latestRun($repository, $pullRequest['id'])
            ?? throw new ActionRefused("Pull request #{$pullRequest['id']} has no review with findings yet.");

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
        $host = $repository->sourceControl();
        $errors = [];

        try {
            $threads = $this->feedback->threads(ReviewContext::compactComments($host->getPullRequestComments((string) $pullRequestId), null));
        } catch (Throwable $exception) {
            return array_fill_keys(array_column($items, 'finding_id'), 'The pull request\'s comments could not be read: '.$exception->getMessage());
        }

        $titles = collect($run->findings ?? [])->pluck('title', 'id');

        foreach ($items as $item) {
            $id = $item['finding_id'];
            // Posted by Review Central's own account, so it says whose it is.
            $body = rtrim((string) $item['reply'])."\n\n— {$user->name}, via Review Central";
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
}
