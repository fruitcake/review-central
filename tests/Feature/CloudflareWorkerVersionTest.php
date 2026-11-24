<?php

use App\Runners\Container\CloudflareSandbox;
use App\Runners\Container\ReviewSessions;

/*
 * The setup page tells a Worker that was not deployed again after a change by the
 * version it says it runs. That only works when every change raises the version.
 *
 * Changed cloudflare/worker/src/index.ts? Raise WORKER_VERSION in it, then put the
 * hash this test prints here, with the version.
 */
const WORKER_SOURCE = ['version' => '2026-11-24.6', 'hash' => 'b8a1f8b5f3957e8bf4d9320208d9152c29d4c341896fda0fb2f746897d4a0a07'];

it('raises the Worker\'s version with every change to its code', function () {
    $source = (string) file_get_contents(base_path('cloudflare/worker/src/index.ts'));
    $hash = hash('sha256', (string) preg_replace('/^const WORKER_VERSION = "[^"]+";$/m', '', $source));

    expect(CloudflareSandbox::workerVersion())->toBe(WORKER_SOURCE['version'], 'WORKER_VERSION in index.ts and here differ.')
        ->and($hash)->toBe(WORKER_SOURCE['hash'], "cloudflare/worker/src/index.ts changed: raise WORKER_VERSION there, and set version and hash here (the hash is now {$hash}).");
});

it('leaves the same caches out of the reviewer\'s home as review-central', function () {
    $source = (string) file_get_contents(base_path('cloudflare/worker/src/index.ts'));

    preg_match('/^const HOME_CACHES = \[(.*)\];$/m', $source, $match);

    expect(json_decode('['.($match[1] ?? '').']', true))->toBe(ReviewSessions::CACHES);
});
