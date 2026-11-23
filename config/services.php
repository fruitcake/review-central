<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Signing in with Google: an OAuth client from the Google Cloud console, with
    // <APP_URL>/sign-in/google/callback as its redirect address. Without both
    // values the sign-in page has no such button.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => '/sign-in/google/callback',
    ],

    // Acting on Bitbucket as yourself: an OAuth consumer of a Bitbucket workspace,
    // with <APP_URL>/connections/bitbucket/callback as its callback URL. Without
    // both values the profile offers no Bitbucket account to connect.
    'bitbucket' => [
        'client_id' => env('BITBUCKET_CLIENT_ID'),
        'client_secret' => env('BITBUCKET_CLIENT_SECRET'),
        'redirect' => '/connections/bitbucket/callback',
    ],

    // Connecting Asana and Jira by signing in there instead of pasting a token:
    // this installation registered as an OAuth app at each, with
    // <APP_URL>/connections/oauth/callback as its redirect address. Without an
    // id and secret a connection takes a token only.
    'asana' => [
        'client_id' => env('ASANA_CLIENT_ID'),
        'client_secret' => env('ASANA_CLIENT_SECRET'),
        // The scopes the app is registered with, space separated; empty for an
        // app with full permissions.
        'scope' => env('ASANA_OAUTH_SCOPE', 'workspaces:read workspaces.typeahead:read tasks:read stories:read users:read attachments:read'),
    ],

    'atlassian' => [
        'client_id' => env('ATLASSIAN_CLIENT_ID'),
        'client_secret' => env('ATLASSIAN_CLIENT_SECRET'),
    ],

    // Reviewing on GitHub as an app of its own instead of with a token: this
    // installation registered as a GitHub App, which a tenant installs on its
    // organisation. Its callback address is <APP_URL>/connections/oauth/callback,
    // its webhook <APP_URL>/api/webhooks/github-app with this secret. The private
    // key as PEM text, or the path of the file it is in.
    'github_app' => [
        'id' => env('GITHUB_APP_ID'),
        'slug' => env('GITHUB_APP_SLUG'),
        'client_id' => env('GITHUB_APP_CLIENT_ID'),
        'client_secret' => env('GITHUB_APP_CLIENT_SECRET'),
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
        'webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
    ],

];
