<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tries the Cloudflare setup as this installation has it now, from the settings
 * and the environment: the API token that deploys, the bucket the reviews hand
 * their files through, and the Worker.
 */
class CloudflareTest
{
    public function __construct(protected SetupCheck $setup) {}

    /**
     * @return list<array{ok: bool, title: string, detail: string}> one line for each that is set up
     */
    public function run(): array
    {
        $lines = [];
        $account = config('review.cloudflare.account');
        $token = config('review.cloudflare.api_token');

        if (filled($token)) {
            $lines[] = $this->apiToken((string) $token, filled($account) ? (string) $account : null);
        }

        $disk = (string) config('review.cloudflare.disk');
        $bucket = (string) config("filesystems.disks.{$disk}.bucket");

        if (filled(config("filesystems.disks.{$disk}.key")) && $bucket !== '') {
            $check = $this->setup->cloudflareBucket($disk, $bucket);
            $lines[] = ['ok' => $check['state'] === SetupCheck::OK, 'title' => 'Bucket', 'detail' => $check['detail'].($check['fix'] === null ? '' : ' '.$check['fix'])];
        }

        if (filled(config('review.cloudflare.url')) && filled(config('review.cloudflare.token'))) {
            $status = rescue(fn () => Http::withToken((string) config('review.cloudflare.token'))->timeout(8)->connectTimeout(4)->get(rtrim((string) config('review.cloudflare.url'), '/').'/health')->status(), 0, false);
            $lines[] = ['ok' => $status === 200, 'title' => 'Worker', 'detail' => match ($status) {
                200 => 'It answers at '.config('review.cloudflare.url').'.',
                401 => 'It refuses the token this installation asks it with: deploy it again from the Setup page.',
                0 => 'It cannot be reached at '.config('review.cloudflare.url').'.',
                default => "It answered {$status}.",
            }];
        }

        return $lines;
    }

    /**
     * Whether Cloudflare takes the token: an account's own, or a user's.
     *
     * @return array{ok: bool, title: string, detail: string}
     */
    protected function apiToken(string $token, ?string $account): array
    {
        $urls = array_filter([
            $account === null ? null : "https://api.cloudflare.com/client/v4/accounts/{$account}/tokens/verify",
            'https://api.cloudflare.com/client/v4/user/tokens/verify',
        ]);

        foreach ($urls as $url) {
            try {
                $response = Http::withToken($token)->timeout(8)->connectTimeout(4)->get($url);
            } catch (Throwable) {
                return ['ok' => false, 'title' => 'API token', 'detail' => 'Cloudflare could not be reached to ask.'];
            }

            if ($response->successful() && $response->json('result.status') === 'active') {
                return ['ok' => true, 'title' => 'API token', 'detail' => 'Cloudflare takes it, and it is active. Whether it may deploy shows when it does.'];
            }
        }

        return ['ok' => false, 'title' => 'API token', 'detail' => 'Cloudflare does not take it: it is wrong, expired or rolled since.'];
    }
}
