<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ config('app.name') }}</title>
    <link rel="icon" href="{{ app(\App\Services\PlatformSettings::class)->favicon() }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <style>
        :root { color-scheme: light dark; --muted: #71717a; --accent: #0d6861; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; line-height: 1.5; margin: 0; padding: 4rem 1rem; }
        main { max-width: 32rem; margin: 0 auto; }
        h1 { font-size: 1.4rem; margin: 0 0 .5rem; }
        .muted { color: var(--muted); font-size: .9rem; }
        button { background: #0f766e; color: #ffffff; border: 0; border-radius: 0; padding: .6rem 1.2rem; font: inherit; font-weight: 600; cursor: pointer; margin-top: 1rem; }
    </style>
</head>
<body>
<main>
    <h1>{{ __('No tenant yet') }}</h1>
    <p>{{ __('You are signed in as :email, but this account has not been added to a tenant. Ask an administrator of this installation to add you to one.', ['email' => $user->email]) }}</p>
    <p class="muted">{!! __('Your name, email address and password are under <a href=":url">your profile</a>.', ['url' => e($profile)]) !!}</p>
    <form method="post" action="{{ $logout }}">
        @csrf
        <button type="submit">{{ __('Sign out') }}</button>
    </form>
</main>
</body>
</html>
