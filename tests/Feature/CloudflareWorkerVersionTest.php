<?php

use App\Runners\Container\CloudflareSandbox;

/*
 * The setup page tells a Worker that was not deployed again after a change by the
 * version it says it runs. That only works when every change raises the version.
 *
 * Changed cloudflare/worker/src/index.ts? Raise WORKER_VERSION in it, then put the
 * hash this test prints here, with the version.
 */
const WORKER_SOURCE = ['version' => '2026-11-20.1', 'hash' => '5e25ecadd1043e6cf77e1c859fcf036e8f8ae0434bf3db01fd663ae597093eb1'];

it('raises the Worker\'s version with every change to its code', function () {
    $source = (string) file_get_contents(base_path('cloudflare/worker/src/index.ts'));
    $hash = hash('sha256', (string) preg_replace('/^const WORKER_VERSION = "[^"]+";$/m', '', $source));

    expect(CloudflareSandbox::workerVersion())->toBe(WORKER_SOURCE['version'], 'WORKER_VERSION in index.ts and here differ.')
        ->and($hash)->toBe(WORKER_SOURCE['hash'], "cloudflare/worker/src/index.ts changed: raise WORKER_VERSION there, and set version and hash here (the hash is now {$hash}).");
});
