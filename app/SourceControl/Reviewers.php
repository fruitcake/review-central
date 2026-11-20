<?php

declare(strict_types=1);

namespace App\SourceControl;

use Illuminate\Support\Carbon;

/**
 * The people asked to review a pull request, and what each of them last did, as a
 * pull request in the shape SourceControl documents carries them:
 *
 *  - reviewers: the users asked to review, as Bitbucket lists them
 *  - participants: user, role (REVIEWER, PARTICIPANT), approved, state
 *    (approved, changes_requested, null) and participated_on, as Bitbucket lists them
 *  - reviewers_partial: true where the host lists only who has yet to review, as
 *    GitHub does, so that a reviewer left out has not been taken off
 */
final class Reviewers
{
    public const APPROVED = 'approved';

    public const CHANGES_REQUESTED = 'changes_requested';

    /**
     * @param  array<string, mixed>  $pullRequest
     * @param  array<int, string|null>  $leaveOut  accounts that are not reviewers here: the review's own
     * @return array<int, array{name: string|null, account_id: string|null, nickname: string|null, state: string|null, at: string|null}>
     */
    public static function from(array $pullRequest, array $leaveOut = []): array
    {
        $found = [];
        $author = self::person($pullRequest['author'] ?? []);

        foreach ((array) ($pullRequest['reviewers'] ?? []) as $user) {
            if (is_array($user)) {
                $person = self::person($user);
                $found[self::key($person)] = $person + ['state' => null, 'at' => null];
            }
        }

        foreach ((array) ($pullRequest['participants'] ?? []) as $participant) {
            if (! is_array($participant) || ! is_array($participant['user'] ?? null)) {
                continue;
            }

            $state = match (true) {
                ($participant['state'] ?? null) === self::CHANGES_REQUESTED => self::CHANGES_REQUESTED,
                ($participant['state'] ?? null) === self::APPROVED, ($participant['approved'] ?? false) === true => self::APPROVED,
                default => null,
            };

            // Someone who only commented is no reviewer; someone who approved is, asked or not.
            if (($participant['role'] ?? null) !== 'REVIEWER' && $state === null) {
                continue;
            }

            $person = self::person($participant['user']);
            $found[self::key($person)] = $person + ['state' => $state, 'at' => self::time($participant['participated_on'] ?? null)];
        }

        $leaveOut = array_filter(array_map(fn (?string $account): string => mb_strtolower((string) $account), $leaveOut));

        return array_values(array_filter($found, fn (array $reviewer): bool => self::key($reviewer) !== self::key($author)
            && ! in_array(mb_strtolower((string) $reviewer['account_id']), $leaveOut, true)));
    }

    /**
     * Whether the host lists only who has yet to review.
     *
     * @param  array<string, mixed>  $pullRequest
     */
    public static function partial(array $pullRequest): bool
    {
        return ($pullRequest['reviewers_partial'] ?? false) === true;
    }

    /**
     * What identifies one person across deliveries.
     *
     * @param  array{name?: string|null, account_id?: string|null, nickname?: string|null}  $person
     */
    public static function key(array $person): string
    {
        return mb_strtolower((string) (($person['account_id'] ?? null) ?: (($person['nickname'] ?? null) ?: ($person['name'] ?? ''))));
    }

    /**
     * @param  array<string, mixed>  $user  as Bitbucket describes a user
     * @return array{name: string|null, account_id: string|null, nickname: string|null}
     */
    public static function person(array $user): array
    {
        $text = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        return [
            'name' => $text($user['display_name'] ?? null) ?? $text($user['nickname'] ?? null),
            'account_id' => $text($user['account_id'] ?? null),
            'nickname' => $text($user['nickname'] ?? null),
        ];
    }

    protected static function time(mixed $time): ?string
    {
        return is_string($time) && $time !== '' ? rescue(fn (): string => Carbon::parse($time)->utc()->toIso8601String(), null, false) : null;
    }
}
