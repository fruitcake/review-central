<?php

declare(strict_types=1);

namespace App\Connections;

use App\Filament\CurrentTenant;
use App\Models\Connection;
use App\Models\IntegrationApp;
use App\Models\Tenant;

/**
 * The apps a person signs in at a service through, instead of a pasted token:
 * kept as a list, each the platform's (for every tenant, or for those listed) or
 * one tenant's own. Signing in with Google is for the whole installation, and
 * there is one; the GitHub App, for now, too. Of Asana's and Atlassian's there
 * can be several. What .env sets is an app as well, for every tenant.
 */
final class Integrations
{
    /** What a connection's driver signs in through, by the name it gives. */
    public const ASANA = 'asana';

    public const JIRA = 'jira';

    public const GITHUB_APP = 'github_app';

    /** The type of app each of those names signs in through. */
    public const TYPE_OF = [self::ASANA => 'asana', self::JIRA => 'atlassian', self::GITHUB_APP => 'github_app'];

    /**
     * The types of app: what each is for, whether there can be one or several,
     * whether a tenant may add its own, and what it is made of, with where .env
     * sets it.
     *
     * @var array<string, array{label: string, single: bool, tenants_add: bool, service: string, fields: array<string, array{label: string, secret: bool, env: string}>}>
     */
    public const TYPES = [
        'google' => ['label' => 'Google: signing in', 'single' => true, 'tenants_add' => false, 'service' => 'Google', 'fields' => [
            'client_id' => ['label' => 'Client ID', 'secret' => false, 'env' => 'services.google.client_id'],
            'client_secret' => ['label' => 'Client secret', 'secret' => true, 'env' => 'services.google.client_secret'],
        ]],
        'bitbucket' => ['label' => 'Bitbucket: act as yourself', 'single' => true, 'tenants_add' => false, 'service' => 'Bitbucket', 'fields' => [
            'client_id' => ['label' => 'Client ID', 'secret' => false, 'env' => 'services.bitbucket.client_id'],
            'client_secret' => ['label' => 'Secret', 'secret' => true, 'env' => 'services.bitbucket.client_secret'],
        ]],
        'asana' => ['label' => 'Asana: connect by signing in', 'single' => false, 'tenants_add' => true, 'service' => 'Asana', 'fields' => [
            'client_id' => ['label' => 'Client ID', 'secret' => false, 'env' => 'services.asana.client_id'],
            'client_secret' => ['label' => 'Client secret', 'secret' => true, 'env' => 'services.asana.client_secret'],
        ]],
        'atlassian' => ['label' => 'Atlassian: connect Jira by signing in', 'single' => false, 'tenants_add' => true, 'service' => 'Atlassian', 'fields' => [
            'client_id' => ['label' => 'Client ID', 'secret' => false, 'env' => 'services.atlassian.client_id'],
            'client_secret' => ['label' => 'Client secret', 'secret' => true, 'env' => 'services.atlassian.client_secret'],
        ]],
        'github_app' => ['label' => 'GitHub App: review, sign in and reply as yourself', 'single' => true, 'tenants_add' => false, 'service' => 'GitHub', 'fields' => [
            'app_id' => ['label' => 'App ID', 'secret' => false, 'env' => 'services.github_app.id'],
            'slug' => ['label' => 'Name in its address', 'secret' => false, 'env' => 'services.github_app.slug'],
            'client_id' => ['label' => 'Client ID', 'secret' => false, 'env' => 'services.github_app.client_id'],
            'client_secret' => ['label' => 'Client secret', 'secret' => true, 'env' => 'services.github_app.client_secret'],
            'webhook_secret' => ['label' => 'Webhook secret', 'secret' => true, 'env' => 'services.github_app.webhook_secret'],
            'private_key' => ['label' => 'Private key', 'secret' => true, 'env' => 'services.github_app.private_key'],
        ]],
    ];

    /**
     * Types whose app is for the whole installation, never for some tenants only:
     * signing in comes before a tenant is known, and a user's own Bitbucket
     * account is theirs, not a tenant's.
     *
     * @var array<int, string>
     */
    public const FOR_EVERY_TENANT = ['google', 'bitbucket'];

    /**
     * The single types whose app is read from the configuration: Google's and
     * Bitbucket's through Socialite, the GitHub App's by GitHubApp.
     *
     * @var array<int, string>
     */
    protected const APPLIED = ['google', 'bitbucket', 'github_app'];

    /**
     * The configuration this put in from a single app of the list, where .env left
     * it empty, so a later change replaces it and what .env sets is told apart.
     *
     * @var array<string, string>
     */
    protected static array $applied = [];

    /**
     * Puts the one app of each single type into the configuration, where .env
     * leaves it empty: what signing in with Google, a user's own Bitbucket
     * account and the GitHub App read.
     */
    public static function apply(): void
    {
        if (app()->runningConsoleCommand('config:cache', 'optimize')) {
            return;
        }

        $singles = rescue(fn () => IntegrationApp::query()->whereNull('tenant_id')->whereIn('type', self::APPLIED)->get()->keyBy('type'), collect(), false);

        foreach (self::APPLIED as $type) {
            $app = $singles->get($type);

            foreach (self::TYPES[$type]['fields'] as $field => ['env' => $key]) {
                $value = $app?->credential($field);
                $ours = blank(config($key)) || (array_key_exists($key, self::$applied) && self::$applied[$key] === config($key));

                if (! $ours || config($key) === $value) {
                    continue;
                }

                config([$key => $value]);

                if ($value === null) {
                    unset(self::$applied[$key]);
                } else {
                    self::$applied[$key] = $value;
                }
            }
        }
    }

    /**
     * The app .env sets for a type, when it sets one: for every tenant.
     */
    public static function fromEnv(string $type): ?OAuthApp
    {
        $credentials = [];

        foreach (self::TYPES[$type]['fields'] ?? [] as $field => ['env' => $key]) {
            $value = config($key);

            // What this put there from the list is not .env's.
            if (is_string($value) && $value !== '' && ! (array_key_exists($key, self::$applied) && self::$applied[$key] === $value)) {
                $credentials[$field] = $value;
            }
        }

        return filled($credentials['client_id'] ?? null) && filled($credentials['client_secret'] ?? null)
            ? new OAuthApp(OAuthApp::ENV, 'From .env', $credentials)
            : null;
    }

    /**
     * The apps of a type a tenant may sign in through, by key: .env's first, then
     * the platform's that are for it, then its own. The open tenant's when none
     * is named; without a tenant, every one of the platform's.
     *
     * @return array<string, OAuthApp>
     */
    public static function available(string $type, ?Tenant $tenant = null): array
    {
        $tenant ??= CurrentTenant::find();
        $apps = [];

        if (($env = self::fromEnv($type)) !== null) {
            $apps[$env->key] = $env;

            // Of a single type .env's is the one: the configuration that signing in
            // with Google and the GitHub App read is its, whatever the list holds.
            if (self::TYPES[$type]['single']) {
                return $apps;
            }
        }

        $query = IntegrationApp::query()->where('type', $type)->orderByRaw('tenant_id is not null')->orderBy('name');
        $listed = rescue(fn () => ($tenant === null ? $query->whereNull('tenant_id') : $query->for($tenant))->get(), collect(), false);

        foreach ($listed as $app) {
            $apps[(string) $app->getKey()] = new OAuthApp((string) $app->getKey(), $app->name, $app->credentials);
        }

        // Only one that has what it needs: a GitHub App also its ID, name and key.
        $needs = $type === 'github_app' ? ['client_id', 'client_secret', 'app_id', 'slug', 'private_key'] : ['client_id', 'client_secret'];

        return array_filter($apps, fn (OAuthApp $app): bool => collect($needs)->every(fn (string $field): bool => filled($app->credentials[$field] ?? null)));
    }

    /**
     * Whether a tenant's connection may sign in through the driver's service.
     */
    public static function offered(ConnectsWithOAuth $driver, ?Tenant $tenant = null): bool
    {
        return self::available(self::TYPE_OF[$driver->oauthIntegration()], $tenant) !== [];
    }

    /**
     * Whether any app of that service is set up at all, for any tenant.
     */
    public static function configured(string $name): bool
    {
        $type = self::TYPE_OF[$name] ?? $name;

        return self::fromEnv($type) !== null
            || rescue(fn (): bool => IntegrationApp::query()->where('type', $type)->exists(), false, false);
    }

    /**
     * Why a tenant's connection cannot sign in through the driver's service, in a
     * sentence, or null when it can.
     */
    public static function unavailable(ConnectsWithOAuth $driver, string $service, ?Tenant $tenant = null): ?string
    {
        return match (true) {
            self::offered($driver, $tenant) => null,
            ! self::configured($driver->oauthIntegration()) => "Signing in at {$service} is not set up; an administrator adds an app for it under Apps, or this team adds its own.",
            default => "Signing in at {$service} is not on for this team; an administrator makes an app available to it under Apps, or this team adds its own.",
        };
    }

    /**
     * The app a connection signs in through: the one it was connected with while
     * that is still there for its tenant, else the first there is.
     */
    public static function forConnection(Connection $connection, ConnectsWithOAuth $driver): ?OAuthApp
    {
        $apps = self::available(self::TYPE_OF[$driver->oauthIntegration()], $connection->tenant);

        return $apps[(string) $connection->setting('oauth_app')] ?? (reset($apps) ?: null);
    }
}
