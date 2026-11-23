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
     * Asks the host and keeps the answer; one the host cannot give leaves the last.
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
        ])->save();
    }

    /**
     * As the MCP servers and an API give it.
     *
     * @return array{conflicts: bool, files?: array<int, string>, checked_at?: string}|null
     */
    public static function describe(OpenPullRequest $record): ?array
    {
        if ($record->merge_conflicts === null) {
            return null;
        }

        return array_filter([
            'conflicts' => $record->merge_conflicts,
            'files' => $record->conflicting_files,
            'checked_at' => $record->merge_checked_at?->toIso8601String(),
        ], fn (mixed $value): bool => $value !== null);
    }
}
