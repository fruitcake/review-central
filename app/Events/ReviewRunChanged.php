<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A review of the tenant started, ended, or reads differently in a list.
 *
 * Sent as it happens, not from the queue: the worker that would send it may be the
 * one running the review, and the page would hear of it when the review is done.
 * Rescued: a broadcaster that is down must not cost a review its result.
 */
class ReviewRunChanged implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public const NAME = 'review.changed';

    public function __construct(public int $tenantId, public string $run) {}

    public static function channel(int $tenantId): string
    {
        return "tenants.{$tenantId}";
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(self::channel($this->tenantId));
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return ['run' => $this->run];
    }
}
