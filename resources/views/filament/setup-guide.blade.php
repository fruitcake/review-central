{{-- How to get a key or token: see App\Support\SetupGuide and App\Filament\Components\SetupGuideAction. --}}
@php
    $inline = fn (string $text) => new \Illuminate\Support\HtmlString(Str::inlineMarkdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
@endphp

<div class="rc-prose rc-guide">
    <p>
        <a href="{{ $guide->url }}" target="_blank" rel="noopener noreferrer">{{ $guide->linkLabel }}</a>
        <span class="rc-muted">{{ $guide->url }}</span>
    </p>

    @if ($guide->steps !== [])
        <h3>Steps</h3>
        <ol>
            @foreach ($guide->steps as $step)
                <li>{{ $inline($step) }}</li>
            @endforeach
        </ol>
    @endif

    @if ($guide->permissions !== [])
        <h3>Scopes and permissions</h3>
        <ul>
            @foreach ($guide->permissions as $permission => $why)
                <li><code>{{ $permission }}</code>: {{ $inline($why) }}</li>
            @endforeach
        </ul>
    @endif

    @foreach ($guide->notes as $note)
        <p class="rc-note">{{ $inline($note) }}</p>
    @endforeach
</div>
