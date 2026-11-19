<?php

declare(strict_types=1);

namespace App\Runners\Container;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * The Worker's own bucket, reached through the Worker: for one that runs on this
 * machine under `wrangler dev`, whose bucket is a local stand-in no disk here can
 * reach. A deployed Worker is not asked this way: what a request to it may carry
 * is limited, and a checkout is not.
 */
final readonly class WorkerFiles implements SandboxFiles
{
    /**
     * @param  Closure(int): PendingRequest  $client  a request to the Worker, with a timeout in seconds
     */
    public function __construct(protected Closure $client) {}

    public function put(string $key, $stream): void
    {
        // Read whole: this is one machine talking to itself.
        $response = ($this->client)(600)->withBody((string) stream_get_contents($stream), 'application/gzip')->put("files/{$key}");

        if (! $response->successful()) {
            throw new RuntimeException("The local Cloudflare Worker answered {$response->status()} to a file: is `npx wrangler dev` running in cloudflare/worker?");
        }
    }

    public function get(string $key)
    {
        $response = ($this->client)(600)->get("files/{$key}");

        if (! $response->successful()) {
            return null;
        }

        // Through a temporary file, so what comes back is read as a stream
        // whatever its size.
        $stream = tmpfile();

        if ($stream === false) {
            return null;
        }

        fwrite($stream, $response->body());
        rewind($stream);

        return $stream;
    }

    public function forget(string $prefix): void
    {
        ($this->client)(30)->delete("files/{$prefix}");
    }
}
