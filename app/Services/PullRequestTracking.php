<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DescribePullRequest;
use App\Models\OpenPullRequest;
use App\Models\Repository;
use App\SourceControl\Reviewers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps what an open pull request's turn is read from: its reviewers and what each
 * did, when its head changed, and a short history of it. Fed by every delivery
 * about the pull request, and once from the host for those from before.
 */
class PullRequestTracking
{
    /** Seconds a burst of deliveries is waited out before the AI's note is written. */
    public const NOTE_DELAY = 30;

    /**
     * Brings the record up to date with a delivery, before its own fields are:
     * what changed is read against what it held. Does not save it.
     *
     * @param  array<string, mixed>  $data  the pull request in the shape SourceControl documents
     */
    public function track(Repository $repository, OpenPullRequest $record, array $data): void
    {
        $fresh = $record->opened_at === null && ($record->history ?? []) === [];
        $opened = self::time($data['created_on'] ?? null);

        if ($record->opened_at === null) {
            $record->opened_at = $opened ?? now();
        }

        if ($fresh) {
            $author = Reviewers::person(is_array($data['author'] ?? null) ? $data['author'] : [])['name'];
            $record->record('opened', 'Opened'.($author === null ? '' : " by {$author}"), $author, $record->opened_at);
        }

        $destination = $data['destination']['branch']['name'] ?? null;
        $record->destination_branch = is_string($destination) && $destination !== '' ? $destination : $record->destination_branch;

        $commit = $data['source']['commit']['hash'] ?? null;

        if (is_string($commit) && $commit !== '' && ($record->commit === null || ! ReviewContext::isSameCommit($record->commit, $commit))) {
            if ($record->commit !== null) {
                $record->record('pushed', 'New commits, up to '.substr($commit, 0, 7));
            }

            $record->head_since = $record->commit === null ? $record->opened_at : now();
        }

        if (is_bool($data['draft'] ?? null) && ! $fresh && $record->draft !== $data['draft']) {
            $record->record('draft', $data['draft'] ? 'Marked as a draft' : 'Marked ready for review');
        }

        $this->reviewers($repository, $record, $data, $fresh);
    }

    /**
     * Has the AI's note written for the pull request's state, once deliveries have
     * settled: when there is a provider to write it, and the state has changed.
     */
    public function describe(OpenPullRequest $record): void
    {
        if (! DescribePullRequest::wanted($record)) {
            return;
        }

        if (Cache::add("describe-pull-request:{$record->getKey()}", true, self::NOTE_DELAY)) {
            DescribePullRequest::dispatch($record->getKey())->delay(now()->addSeconds(self::NOTE_DELAY));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function reviewers(Repository $repository, OpenPullRequest $record, array $data, bool $fresh): void
    {
        $before = collect($record->reviewers ?? [])->keyBy(fn (array $reviewer): string => Reviewers::key($reviewer));
        $incoming = collect(Reviewers::from($data, [$this->ownAccount($repository)]))->keyBy(fn (array $reviewer): string => Reviewers::key($reviewer));

        // A host that lists only who has yet to review leaves out who has: they stay.
        $after = Reviewers::partial($data)
            ? $before->filter(fn (array $reviewer): bool => $reviewer['state'] !== null)->merge($incoming)
            : $incoming;

        $after = $after->map(function (array $reviewer, string $key) use ($before, $fresh, $record): array {
            $was = $before->get($key);
            $name = $reviewer['name'] ?? 'Someone';

            // Unchanged: it happened when it was first seen. Changed: now, as the
            // delivery arrives; from the host's own word only for the first look.
            if ($was !== null && $was['state'] === $reviewer['state']) {
                return ['at' => $was['at']] + $reviewer;
            }

            $at = $fresh ? ($reviewer['at'] ?? null) : now()->toIso8601String();
            $when = $at === null ? null : Carbon::parse($at);

            match (true) {
                $reviewer['state'] === Reviewers::APPROVED => $record->record('approved', "{$name} approved", $name, $when),
                $reviewer['state'] === Reviewers::CHANGES_REQUESTED => $record->record('changes', "{$name} asked for changes", $name, $when),
                $was === null => $record->record('reviewer', "{$name} is asked to review", $name, $when),
                $was['state'] === Reviewers::APPROVED => $record->record('reviewer', "{$name}'s approval is withdrawn", $name, $when),
                default => $record->record('reviewer', "{$name}'s request for changes is withdrawn", $name, $when),
            };

            return ['at' => $at] + $reviewer;
        });

        foreach ($before->diffKeys($after) as $gone) {
            $name = $gone['name'] ?? 'Someone';

            // Someone who approved without being asked is no reviewer once they withdraw it.
            $record->record('reviewer', match ($gone['state']) {
                Reviewers::APPROVED => "{$name}'s approval is withdrawn",
                Reviewers::CHANGES_REQUESTED => "{$name}'s request for changes is withdrawn",
                default => "{$name} is no longer a reviewer",
            }, $gone['name'] ?? null);
        }

        $record->reviewers = $after->map(fn (array $reviewer): array => [
            'name' => $reviewer['name'],
            'account_id' => $reviewer['account_id'],
            'nickname' => $reviewer['nickname'],
            'state' => $reviewer['state'],
            'at' => $reviewer['at'],
        ])->values()->all();
    }

    /**
     * The account the review acts as, which approves as a reviewer but is the AI
     * review: kept for a day, it is one request.
     */
    protected function ownAccount(Repository $repository): ?string
    {
        return Cache::remember(
            "source-control-account:{$repository->connection_id}",
            now()->addDay(),
            fn (): string => (string) rescue(fn (): ?string => $repository->sourceControl()->accountId(), null, false),
        ) ?: null;
    }

    protected static function time(mixed $time): ?Carbon
    {
        return is_string($time) && $time !== '' ? rescue(fn (): Carbon => Carbon::parse($time)->utc(), null, false) : null;
    }
}
