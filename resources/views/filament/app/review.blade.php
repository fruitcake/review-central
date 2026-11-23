@php
    use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
    use App\Filament\RunPresenter;
    use App\Runners\Runners;
    use App\Services\Currency;
    use App\Services\ReviewSettings;
    use Illuminate\Support\Str;

    $outcome = RunPresenter::outcome($run);
    $ticket = $run->context['ticket'] ?? null;
    $pullRequest = $run->context['pull_request'] ?? [];
    $position = $siblings->search(fn ($sibling) => $sibling->is($run));
    $newest = $siblings->last();
    $usage = collect($run->usage ?? [])->reduce(function (array $sum, array $types): array {
        foreach (['input', 'output', 'cache_read', 'cache_creation'] as $type) {
            $sum[$type] += (int) ($types[$type] ?? 0);
        }

        return $sum;
    }, ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0]);
    $tokens = array_sum($usage);
    $statuses = ['new' => 'New', 'open' => 'Open', 'fixed' => 'Fixed', 'acknowledged' => 'Acknowledged', 'false_positive' => 'Not a problem', 'unreported' => 'No longer reported'];
    // Only a web address becomes a link: a ticket's address comes from whatever its tracker answered.
    $link = fn (?string $url, string $label) => $url === null || preg_match('~^https?://~i', $url) !== 1
        ? e($label)
        : '<a href="'.e($url).'" target="_blank" rel="noopener">'.e($label).' ↗</a>';
@endphp

<x-filament-panels::page>
    <div class="rc-stack">
        <div class="rc-meta">
            <span class="rc-chip rc-chip-{{ $outcome['tone'] }}">{{ $outcome['label'] }}</span>
            @if ($siblings->count() > 1)
                <span class="rc-muted">
                    Run {{ $position + 1 }} of {{ $siblings->count() }}
                    @if (! $newest->is($run)) · the newest run: {{ Str::lower(RunPresenter::outcome($newest)['label']) }} @endif
                </span>
            @endif
            <span class="rc-mono">{{ $run->workspace }}/{{ $run->slug }}</span>
            @if ($run->pull_request_id)
                <span>{!! $link($run->pullRequestUrl(), "PR #{$run->pull_request_id}") !!}</span>
            @endif
            <span>{{ $run->branch }}@if (filled($pullRequest['destination_branch'] ?? null)) → {{ $pullRequest['destination_branch'] }}@endif</span>
            <span>
                Ticket:
                @if ($ticket === null)
                    <span class="rc-muted">None named in the title</span>
                @else
                    {!! $link($ticket['url'] ?? null, (string) ($ticket['reference'] ?? '')) !!}
                    @if (filled($ticket['error'] ?? null)) <span class="rc-tone-danger">{{ $ticket['error'] }}</span> @endif
                @endif
            </span>
        </div>

        @if ($siblings->count() > 1)
            <section class="rc-card rc-pad" aria-labelledby="rc-runs">
                <h2 id="rc-runs">Runs on this pull request</h2>
                <div class="rc-timeline">
                    @foreach ($siblings as $index => $sibling)
                        @php($result = RunPresenter::outcome($sibling))
                        <a href="{{ ReviewRunResource::getUrl('view', ['record' => $sibling]) }}" @class(['rc-timeline-run', 'rc-current' => $sibling->is($run)]) @if ($sibling->is($run)) aria-current="true" @endif>
                            <span class="rc-timeline-top"><strong>Run {{ $index + 1 }} · {{ \App\Filament\LocalTime::of($sibling->started_at)->format('j M H:i') }}</strong><span class="rc-mono">{{ substr($sibling->commit, 0, 7) }}</span></span>
                            <span class="rc-chip rc-chip-{{ $result['tone'] }}">{{ $result['label'] }}</span>
                            <span class="rc-muted">
                                @if (RunPresenter::live($sibling))
                                    @include('filament.app.partials.elapsed', ['since' => $sibling->started_at]) so far
                                @else
                                {{ collect([
                                    $sibling->findings === null ? null : ($sibling->openFindings() === 0 ? 'No open findings' : $sibling->openFindings().' open'),
                                    RunPresenter::duration($sibling->duration_seconds),
                                    $sibling->cost === null ? null : Currency::show($sibling->cost),
                                ])->filter()->implode(' · ') ?: '—' }}
                                @endif
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <div class="rc-row">
            <div class="rc-grow-2 rc-column">
                @if (filled($run->manual_review))
                    <section class="rc-card rc-pad" aria-labelledby="rc-manual">
                        <h2 id="rc-manual">For a person to check</h2>
                        <span class="rc-muted">{{ $run->needsManualReview() ? 'The review found nothing that holds the merge, and has not approved the pull request.' : 'Besides the findings below.' }}</span>
                        <div class="rc-prose">
                            <ul>
                                @foreach ($run->manual_review as $point)
                                    <li>{{ $point }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </section>
                @endif

                <section class="rc-card rc-pad" aria-labelledby="rc-summary">
                    <h2 id="rc-summary">Summary</h2>
                    @if (filled($run->summary))
                        <div class="rc-prose">{!! Str::markdown($run->summary, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                    @else
                        <span class="rc-muted">{{ RunPresenter::standing($run) }}</span>
                    @endif
                </section>

                {{-- Asks again every few seconds for as long as the review is queued or runs, with or
                     without a log yet; the page then settles on how it ended. --}}
                @if ($live)
                    <div wire:poll.{{ $poll }} hidden></div>
                @endif

                {{-- While it runs the log is what there is to watch; once done, the findings come first. --}}
                @if ($live)
                    @include('filament.app.partials.review-log')
                @endif

                <section class="rc-card rc-pad" aria-labelledby="rc-findings">
                    <div class="rc-card-head">
                        <h2 id="rc-findings">Findings</h2>
                        <span class="rc-muted">Your answers show how often the reviewer is right, per category</span>
                    </div>
                    @forelse ($findings as $finding)
                        @php($severity = strtolower((string) ($finding['severity'] ?? 'low')))
                        @php($status = (string) ($finding['status'] ?? ''))
                        <article class="rc-finding">
                            <div class="rc-finding-tags">
                                <span class="rc-mono rc-finding-id">{{ $finding['id'] ?? '' }}</span>
                                <span class="rc-chip rc-chip-{{ in_array($severity, ['high', 'medium', 'low'], true) ? $severity : 'low' }}">{{ ucfirst($severity) }}</span>
                                @if (filled($finding['category'] ?? null)) <span class="rc-tag">{{ ucfirst((string) $finding['category']) }}</span> @endif
                                <span class="rc-chip rc-chip-{{ $status === 'fixed' ? 'approved' : 'neutral' }}">{{ $statuses[$status] ?? ucfirst($status) }}</span>
                                @if ($finding['blocks']) <span class="rc-chip rc-chip-changes">Holds the merge</span> @endif
                            </div>
                            <h3>{{ $finding['title'] ?? '' }}</h3>
                            @if (filled($finding['path'] ?? null))
                                <span class="rc-mono rc-muted">{{ $finding['path'] }}@if (filled($finding['line'] ?? null)):{{ $finding['line'] }}@endif</span>
                            @endif
                            @if (filled($finding['details'] ?? null))
                                <div class="rc-prose">{!! Str::markdown((string) $finding['details'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                            @endif
                            @if (filled($finding['suggestion'] ?? null))
                                <pre class="rc-code">{{ $finding['suggestion'] }}</pre>
                            @endif
                            <div class="rc-verdicts">
                                <span class="rc-muted">Was this right?</span>
                                @foreach (\App\Enums\FindingVerdictKind::cases() as $kind)
                                    @php($given = ($verdicts[(string) ($finding['id'] ?? '')] ?? null) === $kind)
                                    <button type="button" wire:click="judge(@js((string) ($finding['id'] ?? '')), '{{ $kind->value }}')" aria-pressed="{{ $given ? 'true' : 'false' }}" @class(['rc-verdict', "rc-verdict-{$kind->value}", 'rc-on' => $given])>{{ $kind->text() }}</button>
                                @endforeach
                                @php($mine = $verdicts[(string) ($finding['id'] ?? '')] ?? null)
                                @if ($mine !== null && ! $mine->accepts())
                                    <select aria-label="Why" class="rc-reason" wire:change="explain(@js((string) ($finding['id'] ?? '')), $event.target.value)">
                                        <option value="">Why?</option>
                                        @foreach (\App\Enums\FindingVerdictReason::cases() as $reason)
                                            <option value="{{ $reason->value }}" title="{{ $reason->description() }}" @selected(($reasons[(string) ($finding['id'] ?? '')] ?? null) === $reason)>{{ $reason->text() }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                @if (in_array((string) ($finding['id'] ?? ''), $dismissed, true) && $this->makeRuleAction->isVisible())
                                    {{ ($this->makeRuleAction)(['finding' => (string) ($finding['id'] ?? '')]) }}
                                @endif
                            </div>
                        </article>
                    @empty
                        <span class="rc-muted">{{ $run->findings === null ? 'No findings reported yet.' : 'No findings.' }}</span>
                    @endforelse
                </section>

                @if (! $live)
                    @include('filament.app.partials.review-log')
                @endif

                @if ($run->pull_request_id !== null)
                    <section class="rc-card rc-pad" aria-labelledby="rc-questions">
                        <div class="rc-card-head">
                            <h2 id="rc-questions">Questions</h2>
                            <span class="rc-muted">asked here, or on the pull request with @review</span>
                        </div>
                        {{-- Answered here alone: nothing is said on the pull request. --}}
                        <form wire:submit="ask" class="rc-ask">
                            <label for="rc-ask-question" class="rc-muted">Ask the reviewer about this pull request. It answers here, from the session of its last review; nothing is posted on the pull request.</label>
                            <textarea id="rc-ask-question" wire:model="question" rows="3" maxlength="4000" placeholder="Why is this a problem, and where else does it happen?"></textarea>
                            @error('question') <span class="rc-tone-danger">{{ $message }}</span> @enderror
                            <div><button type="submit" class="rc-button" wire:loading.attr="disabled" wire:target="ask">Ask</button></div>
                        </form>
                        {{-- Looks again while an answer is on its way. --}}
                        @if ($questions->contains(fn ($question) => in_array($question->status, [\App\Models\ReviewQuestion::STATUS_ASKED, \App\Models\ReviewQuestion::STATUS_UNSURE], true)))
                            <div wire:poll.10s hidden></div>
                        @endif
                        @foreach ($questions as $question)
                            <div class="rc-question">
                                <p><strong>{{ $question->author ?? 'Someone' }}</strong> <span class="rc-muted">{{ \App\Filament\LocalTime::of($question->created_at)->format('j M, H:i') }} · {{ $question->comment_id === null ? 'here' : 'on the pull request' }}</span></p>
                                <p>{{ $question->question }}</p>
                                @if ($question->status === \App\Models\ReviewQuestion::STATUS_ANSWERED)
                                    <div class="rc-prose">{!! Str::markdown((string) $question->answer, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                                    <span class="rc-muted">{{ $question->resumed ? 'Answered from the last review\'s session' : 'Answered from the code alone' }}{{ $question->cost === null ? '' : ' · '.Currency::show($question->cost) }}</span>
                                @else
                                    <span class="rc-muted">{{ $question->status === \App\Models\ReviewQuestion::STATUS_FAILED ? 'The reviewer gave no answer.' : 'The reviewer is answering.' }}</span>
                                @endif
                            </div>
                        @endforeach
                    </section>
                @endif
            </div>

            <aside class="rc-grow-1 rc-column">
                <section class="rc-card rc-pad" aria-labelledby="rc-cost">
                    <h2 id="rc-cost">Cost of this run</h2>
                    <div class="rc-pairs">
                        <div><span class="rc-muted">Cost</span><strong>{{ $run->cost === null ? 'Unknown' : Currency::show($run->cost) }}</strong></div>
                        <div><span class="rc-muted">Duration</span><strong>
                            @if ($live)
                                @include('filament.app.partials.elapsed', ['since' => $run->started_at])
                            @else
                                {{ RunPresenter::duration($run->duration_seconds) ?? '—' }}
                            @endif
                        </strong></div>
                        <div><span class="rc-muted">Tokens</span><strong>{{ number_format($tokens) }}</strong></div>
                        <div><span class="rc-muted">Read from cache</span><strong>{{ ($rate = $run->cacheHitRate()) === null ? '—' : round($rate * 100).'%' }}</strong></div>
                    </div>
                    @if ($run->cost_estimated)
                        <span class="rc-muted">Estimated from the tokens and the price list.</span>
                    @endif
                    @if ($tokens > 0)
                        <div class="rc-bar rc-bar-tall">
                            <div class="rc-fill-primary" style="width: {{ $usage['cache_read'] / $tokens * 100 }}%"></div>
                            <div class="rc-fill-sky" style="width: {{ $usage['cache_creation'] / $tokens * 100 }}%"></div>
                            <div class="rc-fill-grey" style="width: {{ $usage['input'] / $tokens * 100 }}%"></div>
                            <div class="rc-fill-accent" style="width: {{ $usage['output'] / $tokens * 100 }}%"></div>
                        </div>
                        <div class="rc-legend rc-legend-stacked">
                            <span><i class="rc-fill-primary"></i>Cache read {{ number_format($usage['cache_read']) }}</span>
                            <span><i class="rc-fill-sky"></i>Cache write {{ number_format($usage['cache_creation']) }}</span>
                            <span><i class="rc-fill-grey"></i>Input {{ number_format($usage['input']) }}</span>
                            <span><i class="rc-fill-accent"></i>Output {{ number_format($usage['output']) }}</span>
                        </div>
                    @endif
                    @if ($siblings->count() > 1)
                        <div class="rc-note">This pull request so far: <strong>{{ $siblings->count() }} runs · {{ Currency::show($siblings->sum(fn ($sibling) => (float) $sibling->cost)) }}</strong></div>
                    @endif
                </section>

                <section class="rc-card rc-pad" aria-labelledby="rc-details">
                    <h2 id="rc-details">Run details</h2>
                    <dl class="rc-details">
                        <dt>Started</dt><dd>{{ \App\Filament\LocalTime::of($run->started_at)->format('j M Y, H:i:s') }}</dd>
                        @if ($run->peak_memory_mb !== null)
                            <dt>Memory</dt><dd>{{ number_format($run->peak_memory_mb) }} MB at most{{ $run->memory_limit === null ? '' : ", of {$run->memory_limit} allowed" }}</dd>
                        @endif
                        @if ($run->sandbox !== null)
                            <dt>Sandbox</dt><dd>{{ ucfirst($run->sandbox) }}{{ $run->sandbox_size === null ? '' : " · {$run->sandbox_size}" }}{{ $run->sandbox_seconds === null ? '' : ' · '.RunPresenter::duration($run->sandbox_seconds) }}{{ $run->sandbox_cost === null ? '' : ' · about '.Currency::show($run->sandbox_cost, null, 4) }}</dd>
                        @endif
                        @if ($run->queue_seconds !== null)
                            <dt>Waited</dt><dd>{{ RunPresenter::duration($run->queue_seconds) }} for a worker</dd>
                        @endif
                        <dt>Runner</dt><dd>{{ rescue(fn () => app(Runners::class)->get((string) $run->runner)->shortLabel(), '—', false) }}</dd>
                        <dt>Build</dt><dd>{{ $run->build_number === null ? '—' : "#{$run->build_number}" }}</dd>
                        <dt>Reviewer</dt><dd>{{ RunPresenter::reviewer($run) ?? '—' }}</dd>
                        <dt>Model</dt><dd>{{ $run->model ?? '—' }}</dd>
                        <dt>Effort</dt><dd>{{ $run->effort ?? 'Default' }}</dd>
                        @if ($run->modules !== null)
                            <dt>Parts</dt><dd>{{ ucfirst(ReviewSettings::describeModules($run->modules)) }}</dd>
                        @endif
                        @if ($classification !== null)
                            <dt>Classified</dt><dd>{{ $classification->text() }}<span class="rc-muted">{{ $classification->reason === null ? '' : ": {$classification->reason}" }} · {{ $classification->files_count }} files, +{{ $classification->lines_added }} −{{ $classification->lines_removed }}{{ $classification->cost === null ? '' : ' · about '.Currency::show($classification->cost, null, 4) }}</span></dd>
                        @endif
                        <dt>Instructions</dt><dd class="rc-mono">{{ $run->instructions_version ?? '—' }}</dd>
                        <dt>Commit</dt><dd class="rc-mono">{{ substr($run->commit, 0, 12) }}</dd>
                        @if (($run->metrics['cpu_average'] ?? null) !== null)
                            <dt>Processor</dt><dd>{{ $run->metrics['cpu_average'] }} on average, {{ $run->metrics['cpu_peak'] }} at most{{ ($run->metrics['processors'] ?? null) === null ? '' : ', of '.$run->metrics['processors'] }}{{ ($run->metrics['starved_percent'] ?? 0) > 0 ? " · short of it {$run->metrics['starved_percent']}% of the time" : '' }}</dd>
                        @endif
                    </dl>
                    @if (! empty($run->metrics['steps']))
                        @php($slowest = collect($run->metrics['steps'])->sortByDesc(fn ($step) => (int) ($step['seconds'] ?? 0))->first())
                        {{-- Closed: what each step took is for whoever looks into a slow or heavy review. --}}
                        <details class="rc-collapse">
                            <summary>
                                <span>Steps</span>
                                <span class="rc-muted">{{ count($run->metrics['steps']) }} · longest {{ $slowest['name'] }}, {{ RunPresenter::duration((int) $slowest['seconds']) }}</span>
                            </summary>
                        <table class="rc-steps">
                            <thead><tr><th scope="col">Step</th><th scope="col">Took</th><th scope="col">Memory</th><th scope="col">Processor</th></tr></thead>
                            <tbody>
                                @foreach ($run->metrics['steps'] as $step)
                                    <tr>
                                        <td>{{ $step['name'] }}</td>
                                        <td>{{ RunPresenter::duration((int) $step['seconds']) }}</td>
                                        <td>{{ ($step['peak_memory_mb'] ?? null) === null ? '—' : number_format($step['peak_memory_mb']).' MB' }}</td>
                                        <td>{{ ($step['cpu_average'] ?? null) === null ? '—' : $step['cpu_average'].' · '.$step['cpu_peak'].' max' }}{{ ($step['starved_percent'] ?? 0) >= 25 ? ' · short' : '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </details>
                    @endif
                </section>

                <section class="rc-card rc-pad rc-rate" aria-labelledby="rc-rate">
                    <h2 id="rc-rate">Rate this review</h2>
                    @if ($ratings->isEmpty())
                        <span>Nobody rated this review yet. Ratings show how useful the reviews are, per repository and per category.</span>
                    @else
                        @foreach ($ratings as $rating)
                            <div>
                                <strong>{{ $rating->score }} of 5</strong> · {{ $rating->name ?? 'Anonymous' }} · {{ $rating->created_at->diffForHumans() }}
                                @if (filled($rating->comment)) <div>{{ $rating->comment }}</div> @endif
                            </div>
                        @endforeach
                    @endif
                    <div class="rc-stars" role="group" aria-label="Your rating">
                        @foreach (range(1, 5) as $score)
                            <button type="button" wire:click="rate({{ $score }})" aria-label="{{ $score }} of 5" aria-pressed="{{ $ownScore === $score ? 'true' : 'false' }}" @class(['rc-on' => $ownScore !== null && $score <= $ownScore])>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m12 2 3 6.5 7 .9-5.2 4.8 1.4 7L12 17.8 5.8 21.2l1.4-7L2 9.4l7-.9z"/></svg>
                            </button>
                        @endforeach
                    </div>
                    <a href="{{ route('reviews.rate', ['token' => $run->ratingToken()]) }}" target="_blank" rel="noopener">The rating page, to share ↗</a>
                </section>
            </aside>
        </div>
    </div>
</x-filament-panels::page>
