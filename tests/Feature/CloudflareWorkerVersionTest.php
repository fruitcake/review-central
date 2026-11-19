<?php

use App\Runners\Container\CloudflareSandbox;

/*
 * The setup page tells a Worker that was not deployed again after a change by the
 * version it says it runs. That only works when every change raises the version.
 *
 * Changed cloudflare/worker/src/index.ts? Raise WORKER_VERSION in it, then put the
 * hash this test prints here, with the version.
 */
const WORKER_SOURCE = ['version' => '2026-11-19.1', 'hash' => '19330c907ff2af93b51d66acf48bdb6eb6dae4af51922546e8e5dc526a3b9be1'];

it('raises the Worker\'s version with every change to its code', function () {
    $source = (string) file_get_contents(base_path('cloudflare/worker/src/index.ts'));
    $hash = hash('sha256', (string) preg_replace('/^const WORKER_VERSION = "[^"]+";$/m', '', $source));

    expect(CloudflareSandbox::workerVersion())->toBe(WORKER_SOURCE['version'], 'WORKER_VERSION in index.ts and here differ.')
        ->and($hash)->toBe(WORKER_SOURCE['hash'], "cloudflare/worker/src/index.ts changed: raise WORKER_VERSION there, and set version and hash here (the hash is now {$hash}).");
});
