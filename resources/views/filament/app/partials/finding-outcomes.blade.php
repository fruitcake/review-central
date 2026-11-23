{{-- How the period's findings ended, and why people dismissed the ones they did. --}}
@php($fills = ['fixed' => 'rc-fill-primary', 'acknowledged' => 'rc-fill-sky', 'false_positive' => 'rc-fill-danger', 'open' => 'rc-fill-ink', 'outdated' => 'rc-fill-grey', 'unreported' => 'rc-fill-hatch'])
<div class="rc-outcomes">
    <div>
        <h3 id="rc-outcomes">How findings ended</h3>
        <span class="rc-muted">As the reviews of each pull request last reported them, however people answered</span>
    </div>
    @if ($outcomes['total'] === 0)
        <span class="rc-muted">No findings in this period.</span>
    @else
        <div class="rc-bar rc-bar-tall" role="img" aria-label="{{ collect($outcomes['outcomes'])->map(fn (array $outcome): string => "{$outcome['count']} {$outcome['label']}")->implode(', ') }}">
            @foreach ($outcomes['outcomes'] as $outcome)
                @if ($outcome['count'] > 0)
                    <div class="{{ $fills[$outcome['key']] }}" style="width: {{ $outcome['count'] / $outcomes['total'] * 100 }}%" title="{{ $outcome['label'] }}: {{ $outcome['count'] }}"></div>
                @endif
            @endforeach
        </div>
        <div class="rc-legend">
            @foreach ($outcomes['outcomes'] as $outcome)
                <span><i class="{{ $fills[$outcome['key']] }}"></i>{{ $outcome['label'] }} {{ $outcome['count'] }}</span>
            @endforeach
        </div>
    @endif
    @if ($outcomes['reasons'] !== [])
        <div class="rc-legend">
            <span class="rc-muted">Dismissed as</span>
            @foreach ($outcomes['reasons'] as $reason)
                <span>{{ $reason['label'] }} {{ $reason['count'] }}</span>
            @endforeach
        </div>
    @endif
</div>
