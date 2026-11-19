<?php

declare(strict_types=1);

namespace App\Runners\Container;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * The bucket a deployed Worker is bound to, reached from here as a filesystem disk.
 */
final readonly class BucketFiles implements SandboxFiles
{
    public function __construct(protected Filesystem $disk) {}

    public function put(string $key, $stream): void
    {
        $this->disk->writeStream($key, $stream);
    }

    public function get(string $key)
    {
        $stream = $this->disk->readStream($key);

        return is_resource($stream) ? $stream : null;
    }

    public function forget(string $prefix): void
    {
        $this->disk->deleteDirectory($prefix);
    }
}
