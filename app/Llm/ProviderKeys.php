<?php

declare(strict_types=1);

namespace App\Llm;

use RuntimeException;

/**
 * The AI providers' keys as the reviewers read them from their environment, and
 * the host each one is sent to. A sandbox that can keep them out of its reach
 * gets placeholders instead, and swaps the keys in on the way to those hosts.
 */
final class ProviderKeys
{
    /**
     * Each key's variable and the host its provider's API is on.
     *
     * @var array<string, string>
     */
    public const HOSTS = [
        'ANTHROPIC_API_KEY' => 'api.anthropic.com',
        'OPENAI_API_KEY' => 'api.openai.com',
        'CODEX_API_KEY' => 'api.openai.com',
        'GEMINI_API_KEY' => 'generativelanguage.googleapis.com',
        'DEEPSEEK_API_KEY' => 'api.deepseek.com',
        'GROQ_API_KEY' => 'api.groq.com',
        'MISTRAL_API_KEY' => 'api.mistral.ai',
        'OPENROUTER_API_KEY' => 'openrouter.ai',
        'XAI_API_KEY' => 'api.x.ai',
    ];

    /**
     * Other addresses a key goes to, each only under these paths (one ending in / is
     * everything under it): the Codex CLI reads from the ChatGPT backend with the
     * API key, such as its list of models, and chatgpt.com is a site as much as an
     * API, so it gets the key for its backend's API alone.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const PATHS = [
        'OPENAI_API_KEY' => ['chatgpt.com' => ['/backend-api/']],
        'CODEX_API_KEY' => ['chatgpt.com' => ['/backend-api/']],
    ];

    /**
     * The variable that sends a key elsewhere, through a gateway, instead.
     *
     * @var array<string, string>
     */
    public const BASE_URLS = [
        'ANTHROPIC_API_KEY' => 'ANTHROPIC_BASE_URL',
        'OPENAI_API_KEY' => 'OPENAI_BASE_URL',
        'CODEX_API_KEY' => 'OPENAI_BASE_URL',
    ];

    /**
     * The environment with every key it holds replaced by a placeholder, and what
     * puts each back: the key, its placeholder and its host. There is no key that
     * goes into the sandbox after all: one for a gateway over plain HTTP, which the
     * Worker would send on unencrypted, stops the run instead.
     *
     * @param  array<string, string>  $environment
     * @param  string|null  $stableFor  the name of a warm sandbox, whose placeholders stay the same for the same keys
     * @return array{environment: array<string, string>, credentials: list<array{host: string, placeholder: string, value: string, paths?: list<string>}>}
     *
     * @throws RuntimeException
     */
    public static function proxied(array $environment, ?string $stableFor = null): array
    {
        $credentials = [];

        foreach (self::HOSTS as $variable => $host) {
            $value = $environment[$variable] ?? '';

            if ($value === '') {
                continue;
            }

            $baseVariable = self::BASE_URLS[$variable] ?? null;
            $base = $baseVariable === null ? '' : ($environment[$baseVariable] ?? '');

            if ($base !== '') {
                if (parse_url($base, PHP_URL_SCHEME) !== 'https' || ! is_string($gateway = parse_url($base, PHP_URL_HOST))) {
                    throw new RuntimeException("{$baseVariable} is {$base}: a key goes to a gateway over HTTPS only. Change the AI provider's base URL to an https:// address.");
                }

                // The Worker takes a sandbox's HTTPS on its own port alone.
                if (! in_array(parse_url($base, PHP_URL_PORT), [null, 443], true)) {
                    throw new RuntimeException("{$baseVariable} is {$base}: in a Cloudflare sandbox a key goes to a gateway on port 443 only. Serve the gateway there, or set REVIEW_CLOUDFLARE_PROXY_KEYS=false to give the sandbox the key itself.");
                }

                $host = strtolower($gateway);
            }

            $placeholder = self::placeholder($value, $stableFor);
            $environment[$variable] = $placeholder;
            $credentials[] = ['host' => $host, 'placeholder' => $placeholder, 'value' => $value];

            // Not where the key goes through a gateway: there it is the gateway's to send on.
            if ($base === '') {
                foreach (self::PATHS[$variable] ?? [] as $other => $paths) {
                    $credentials[] = ['host' => $other, 'placeholder' => $placeholder, 'value' => $value, 'paths' => $paths];
                }
            }
        }

        return ['environment' => $environment, 'credentials' => $credentials];
    }

    /**
     * Random, and shaped like the key where a CLI looks at its start: the
     * provider's prefix ("sk-ant-api03-", "sk-proj-", "gsk_"), never more of it.
     *
     * For a sandbox kept warm, the same for the same key under the same name, so
     * the Worker can tell the next run brings the same keys and keep what it
     * set up for them; a key that changed gives another one, and a fresh sandbox.
     */
    protected static function placeholder(string $key, ?string $stableFor = null): string
    {
        $prefix = preg_match('/^[A-Za-z]{2,5}[-_](?:[A-Za-z0-9]{2,6}[-_]){0,2}/', $key, $matches) === 1 ? $matches[0] : '';
        $unique = $stableFor === null ? bin2hex(random_bytes(20)) : substr(hash_hmac('sha256', $key, config('app.key').'|warm|'.$stableFor), 0, 40);

        return $prefix.'placeholder-'.$unique;
    }
}
