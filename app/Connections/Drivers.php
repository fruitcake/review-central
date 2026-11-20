<?php

declare(strict_types=1);

namespace App\Connections;

use App\Enums\ConnectionKind;
use App\Exceptions\UnknownDriver;

/**
 * The drivers from config/review.php, by kind and name.
 */
class Drivers
{
    /**
     * @throws UnknownDriver
     */
    public function get(ConnectionKind $kind, string $driver): ConnectionDriver
    {
        $class = config("review.drivers.{$kind->value}.{$driver}");

        if (! is_string($class)) {
            throw UnknownDriver::named($kind, $driver);
        }

        return app($class);
    }

    /**
     * @return array<string, string> driver name to label
     */
    public function options(ConnectionKind $kind): array
    {
        $options = [];

        foreach (config("review.drivers.{$kind->value}", []) as $driver => $class) {
            // One that needs this installation to be registered somewhere, and is not.
            if (is_string($driver) && ! (($found = $this->get($kind, $driver)) instanceof NeedsSetup && ! $found->isSetUp())) {
                $options[$driver] = $found->label();
            }
        }

        return $options;
    }
}
