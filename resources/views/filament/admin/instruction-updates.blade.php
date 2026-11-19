{{-- What a release changes in each instruction file: removed lines red, added lines green. --}}
<div class="rc-updates">
    @foreach ($updates as $update)
        <details @if ($loop->first) open @endif class="rc-update">
            <summary>
                <strong>{{ $update['title'] }}</strong>
                <span class="rc-muted">{{ $update['key'] }} · {{ match ($update['state']) { 'new' => 'new', 'outdated' => 'changed in this release', default => 'changed, and edited here' } }}</span>
            </summary>
            <pre class="rc-diff">@foreach ($update['diff'] as $line)<span @class(['rc-diff-add' => $line['type'] === '+', 'rc-diff-del' => $line['type'] === '-', 'rc-diff-gap' => $line['type'] === '@@'])>{{ $line['type'] === '@@' ? '…' : $line['type'].' '.$line['text'] }}
</span>@endforeach</pre>
        </details>
    @endforeach
</div>
