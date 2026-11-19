{{-- How long a run that is still going has taken so far, counting up in the browser
     from what the server saw, as RunPresenter::duration() writes it once it is done.
     Left alone by Livewire's polling, which would otherwise set it back a second. --}}
@php($elapsed = max(0, (int) $since->diffInSeconds(now())))
<span
    wire:ignore
    x-data="{
        seconds: {{ $elapsed }},
        timer: null,
        init() {
            const from = Date.now() - this.seconds * 1000;
            this.timer = setInterval(() => { this.seconds = Math.floor((Date.now() - from) / 1000) }, 1000);
        },
        destroy() { clearInterval(this.timer) },
        get text() {
            const s = this.seconds, two = (n) => String(n).padStart(2, '0');
            return s >= 3600
                ? `${Math.floor(s / 3600)}:${two(Math.floor(s % 3600 / 60))}:${two(s % 60)}`
                : `${two(Math.floor(s / 60))}:${two(s % 60)}`;
        },
    }"
    x-text="text"
>{{ \App\Filament\RunPresenter::duration($elapsed) }}</span>
