<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Connect {{ $client->name }} · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ app(\App\Services\PlatformSettings::class)->favicon() }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <style>
        :root { color-scheme: light dark; --border: #d4d4d8; --muted: #71717a; --accent: #0d6861; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; line-height: 1.5; margin: 0; padding: 4rem 1rem; }
        main { max-width: 32rem; margin: 0 auto; }
        h1 { font-size: 1.4rem; margin: 0 0 .5rem; }
        .muted { color: var(--muted); font-size: .9rem; }
        .destination { border-left: 4px solid var(--accent); padding: .75rem 1rem; background: color-mix(in srgb, var(--accent) 8%, transparent); }
        .destination strong { font-size: 1.1rem; word-break: break-all; }
        ul { padding-left: 1.2rem; }
        .actions { display: flex; gap: .75rem; margin-top: 1.5rem; }
        .actions form { margin: 0; }
        button { border: 0; border-radius: 0; padding: .6rem 1.2rem; font: inherit; font-weight: 600; cursor: pointer; }
        button[disabled] { opacity: .5; cursor: default; }
        .approve { background: #0f766e; color: #ffffff; }
        .deny { background: transparent; color: inherit; border: 1px solid var(--border); }
    </style>
</head>
<body>
<main>
    <h1>Connect {{ $client->name }}</h1>
    @php($destination = \App\Support\OAuthRedirect::destination(\App\Support\OAuthRedirect::requested($request, $client)))
    <p class="destination">Connecting sends access to <strong>{{ $destination['where'] }}</strong>. Go on only if you started this there yourself: "{{ $client->name }}" is the name the client gave itself, and proves nothing.</p>
    <p>{{ $client->name }} asks to use {{ config('app.name') }} as you, {{ $user->email }}: to read the reviews, findings and rules of your teams' repositories and the tickets their pull requests are for, as your team's tracker connections can read them, and to do what you may do there, such as asking the reviewer, judging a finding or starting a review.</p>

    @if (count($scopes) > 0)
        <ul>
            @foreach ($scopes as $scope)
                <li>{{ $scope->description }}</li>
            @endforeach
        </ul>
    @endif

    <p class="muted">You can connect it again at any time; it gets no more than your own account can do.</p>

    {{-- Both forms carry the same one-time token, so the first press disables the other. --}}
    <div class="actions" id="actions">
        <form method="post" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="approve">Connect</button>
        </form>
        <form method="post" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="deny">Cancel</button>
        </form>
    </div>
</main>
<script>
    document.getElementById('actions').addEventListener('submit', function () {
        setTimeout(function () {
            document.querySelectorAll('#actions button').forEach(function (button) { button.disabled = true; });
        });
    });
</script>
</body>
</html>
