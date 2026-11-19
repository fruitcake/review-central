<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\ReviewLogGrew;
use App\Events\ReviewRunChanged;
use App\Models\ReviewRun;

/**
 * Tells open pages that something changed, where the installation has a
 * broadcaster: Pusher (or anything that speaks its protocol) or a Mercure hub,
 * chosen with BROADCAST_CONNECTION. Without one the pages ask by themselves.
 *
 * A broadcast only says that something changed, never what: the page then reads it
 * from this server as it always did. So no code and no review text reaches the
 * broadcaster, and a page that missed one is only late.
 */
class Broadcasts
{
    /** The drivers a page can listen to. `log` and `null` are broadcasters too, and reach nobody. */
    public const DRIVERS = ['pusher', 'mercure'];

    public static function enabled(): bool
    {
        return in_array(self::driver(), self::DRIVERS, true);
    }

    public static function driver(): ?string
    {
        $driver = config('broadcasting.connections.'.config('broadcasting.default').'.driver');

        return is_string($driver) ? $driver : null;
    }

    /**
     * What Laravel Echo is made with in the browser, or null when pages do not listen.
     * Read from the configuration on every request, so the choice is the
     * environment's and no script has to be built for it.
     *
     * @return array<string, mixed>|null
     */
    public static function echoOptions(): ?array
    {
        $connection = (array) config('broadcasting.connections.'.config('broadcasting.default'));

        $own = filled($connection['own_host'] ?? null);

        return match (self::driver()) {
            'pusher' => array_filter([
                'broadcaster' => 'pusher',
                'key' => $connection['key'] ?? null,
                'cluster' => $connection['options']['cluster'] ?? null,
                // Only for a server of one's own that speaks Pusher's protocol.
                'wsHost' => $own ? $connection['options']['host'] : null,
                'wsPort' => $own ? (int) $connection['options']['port'] : null,
                'wssPort' => $own ? (int) $connection['options']['port'] : null,
                'forceTLS' => ($connection['options']['scheme'] ?? 'https') === 'https',
                'enabledTransports' => ['ws', 'wss'],
            ], fn (mixed $value): bool => $value !== null),
            'mercure' => array_filter([
                'broadcaster' => 'mercure',
                // Without one it is the hub at this site's own /.well-known/mercure.
                'host' => ($connection['public_url'] ?? null) ?: ($connection['url'] ?? null),
            ], fn (mixed $value): bool => $value !== null),
            default => null,
        };
    }

    /**
     * A review started, ended or changed how it reads in a list.
     */
    public static function runChanged(ReviewRun $run): void
    {
        if (self::enabled()) {
            rescue(fn () => ReviewRunChanged::dispatch((int) $run->tenant_id, (string) $run->uuid), report: false);
        }
    }

    /**
     * A container review printed something.
     */
    public static function logGrew(int $repositoryId, int $build): void
    {
        if (self::enabled()) {
            rescue(fn () => ReviewLogGrew::dispatch($repositoryId, $build), report: false);
        }
    }

    /**
     * The name a Livewire page listens under for one of these, on a private channel.
     */
    public static function listener(string $channel, string $event): string
    {
        return "echo-private:{$channel},.{$event}";
    }
}
