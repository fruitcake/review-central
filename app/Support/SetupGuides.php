<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How to get what the installation itself is set up with, rather than a tenant's
 * connection: the Cloudflare token and the bucket's keys under Settings, and the
 * apps at Google, Asana, Atlassian and GitHub that the Setup page says are off.
 * Each says the same as the guide in docs/guide, next to where it is needed.
 */
class SetupGuides
{
    public const CLOUDFLARE_TOKEN = 'cloudflare-token';

    public const R2_KEYS = 'r2-keys';

    public const GOOGLE = 'google';

    public const ASANA = 'asana';

    public const ATLASSIAN = 'atlassian';

    public const GITHUB_APP = 'github-app';

    /**
     * A guide by its name, for a page that names it in a check or a link.
     */
    public function named(string $name): ?SetupGuide
    {
        return match ($name) {
            self::CLOUDFLARE_TOKEN => $this->cloudflareToken(),
            self::R2_KEYS => $this->r2Keys(),
            self::GOOGLE => $this->google(),
            self::ASANA => $this->asana(),
            self::ATLASSIAN => $this->atlassian(),
            self::GITHUB_APP => $this->githubApp(),
            default => null,
        };
    }

    /**
     * What `wrangler deploy` needs: the Worker's script, its container and the
     * bucket, and the account's settings to find them.
     */
    public function cloudflareToken(): SetupGuide
    {
        return new SetupGuide(
            title: 'A Cloudflare API token to deploy the Worker',
            url: 'https://dash.cloudflare.com/profile/api-tokens',
            linkLabel: 'API tokens in the Cloudflare dashboard',
            steps: [
                'Open the link and choose **Create Token**, then **Create Custom Token**.',
                'Name it after this installation, and add the permissions below, all for the account.',
                'Under **Account Resources**, include the account the Worker goes on.',
                'Create the token, copy it and paste it here. The account ID is on the account\'s overview in the dashboard, under **API**.',
            ],
            permissions: [
                'Account → Workers Scripts: Edit' => 'deploys the Worker.',
                'Account → Containers: Edit' => 'deploys the sandbox\'s container with it.',
                'Account → Workers R2 Storage: Edit' => 'makes the bucket when it is not there, and binds it to the Worker.',
                'Account → Account Settings: Read' => 'Wrangler reads the account it deploys to.',
            ],
            notes: [
                'Used only to deploy; reviews never use it. The account needs the Workers Paid plan for containers.',
            ],
        );
    }

    /**
     * The bucket is reached as S3 is, with a key pair of its own that may only
     * read and write its objects.
     */
    public function r2Keys(): SetupGuide
    {
        return new SetupGuide(
            title: 'Keys for the R2 bucket',
            url: 'https://dash.cloudflare.com/?to=/:account/r2/api-tokens',
            linkLabel: 'R2 API tokens in the Cloudflare dashboard',
            steps: [
                'Open the link (or **R2 → Manage API tokens** in the dashboard) and choose **Create API token**.',
                'Give it **Object Read & Write**, and under **Specify bucket(s)** only the review bucket, `review-central` unless you named another.',
                'Create it. Cloudflare shows an **Access Key ID** (32 characters) and a **Secret Access Key** (64 characters) once: paste those two here. Not the token value above them.',
            ],
            permissions: [
                'Object Read & Write, on the bucket only' => 'the archives of a checkout and the reviewer\'s home go up, and come back, through the bucket. In a custom token: Workers R2 Storage Bucket Item, Read and Edit.',
            ],
            notes: [
                'The S3 endpoint is `https://<account id>.r2.cloudflarestorage.com`; a deploy from here works it out from the account ID.',
            ],
        );
    }

    /**
     * Socialite asks Google for the person's address and name, the scopes any
     * sign-in has, so the consent screen needs no other.
     */
    public function google(): SetupGuide
    {
        return new SetupGuide(
            title: 'A Google OAuth client to sign in with',
            url: 'https://console.cloud.google.com/apis/credentials',
            linkLabel: 'Credentials in the Google Cloud console',
            steps: [
                'In the Google Cloud console, pick or create a project for this installation, and set up its consent screen (**Google Auth Platform → Branding**) when it asks: the app\'s name and a support address.',
                'Open the link and choose **Create credentials → OAuth client ID**, of the type **Web application**.',
                'Add `'.url('/sign-in/google/callback').'` under **Authorised redirect URIs**, and create it.',
                'Fill in the client\'s ID and secret under **Settings → Integrations**, or set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in `.env`, which goes first.',
            ],
            permissions: [
                'openid, email, profile' => 'who signs in: their address, verified by Google, and their name. Nothing else is asked.',
            ],
            notes: [
                'Someone new joins a tenant only when the tenant lists their organisation\'s domain under **Sign-in domains**; a Gmail address never joins one.',
            ],
        );
    }

    /**
     * An app the connections sign in at Asana through, instead of a pasted token.
     */
    public function asana(): SetupGuide
    {
        return new SetupGuide(
            title: 'An Asana app to connect by signing in',
            url: 'https://app.asana.com/0/my-apps',
            linkLabel: 'Asana developer console',
            steps: [
                'Open the link and choose **Create new app**.',
                'Under **OAuth**, add `'.url('/connections/oauth/callback').'` as a redirect URL.',
                'Fill in the app\'s client ID and secret under **Settings → Integrations**, or set `ASANA_CLIENT_ID` and `ASANA_CLIENT_SECRET` in `.env`, which goes first.',
                'Under **Manage distribution**, let the app be used in any workspace, so tenants outside your own organisation can connect.',
                'Give the app the scopes below, which is what `ASANA_OAUTH_SCOPE` asks for unless it is set. For an app with full permissions instead, set `ASANA_OAUTH_SCOPE` empty: Asana refuses scopes such an app does not name.',
            ],
            permissions: [
                'tasks:read' => 'reads a task, its subtasks, and the workspace\'s search for a task\'s number.',
                'stories:read' => 'reads the comments on a task.',
                'workspaces:read' => 'checks the workspace, which is what **Test** asks.',
                'workspaces.typeahead:read' => 'finds a task by the number its name starts with.',
                'users:read' => 'reads who a task is assigned to and who wrote a comment.',
            ],
        );
    }

    /**
     * Read access, and a refresh token: JiraDriver asks for exactly these.
     */
    public function atlassian(): SetupGuide
    {
        return new SetupGuide(
            title: 'An Atlassian app to connect Jira by signing in',
            url: 'https://developer.atlassian.com/console/myapps/',
            linkLabel: 'Atlassian developer console',
            steps: [
                'Open the link and choose **Create → OAuth 2.0 integration**.',
                'Under **Permissions**, add the Jira API, and its classic scopes below.',
                'Under **Authorization**, add `'.url('/connections/oauth/callback').'` as the callback URL.',
                'Under the app\'s **Settings**, copy the client ID and secret into **Settings → Integrations** here, or into `ATLASSIAN_CLIENT_ID` and `ATLASSIAN_CLIENT_SECRET` in `.env`, which goes first.',
                'Under **Distribution**, set it to **Sharing**, so people of other organisations can authorize it, not only you.',
            ],
            permissions: [
                'read:jira-work' => 'reads issues, their subtasks and comments.',
                'read:jira-user' => 'reads who an issue is assigned to, and who signed in.',
                'offline_access' => 'a refresh token, to keep reading without signing in again. Atlassian adds it when it is asked for.',
            ],
            notes: [
                'An app that is not shared stays usable by its own developer only: under **Distribution**, share it for anyone else to connect.',
            ],
        );
    }

    /**
     * Registering the installation as a GitHub App: the permissions are what
     * GitHubClient and GitHubApp call as the app.
     */
    public function githubApp(): SetupGuide
    {
        return new SetupGuide(
            title: 'Register this installation as a GitHub App',
            url: 'https://github.com/settings/apps/new',
            linkLabel: 'New GitHub App on GitHub',
            steps: [
                'Open the link, or the organisation\'s **Settings → Developer settings → GitHub Apps → New GitHub App** to have the organisation own it.',
                'Name the app; its comments show as `<name>[bot]`. Homepage URL: `'.url('/').'`.',
                'Callback URL: `'.url('/connections/oauth/callback').'`. Leave **Request user authorization (OAuth) during installation** off.',
                'Webhook URL: `'.url('/api/webhooks/github-app').'`, with a long random secret of your own.',
                'Set the permissions below, subscribe to the events **Pull request**, **Issue comment**, **Pull request review comment**, **Pull request review** and **Check run**, and under **Where can this GitHub App be installed?** pick **Any account** for tenants outside your organisation. No verification is needed for that; GitHub asks for it only for a paid listing in the Marketplace.',
                'Create it, then **Generate a private key** and generate a client secret. Fill in the app ID, the name in its address, the client ID and secret, the webhook secret and the contents of the private key under **Settings → Integrations**; or set `GITHUB_APP_ID`, `GITHUB_APP_SLUG`, `GITHUB_APP_CLIENT_ID`, `GITHUB_APP_CLIENT_SECRET`, `GITHUB_APP_PRIVATE_KEY` (the PEM file\'s path, or its contents) and `GITHUB_APP_WEBHOOK_SECRET` in `.env`, which goes first.',
            ],
            permissions: [
                'Repository → Contents: Read' => 'clones the repository and reads its files.',
                'Repository → Metadata: Read' => 'GitHub requires it.',
                'Repository → Pull requests: Read and write' => 'reads the pull request, comments on its lines, approves or requests changes.',
                'Repository → Issues: Read and write' => 'comments on the pull request\'s conversation.',
                'Repository → Commit statuses: Read and write' => 'the status on the commit.',
                'Repository → Checks: Read and write' => 'the "AI Review" check with its annotations and its button.',
                'Organization → Members: Read' => 'checks that whoever connects an installation owns the organisation.',
            ],
            notes: [
                'Until all but the webhook secret are set, *GitHub App* is not offered as a connection.',
            ],
        );
    }
}
