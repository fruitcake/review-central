<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Rate this review</title>
    <link rel="icon" href="{{ app(\App\Services\PlatformSettings::class)->favicon() }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <style>
        :root { color-scheme: light dark; --border: #d4d4d8; --muted: #71717a; --accent: #0d6861; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; line-height: 1.5; margin: 0; padding: 2rem 1rem; }
        main { max-width: 42rem; margin: 0 auto; }
        h1 { font-size: 1.4rem; margin: 0 0 .25rem; }
        .muted { color: var(--muted); font-size: .9rem; }
        fieldset { border: 1px solid var(--border); border-radius: 0; margin: 1.25rem 0; padding: 1rem; }
        legend { font-weight: 600; padding: 0 .4rem; }
        label { display: inline-flex; align-items: center; gap: .35rem; margin-right: 1rem; }
        .finding { border-top: 1px solid var(--border); padding: .6rem 0; }
        .finding:first-of-type { border-top: 0; }
        input[type=text], textarea { width: 100%; box-sizing: border-box; padding: .5rem; border: 1px solid var(--border); border-radius: 0; font: inherit; }
        button { background: #0f766e; color: #ffffff; border: 0; border-radius: 0; padding: .6rem 1.2rem; font: inherit; font-weight: 600; cursor: pointer; }
        .notice { border: 1px solid var(--accent); border-radius: 0; padding: .75rem 1rem; margin: 1rem 0; }
        .error { color: #dc2626; font-size: .9rem; }
    </style>
</head>
<body>
<main>
    <h1>Rate this review</h1>
    <p class="muted">
        {{ $run->workspace }}/{{ $run->slug }}
        @if ($run->pull_request_id) · pull request #{{ $run->pull_request_id }} @endif
        · {{ substr($run->commit, 0, 10) }}
        @if ($run->finished_at) · {{ $run->finished_at->toDayDateTimeString() }} @endif
    </p>

    @if ($saved)
        <div class="notice">Thanks, your rating is saved.</div>
    @endif

    @if ($run->summary)
        <div>{!! \Illuminate\Support\Str::markdown($run->summary, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
    @endif

    <form method="post" action="{{ route('reviews.rate.store', ['token' => $run->rating_token]) }}">
        @csrf

        <fieldset>
            <legend>How useful was this review?</legend>
            @foreach ([1 => 'Useless', 2 => 'Mostly noise', 3 => 'Mixed', 4 => 'Useful', 5 => 'Spot on'] as $score => $label)
                <label><input type="radio" name="score" value="{{ $score }}" required @checked((int) old('score') === $score)> {{ $score }} · {{ $label }}</label>
            @endforeach
            @error('score') <p class="error">{{ $message }}</p> @enderror
        </fieldset>

        @if (filled($run->findings))
            <fieldset>
                <legend>Were the findings fair?</legend>
                @foreach ($run->findings as $finding)
                    @continue(!isset($finding['id']))
                    <div class="finding">
                        <div><strong>{{ $finding['id'] }}</strong> {{ $finding['title'] ?? '' }}</div>
                        @if (isset($finding['path']))
                            <div class="muted">{{ $finding['path'] }}@isset($finding['line']):{{ $finding['line'] }}@endisset</div>
                        @endif
                        <label><input type="radio" name="findings[{{ $finding['id'] }}]" value="fair"> Fair</label>
                        <label><input type="radio" name="findings[{{ $finding['id'] }}]" value="unfair"> Not fair</label>
                    </div>
                @endforeach
            </fieldset>
        @endif

        <fieldset>
            <legend>Anything to add?</legend>
            <p><label>Your name (optional)<br><input type="text" name="name" value="{{ old('name') }}" maxlength="255"></label></p>
            <label>What did it miss, or get wrong?<br><textarea name="comment" rows="4">{{ old('comment') }}</textarea></label>
        </fieldset>

        <button type="submit">Save rating</button>
    </form>
</main>
</body>
</html>
