<?php

declare(strict_types=1);

namespace App\Connections;

use App\Filament\CurrentTenant;
use App\Models\Tenant;
use App\SourceControl\GitHubApp;

/**
 * The apps this installation is registered as at Asana, Atlassian and GitHub,
 * which a tenant's connections sign in through instead of a pasted token: each
 * set up under Settings, and each on for a tenant unless it is turned off there.
 * Signing in with Google is no part of these: it comes before any tenant.
 */
final class Integrations
{
    public const ASANA = 'asana';

    public const JIRA = 'jira';

    public const GITHUB_APP = 'github_app';

    /** @var array<string, string> */
    public const LABELS = [
        self::ASANA => 'Asana: connect by signing in',
        self::JIRA => 'Jira: connect by signing in',
        self::GITHUB_APP => 'GitHub App: review as the app',
    ];

    /**
     * Whether this installation is registered with that service.
     */
    public static function configured(string $name): bool
    {
        return match ($name) {
            self::ASANA => filled(config('services.asana.client_id')) && filled(config('services.asana.client_secret')),
            self::JIRA => filled(config('services.atlassian.client_id')) && filled(config('services.atlassian.client_secret')),
            self::GITHUB_APP => app(GitHubApp::class)->configured(),
            default => false,
        };
    }

    /**
     * Whether a tenant's connection may sign in through the driver's app: it is
     * set up here, and not turned off for the tenant. The open tenant's when none
     * is named.
     */
    public static function offered(ConnectsWithOAuth $driver, ?Tenant $tenant = null): bool
    {
        $tenant ??= CurrentTenant::find();

        return $driver->oauthConfigured() && ($tenant === null || $tenant->allowsIntegration($driver->oauthIntegration()));
    }

    /**
     * Why a tenant's connection cannot sign in through the driver's app, in a
     * sentence, or null when it can.
     */
    public static function unavailable(ConnectsWithOAuth $driver, string $service, ?Tenant $tenant = null): ?string
    {
        return match (true) {
            self::offered($driver, $tenant) => null,
            ! $driver->oauthConfigured() => "Signing in at {$service} is not set up on this installation; an administrator does that under Settings → Integrations.",
            default => "Signing in at {$service} is not on for this team; an administrator turns it on on the tenant.",
        };
    }
}
