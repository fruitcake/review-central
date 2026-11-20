@php
    use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
    use App\Filament\LocalTime;
    use App\Filament\RunPresenter;
    use App\SourceControl\Reviewers;

    /** @var \App\Models\OpenPullRequest $record */
    /** @var \App\Services\PullRequestTurn $turn */
    $reviewerState = fn (?string $state): array => match ($state) {
        Reviewers::APPROVED => ['Approved', 'approved'],
        Reviewers::CHANGES_REQUESTED => ['Changes requested', 'changes'],
        default => ['Not yet', 'neutral'],
    };
@endphp

<div class="rc-stack">
    <div class="rc-meta">
        <span class="rc-chip rc-chip-{{ $standing[1] }}">{{ $standing[0] }}</span>
        @if ($url !== null)
            <a href="{{ $url }}" target="_blank" rel="noopener">Open on the pull request ↗</a>
        @endif
    </div>

    <section class="rc-attention" aria-labelledby="rc-turn">
        <div class="rc-attention-head">
            <span id="rc-turn" class="rc-eyebrow">Whose turn</span>
            <strong>{{ $turn->who() }}</strong>
            @if ($mine) <span class="rc-chip rc-chip-primary">You</span> @endif
        </div>
        @if ($note !== null)
            <p>{{ $note['next_step'] }}</p>
        @else
            <p><strong>{{ $turn->action }}.</strong> {{ $turn->reason }}</p>
        @endif
    </section>

    <dl class="rc-details">
        <dt>Author</dt><dd>{{ $record->author ?? 'Someone' }}</dd>
        <dt>Reviewers</dt>
        <dd>
            @forelse ($record->reviewers ?? [] as $reviewer)
                @php([$label, $tone] = $reviewerState($reviewer['state']))
                <span class="rc-turn-reviewer">{{ $reviewer['name'] ?? 'Someone' }} <span class="rc-chip rc-chip-{{ $tone }}">{{ $label }}</span></span>
            @empty
                <span class="rc-tone-danger">Nobody yet</span>
            @endforelse
        </dd>
        <dt>Open since</dt><dd>{{ $record->opened_at === null ? '—' : LocalTime::of($record->opened_at)->format('j M Y') }}</dd>
        <dt>Branch</dt><dd><span class="rc-mono">{{ $record->branch ?? '—' }}</span>@if ($record->destination_branch) → <span class="rc-mono">{{ $record->destination_branch }}</span>@endif</dd>
        @if ($record->head_since)
            <dt>Newest commits</dt><dd>{{ LocalTime::of($record->head_since)->format('j M H:i') }} · <span class="rc-mono">{{ substr((string) $record->commit, 0, 7) }}</span></dd>
        @endif
    </dl>

    @if (filled($note['summary'] ?? null))
        <section class="rc-prose" aria-label="Summary">
            <p>{{ $note['summary'] }}</p>
        </section>
    @endif

    @if ($run !== null && filled($run->summary))
        <section class="rc-note rc-turn-review" aria-labelledby="rc-ai-review">
            <strong id="rc-ai-review">AI review of {{ substr($run->commit, 0, 7) }}</strong>
            <span class="rc-chip rc-chip-{{ RunPresenter::outcome($run)['tone'] }}">{{ RunPresenter::outcome($run)['label'] }}</span>
            <p>{{ \Illuminate\Support\Str::limit($run->summary, 600) }}</p>
            <a href="{{ ReviewRunResource::getUrl('view', ['record' => $run]) }}">The whole review</a>
        </section>
    @endif

    <details class="rc-turn-history" @if ($history->count() <= 6) open @endif>
        <summary><strong>History ({{ $history->count() }} {{ $history->count() === 1 ? 'step' : 'steps' }})</strong></summary>
        <ol>
            @foreach ($history as $entry)
                <li>
                    <span class="rc-muted">{{ LocalTime::of($entry['at'])->format('j M H:i') }}</span>
                    @if ($entry['url'] !== null)
                        <a href="{{ $entry['url'] }}">{{ $entry['text'] }}</a>
                    @else
                        <span>{{ $entry['text'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
        @if ($history->isEmpty())
            <p class="rc-muted">Nothing yet: the history starts with the next delivery from the host.</p>
        @endif
    </details>
</div>
