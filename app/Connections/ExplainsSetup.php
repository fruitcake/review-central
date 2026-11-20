<?php

declare(strict_types=1);

namespace App\Connections;

use App\Support\SetupGuide;

/**
 * A driver that says how to get what it asks for: the page its key or token is
 * made on, the steps there, and the scopes it needs. The connection form offers
 * it as "How to get this". Optional, so a driver of one's own works without it.
 */
interface ExplainsSetup
{
    public function setupGuide(): SetupGuide;
}
