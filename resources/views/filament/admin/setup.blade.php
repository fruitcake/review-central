@php
    use App\Services\SetupCheck;

    $words = [SetupCheck::OK => 'In place', SetupCheck::NOTE => 'To know', SetupCheck::WRONG => 'To fix'];
    $tones = [SetupCheck::OK => 'approved', SetupCheck::NOTE => 'neutral', SetupCheck::WRONG => 'danger'];
@endphp

<x-filament-panels::page>
    <div class="rc-stack" wire:poll.15s>
        @foreach ($groups as $group)
            <section class="rc-card rc-pad" aria-labelledby="rc-setup-{{ $loop->index }}">
                <h2 id="rc-setup-{{ $loop->index }}">{{ $group['title'] }}</h2>
                <div class="rc-advice">
                    @foreach ($group['checks'] as $check)
                        <div class="rc-advice-item">
                            <span class="rc-chip rc-chip-{{ $tones[$check['state']] }}">{{ $words[$check['state']] }}</span>
                            <div>
                                <strong>{{ $check['title'] }}</strong>
                                <span class="rc-muted">{{ $check['detail'] }}</span>
                                @if ($check['fix'] !== null)
                                    <div class="rc-setup-fix">{!! Str::markdown($check['fix'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
