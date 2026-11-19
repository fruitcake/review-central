<ul class="rc-readiness">
    @foreach ($checks as $check)
        <li>
            <span class="rc-mark rc-mark-{{ $check['state'] }}" aria-hidden="true">{{ ['ok' => '✓', 'problem' => '!', 'unknown' => '…'][$check['state']] }}</span>
            <span>
                {{-- The mark is a shape and a colour; this says it in words to whoever cannot see either. --}}
                <span class="rc-sr">{{ ['ok' => 'In order:', 'problem' => 'Problem:', 'unknown' => 'Not confirmed:'][$check['state']] }}</span>
                <strong>{{ $check['label'] }}</strong>
                <span class="rc-muted">{{ $check['note'] }}</span>
            </span>
        </li>
    @endforeach
</ul>
