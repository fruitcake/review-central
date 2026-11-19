<?php

declare(strict_types=1);

namespace App\Connections;

/**
 * A driver that works only once this installation is set up for it: until then
 * it is not offered for a new connection.
 */
interface NeedsSetup
{
    public function isSetUp(): bool;
}
