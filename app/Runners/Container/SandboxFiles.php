<?php

declare(strict_types=1);

namespace App\Runners\Container;

/**
 * Where the archives of a review in a Cloudflare sandbox travel, between this
 * worker and the Worker that starts the sandbox.
 */
interface SandboxFiles
{
    /**
     * @param  resource  $stream
     */
    public function put(string $key, $stream): void;

    /**
     * @return resource|null null when there is nothing under the key
     */
    public function get(string $key);

    /**
     * Removes everything of one review.
     */
    public function forget(string $prefix): void;
}
