<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OpenPullRequest;
use App\SourceControl\SourceControl;
use Throwable;

/**
 * Whether an open pull request merges into its destination as it stands, asked of
 * its host and kept with it: a conflict is the author's to resolve before anything
 * else, and its destination can move without the pull request changing.
 */
class MergeState
{
    /**
     * Asks the host and keeps the answer, with the head it was asked for. One the
     * host cannot give yet leaves the last, which current() no longer counts once
     * the head moved. Kept without touching its last change, which is the host's.
     */
    public function refresh(OpenPullRequest $record, ?SourceControl $host = null): void
    {
        try {
            $state = ($host ?? $record->repository->sourceControl())->mergeConflicts((string) $record->pull_request_id);
        } catch (Throwable) {
            return;
        }

        if ($state === null) {
            return;
        }

        $record->forceFill([
            'merge_conflicts' => $state['conflicts'],
            'conflicting_files' => $state['files'] === [] ? null : $state['files'],
            'merge_checked_at' => now(),
            'merge_checked_commit' => $record->commit,
        ]);
        $record->timestamps = false;
        $record->save();
        $record->timestamps = true;
    }

    /**
     * Whether it conflicts, as last asked of its present head; null when that is
     * not known.
     */
    public static function current(OpenPullRequest $record): ?bool
    {
        return $record->merge_conflicts !== null && $record->merge_checked_commit === $record->commit ? $record->merge_conflicts : null;
    }

    /**
     * As the MCP servers and an API give it.
     *
     * @param  array{conflicts: bool, files: array<int, string>}|null  $live  asked of the host for one not listed here
     * @return array{conflicts: bool, files?: array<int, string>, checked_at?: string}|null
     */
    public static function describe(?OpenPullRequest $record, ?array $live = null): ?array
    {
        if ($record === null) {
            return $live === null ? null : array_filter(['conflicts' => $live['conflicts'], 'files' => $live['files'] ?: null], fn (mixed $value): bool => $value !== null);
        }

        $conflicts = self::current($record);

        return $conflicts === null ? null : array_filter([
            'conflicts' => $conflicts,
            'files' => $record->conflicting_files,
            'checked_at' => $record->merge_checked_at?->toIso8601String(),
        ], fn (mixed $value): bool => $value !== null);
    }
}
