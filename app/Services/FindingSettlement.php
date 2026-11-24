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
use App\SourceControl\SourceControl;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settles a pull request's findings in one go, as a person would one by one: for
 * each, what they think of it (valid, not a problem, wrong) with why, and where it
 * needs one, a reply in its thread on the pull request, which the next review reads.
 */
class FindingSettlement
{
    public const AS_USER = 'you';

    /**
     * Under a reply posted as the user, so the reviewer does not take it for a
     * question: on the reviewer's own account that went without saying. Hidden in
     * the rendered comment, and no part of the user's own text can write it.
     */
    public const SETTLED_MARKER = '[//]: # (ai-settled)';

    public const AS_CONNECTION = 'the team\'s connection, with your name above it';

    public function __construct(protected PullRequestFeedback $feedback, protected FindingFeedback $verdicts, protected PersonalConnections $personal) {}

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
        ['errors' => $posted, 'as' => $as] = $replies === [] ? ['errors' => [], 'as' => []] : $this->reply($user, $repository, $pullRequest['id'], $run, $replies);

        return [
            'review' => $run->uuid,
            'findings' => array_map(fn (array $item): array => array_filter([
                'finding_id' => $item['finding_id'],
                'verdict' => $item['verdict']->value ?? 'none',
                'reason' => $item['reason']?->value,
                'replied' => filled($item['reply']) ? ($posted[$item['finding_id']] ?? null) === null : null,
                // Whose account the reply is on, so the assistant can say it.
                'replied_as' => $as[$item['finding_id']] ?? null,
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
     * the finding when it has none there: as the user where their own account is
     * connected, else by the tenant's connection, with their name above it. A
     * reply the host refuses from their account, as on a repository it cannot
     * reach, goes the second way; one whose answer was lost may have been posted,
     * and is not posted again.
     *
     * @param  array<int, array{finding_id: string, reply: string|null}>  $items
     * @return array{errors: array<string, string>, as: array<string, string>} what went wrong, and whose account each reply is on, by finding id
     */
    protected function reply(User $user, Repository $repository, int $pullRequestId, ReviewRun $run, array $items): array
    {
        // A trial posts nothing on the pull request: its F1 is not the F1 there.
        if ($run->custom) {
            return ['errors' => array_fill_keys(array_column($items, 'finding_id'), 'This review is a trial, which is not on the pull request: the reply was not posted.'), 'as' => []];
        }

        $errors = [];

        try {
            $host = $repository->sourceControl();
            $threads = $this->feedback->threads(ReviewContext::compactComments($host->getPullRequestComments((string) $pullRequestId), null));
        } catch (Throwable $exception) {
            return ['errors' => array_fill_keys(array_column($items, 'finding_id'), 'The pull request\'s comments could not be read: '.$exception->getMessage()), 'as' => []];
        }

        $own = $this->personal->writerFor($user, $repository);
        $as = [];
        $titles = collect($run->findings ?? [])->pluck('title', 'id');

        foreach ($items as $item) {
            $id = $item['finding_id'];
            $parent = $threads[$id]['comment_id'] ?? null;
            $post = fn (SourceControl $writer, string $body): array => is_numeric($parent)
                ? $writer->addPullRequestComment((string) $pullRequestId, $body, parentId: (int) $parent)
                : $writer->addPullRequestComment((string) $pullRequestId, "**{$id}** · {$titles->get($id)}\n\n{$body}");

            try {
                if ($own !== null) {
                    try {
                        $post($own, self::ownBody((string) $item['reply']));
                        $as[$id] = self::AS_USER;

                        continue;
                    } catch (RequestException $exception) {
                        // Only a refusal says nothing was posted; any other failure is reported below.
                        if (! in_array($exception->response->status(), [401, 403, 404], true)) {
                            throw $exception;
                        }

                        report($exception);
                    }
                }

                $post($host, self::body($user, (string) $item['reply']));
                $as[$id] = self::AS_CONNECTION;
            } catch (Throwable $exception) {
                $errors[$id] = 'The reply could not be posted: '.$exception->getMessage();
            }
        }

        return ['errors' => $errors, 'as' => $as];
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
     * A reply posted on the user's own account: theirs without a name above it,
     * yet no more a command, a question to the reviewer or a marker than one with
     * it. A backslash before an opening / or @, or between an @ and the account
     * it names, is how Markdown shows the characters themselves.
     */
    public static function ownBody(string $reply): string
    {
        $text = str_replace('@{', '@\\{', self::inert(trim($reply)));

        return (str_starts_with($text, '/') || str_starts_with($text, '@') ? '\\'.$text : $text)."\n\n".self::SETTLED_MARKER;
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
