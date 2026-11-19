<span class="rc-brand">
    @if ($logo !== null)
        {{-- An uploaded logo, in an image tag: an SVG there runs no script. --}}
        <img class="rc-brand-logo" src="{{ $logo }}" alt="">
    @else
        <span class="rc-brand-mark" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><g class="rc-spin"><path d="M4.5 9.3A8 8 0 0 1 18.2 7"/><path d="M18.7 3.2V7.4h-4.2"/><path d="M19.5 14.7a8 8 0 0 1-13.7 2.3"/><path d="M5.3 20.8v-4.2h4.2"/></g><path d="m8.8 12.2 2.2 2.2 4.2-4.6"/></svg>
        </span>
    @endif
    <span class="rc-brand-text">
        <span class="rc-brand-name">{{ $name }}</span>
        @if ($subtitle !== '')
            <span class="rc-brand-sub">{{ $subtitle }}</span>
        @endif
    </span>
</span>
