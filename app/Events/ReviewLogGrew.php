<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A container review printed something. It says only that: the log itself may
 * quote the repository's code, and stays on this server.
 */
class ReviewLogGrew implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public const NAME = 'log.grew';

    public function __construct(public int $repositoryId, public int $build) {}

    public static function channel(int $repositoryId, int $build): string
    {
        return "review-logs.{$repositoryId}.{$build}";
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(self::channel($this->repositoryId, $this->build));
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array<string, int>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
