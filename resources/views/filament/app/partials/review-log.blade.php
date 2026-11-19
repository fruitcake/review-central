{{-- The review's log: above the findings while it runs, below them once it is done. --}}
@if ($log !== null)
    <section class="rc-card rc-pad" aria-labelledby="rc-log">
        {{-- Open or closed is the reader's: the page rendering again leaves it as it is, and a
             finished review's log opens as they last left one. A running review's log starts open. --}}
        <details wire:ignore.self @if ($live) open @endif
            x-data="{ follow: true, chosen: false, toEnd() { requestAnimationFrame(() => { this.$refs.log.scrollTop = this.$refs.log.scrollHeight }) } }"
            x-init="if (! $el.open) { try { $el.open = localStorage.getItem('rc-log-open') === '1' } catch (e) {} }"
            x-on:toggle="if ($el.open) { follow = true; toEnd() } if (chosen) { chosen = false; try { localStorage.setItem('rc-log-open', $el.open ? '1' : '0') } catch (e) {} }"
        >
            <summary x-on:click="chosen = true"><h2 id="rc-log" style="display: inline">Log</h2> <span class="rc-muted">{{ $live ? 'follows the review as it runs' : 'what the review printed' }}{{ $log['truncated'] ? ' · the end of it' : '' }}</span></summary>
            {{-- Stays at the end while new lines come in, unless someone scrolled up to read. --}}
            <pre class="rc-log" tabindex="0" aria-label="Log of this review" x-ref="log"
                x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => { if (follow) $el.scrollTop = $el.scrollHeight }).observe($el, { childList: true, characterData: true, subtree: true })"
                x-on:scroll="follow = $el.scrollTop + $el.clientHeight >= $el.scrollHeight - 24"
            >{{ $log['text'] === '' ? 'Nothing printed yet.' : $log['text'] }}</pre>
        </details>
    </section>
@endif
