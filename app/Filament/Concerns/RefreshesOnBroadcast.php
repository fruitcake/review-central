<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Events\ReviewRunChanged;
use App\Filament\CurrentTenant;
use App\Services\Broadcasts;

/**
 * Renders a page again when a review of the open tenant changed, where the
 * installation broadcasts. Without a broadcaster the page listens to nothing.
 */
trait RefreshesOnBroadcast
{
    /**
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        $listeners = parent::getListeners();

        if (Broadcasts::enabled()) {
            foreach ($this->broadcastsToRefreshOn() as $listener) {
                $listeners[$listener] = '$refresh';
            }
        }

        return $listeners;
    }

    /**
     * @return array<int, string>
     */
    protected function broadcastsToRefreshOn(): array
    {
        return [Broadcasts::listener(ReviewRunChanged::channel((int) CurrentTenant::get()->getKey()), ReviewRunChanged::NAME)];
    }
}
