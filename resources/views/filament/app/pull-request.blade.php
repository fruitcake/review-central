@php
    use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
    use App\Filament\LocalTime;
    use App\Filament\RunPresenter;
    use App\SourceControl\Reviewers;

    /** @var \App\Models\OpenPullRequest $record */
    /** @var \App\Services\PullRequestTurn $turn */
    $reviewerState = fn (?string $state): array => match ($state) {
        Reviewers::APPROVED => [__('Approved'), 'approved'],
        Reviewers::CHANGES_REQUESTED => [__('Changes requested'), 'changes'],
        default => [__('Not yet'), 'neutral'],
    };
@endphp

<div class="rc-stack">
    <div class="rc-meta">
        <span class="rc-chip rc-chip-{{ $standing[1] }}">{{ $standing[0] }}</span>
        @if ($url !== null)
            <a href="{{ $url }}" target="_blank" rel="noopener">{{ __('Open on the pull request') }} ↗</a>
        @endif
    </div>

    @if ($closed !== null)
        <section class="rc-attention" aria-labelledby="rc-turn">
            <div class="rc-attention-head">
                <span id="rc-turn" class="rc-eyebrow">{{ __('Closed') }}</span>
                <strong>{{ $closed }}</strong>
            </div>
            {{-- A text node: quotes need no escaping, and stay as typed. --}}
            <p>{{ __('Nobody\'s turn any more.') }}</p>
        </section>
    @else
        <section class="rc-attention" aria-labelledby="rc-turn">
            <div class="rc-attention-head">
                <span id="rc-turn" class="rc-eyebrow">{{ __('Whose turn') }}</span>
                <strong>{{ $turn->who() }}</strong>
                @if ($mine) <span class="rc-chip rc-chip-primary">{{ __('You') }}</span> @endif
            </div>
            @if ($note !== null)
                <p>{{ $note['next_step'] }}</p>
            @else
                <p><strong>{{ $turn->action }}.</strong> {{ $turn->reason }}</p>
            @endif
        </section>
    @endif

    <dl class="rc-details">
        <dt>{{ __('Author') }}</dt><dd>{{ $record->author ?? __('Someone') }}</dd>
        <dt>{{ __('Reviewers') }}</dt>
        <dd>
            @forelse ($record->reviewers ?? [] as $reviewer)
                @php([$label, $tone] = $reviewerState($reviewer['state']))
                <span class="rc-turn-reviewer">{{ $reviewer['name'] ?? __('Someone') }} <span class="rc-chip rc-chip-{{ $tone }}">{{ $label }}</span></span>
            @empty
                <span class="rc-tone-danger">{{ __('Nobody yet') }}</span>
            @endforelse
        </dd>
        <dt>{{ __('Open since') }}</dt><dd>{{ $record->opened_at === null ? '—' : LocalTime::of($record->opened_at)->translatedFormat('j M Y') }}</dd>
        <dt>{{ __('Branch') }}</dt><dd><span class="rc-mono">{{ $record->branch ?? '—' }}</span>@if ($record->destination_branch) → <span class="rc-mono">{{ $record->destination_branch }}</span>@endif</dd>
        @if ($ticket !== null)
            <dt>{{ __('Ticket') }}</dt>
            <dd>
                {{-- Only a web address becomes a link: a ticket's address comes from whatever its tracker answered. --}}
                @if (is_string($ticket['url'] ?? null) && preg_match('~^https?://~i', $ticket['url']) === 1)
                    <a href="{{ $ticket['url'] }}" target="_blank" rel="noopener">{{ $ticket['reference'] }} ↗</a>
                @else
                    {{ $ticket['reference'] }}
                @endif
                @if (filled($ticket['source'] ?? null)) <span class="rc-muted">· {{ $ticket['source'] }}</span> @endif
                @if (filled($ticket['error'] ?? null))
                    <span class="rc-tone-danger">{{ $ticket['error'] }}</span>
                @elseif (($ticket['url'] ?? null) === null && ($ticket['markdown'] ?? null) === null)
                    <span class="rc-muted">· {{ __('not read yet') }}</span>
                @endif
            </dd>
        @endif
        @if ($record->head_since)
            <dt>{{ __('Newest commits') }}</dt><dd>{{ LocalTime::of($record->head_since)->translatedFormat('j M H:i') }} · <span class="rc-mono">{{ substr((string) $record->commit, 0, 7) }}</span></dd>
        @endif
    </dl>

    @if (filled($note['summary'] ?? null))
        <section class="rc-prose" aria-label="{{ __('Summary') }}">
            <p>{{ $note['summary'] }}</p>
        </section>
    @endif

    @if (filled($ticket['markdown'] ?? null))
        <section class="rc-card rc-pad" aria-labelledby="rc-ticket">
            <h2 id="rc-ticket">{{ __('Ticket :reference', ['reference' => $ticket['reference']]) }}@if (filled($ticket['read_at'] ?? null)) <span class="rc-muted">{{ __('read :time', ['time' => LocalTime::of(\Illuminate\Support\Carbon::parse($ticket['read_at']))->translatedFormat('j M H:i')]) }}</span>@endif</h2>
            {{-- Someone wrote it in the tracker: its markdown, without HTML of its own or links that are not web addresses. --}}
            <div class="rc-prose rc-ticket-text">{!! \Illuminate\Support\Str::markdown(\Illuminate\Support\Str::limit($ticket['markdown'], 6000), ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
        </section>
    @endif

    @if ($run !== null && filled($run->summary))
        <section class="rc-note rc-turn-review" aria-labelledby="rc-ai-review">
            <strong id="rc-ai-review">{{ __('AI review of :commit', ['commit' => substr($run->commit, 0, 7)]) }}</strong>
            <span class="rc-chip rc-chip-{{ RunPresenter::outcome($run)['tone'] }}">{{ RunPresenter::outcome($run)['label'] }}</span>
            <p>{{ \Illuminate\Support\Str::limit($run->summary, 600) }}</p>
            <a href="{{ ReviewRunResource::getUrl('view', ['record' => $run]) }}">{{ __('The whole review') }}</a>
        </section>
    @endif

    <details class="rc-turn-history" @if ($history->count() <= 6) open @endif>
        <summary><strong>{{ $history->count() === 1 ? __('History (:count step)', ['count' => $history->count()]) : __('History (:count steps)', ['count' => $history->count()]) }}</strong></summary>
        <ol>
            @foreach ($history as $entry)
                <li>
                    <span class="rc-muted">{{ LocalTime::of($entry['at'])->translatedFormat('j M H:i') }}</span>
                    @if ($entry['url'] !== null)
                        <a href="{{ $entry['url'] }}">{{ $entry['text'] }}</a>
                    @else
                        <span>{{ $entry['text'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
        @if ($history->isEmpty())
            <p class="rc-muted">{{ __('Nothing yet: the history starts with the next delivery from the host.') }}</p>
        @endif
    </details>
</div>
