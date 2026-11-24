<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ config('app.name') }}</title>
    <link rel="icon" href="{{ app(\App\Services\PlatformSettings::class)->favicon() }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <style>
        :root { color-scheme: light dark; --muted: #71717a; --accent: #0d6861; --danger: #b91c1c; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; line-height: 1.5; margin: 0; padding: 4rem 1rem; }
        main { max-width: 32rem; margin: 0 auto; }
        h1 { font-size: 1.4rem; margin: 0 0 .5rem; }
        .muted { color: var(--muted); font-size: .9rem; }
        .error { color: var(--danger); }
        label { display: block; font-weight: 600; margin-top: 1rem; }
        input { font: inherit; font-size: 1.4rem; letter-spacing: .3em; padding: .4rem .6rem; width: 10ch; margin-top: .3rem; border: 1px solid var(--muted); border-radius: 0; }
        button { background: #0f766e; color: #ffffff; border: 0; border-radius: 0; padding: .6rem 1.2rem; font: inherit; font-weight: 600; cursor: pointer; margin-top: 1rem; }
    </style>
</head>
<body>
<main>
    <h1>Check your email</h1>
    <p>We sent a code to {{ $email }}. Type it here to show the address is yours; after this, your {{ $name }} account signs you in by itself.</p>
    <form method="post" action="{{ route('sign-in.code.check') }}">
        @csrf
        <label for="code">Code</label>
        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
        @error('code')
            <p class="error" role="alert">{{ $message }}</p>
        @enderror
        <button type="submit">Sign in</button>
    </form>
    <p class="muted">The code works for {{ $minutes }} minutes. No mail? Look in your spam, or <a href="{{ route('sign-in.redirect', ['provider' => $provider]) }}">sign in with {{ $name }} again</a> for a new one.</p>
</main>
</body>
</html>
