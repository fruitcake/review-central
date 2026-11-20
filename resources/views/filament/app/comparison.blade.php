@php
    use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
    use App\Filament\RunPresenter;
    use App\Models\ReviewComparison;
    use App\Services\Currency;
    use App\Services\ReviewComparisons;

    $verdicts = ['valid' => 'Holds', 'false_positive' => 'False positive', 'unclear' => 'Unclear'];
    $commits = $columns->map(fn ($column) => $column['run']?->commit)->filter()->unique();
@endphp

<x-filament-panels::page>
    {{-- Until every review and the analysis have ended, it looks again now and then. --}}
    <div class="rc-stack" @if (! $done || $comparison->analysis_status === ReviewComparison::ANALYSIS_RUNNING) wire:poll.15s @endif>
        @if ($commits->count() > 1)
            <p class="rc-note">Not every review is of the same commit: the pull request moved on between them. Compare with that in mind.</p>
        @endif

        <div class="rc-compare">
            @foreach ($columns as $label => $column)
                @php($run = $column['run'])
                <section class="rc-card rc-pad" aria-labelledby="rc-col-{{ $label }}">
                    <div class="rc-card-head">
                        <div>
                            <h2 id="rc-col-{{ $label }}">{{ $label }}{{ $label === 'A' ? ' · the review it started from' : '' }}</h2>
                            <span class="rc-muted">
                                @if ($run !== null)
                                    {{ ReviewComparisons::setup($run) }}
                                @else
                                    {{ implode(' · ', array_filter([$column['row']['provider'] ?? null, $column['row']['model'] ?? null, isset($column['row']['effort']) ? 'effort '.$column['row']['effort'] : null])) ?: 'the repository\'s setup' }}
                                @endif
                            </span>
                        </div>
                        @if ($run !== null)
                            @php($outcome = RunPresenter::outcome($run))
                            <span class="rc-chip rc-chip-{{ $outcome['tone'] }}">{{ $outcome['label'] }}</span>
                        @elseif (($column['row']['error'] ?? null) !== null)
                            <span class="rc-chip rc-chip-failed">Not started</span>
                        @else
                            <span class="rc-chip rc-chip-running">Waiting</span>
                        @endif
                    </div>

                    @if ($run === null)
                        <p class="rc-muted">{{ $column['row']['error'] ?? 'Its review has not started yet.' }}</p>
                    @else
                        <dl class="rc-details">
                            <dt>Cost</dt><dd>{{ $run->cost === null ? '—' : Currency::show((float) $run->cost) }}</dd>
                            <dt>Took</dt><dd>{{ $run->duration_seconds === null ? '—' : RunPresenter::duration((int) $run->duration_seconds) }}</dd>
                            <dt>Commit</dt><dd class="rc-mono">{{ substr($run->commit, 0, 12) }}</dd>
                            @isset($scores[$label])
                                <dt>Holds</dt><dd>{{ $scores[$label]['valid'] }}</dd>
                                <dt>False positives</dt><dd>{{ $scores[$label]['false_positive'] }}</dd>
                                <dt>Unclear</dt><dd>{{ $scores[$label]['unclear'] }}</dd>
                                <dt>Missed</dt><dd>{{ $scores[$label]['missed'] }}</dd>
                            @endisset
                        </dl>
                        <a href="{{ ReviewRunResource::getUrl('view', ['record' => $run]) }}">Open the review</a>
                        <ol>
                            @forelse (array_values(array_filter($run->findings ?? [], is_array(...))) as $finding)
                                <li>
                                    <span><span class="rc-mono">{{ $label }}-{{ $finding['id'] ?? '' }}</span> <span class="rc-chip rc-chip-{{ strtolower((string) ($finding['severity'] ?? 'low')) }}">{{ strtoupper((string) ($finding['severity'] ?? '')) }}</span></span>
                                    <strong>{{ $finding['title'] ?? '' }}</strong>
                                    @if (filled($finding['path'] ?? null))
                                        <span class="rc-mono rc-muted">{{ $finding['path'] }}@if (filled($finding['line'] ?? null)):{{ $finding['line'] }}@endif</span>
                                    @endif
                                </li>
                            @empty
                                <li class="rc-muted">{{ in_array($run->status, ['queued', 'running', 'pending'], true) ? 'Still reviewing.' : 'No findings.' }}</li>
                            @endforelse
                        </ol>
                    @endif
                </section>
            @endforeach
        </div>

        <section class="rc-card rc-pad" aria-labelledby="rc-analysis">
            <div class="rc-card-head">
                <div>
                    <h2 id="rc-analysis">Analysis</h2>
                    @if ($comparison->analysis_model !== null)
                        <span class="rc-muted">By {{ $comparison->analysis_model }}{{ $comparison->analysis_cost === null ? '' : ' · about '.Currency::show($comparison->analysis_cost, null, 4) }}</span>
                    @endif
                </div>
            </div>
            @if ($comparison->analysis_status === ReviewComparison::ANALYSIS_RUNNING)
                <p class="rc-muted">The model is reading the reviews.</p>
            @elseif ($comparison->analysis_status === ReviewComparison::ANALYSIS_FAILED)
                <p>It failed: {{ $comparison->analysis_error }}</p>
            @elseif ($comparison->analysis === null)
                <p class="rc-muted">{{ $done ? 'Analyse has one model judge every review\'s findings together: which hold, which are false, and who missed what.' : 'Once every review has ended, Analyse has one model judge their findings together.' }}</p>
            @else
                @if (filled($comparison->analysis['summary'] ?? null))
                    <p>{{ $comparison->analysis['summary'] }}</p>
                @endif
                <table class="rc-steps">
                    <thead><tr><th scope="col">Issue</th><th scope="col">Verdict</th><th scope="col">Found by</th></tr></thead>
                    <tbody>
                        @foreach ($comparison->analysis['findings'] ?? [] as $issue)
                            <tr>
                                <td>
                                    <strong>{{ $issue['title'] }}</strong>
                                    @if ($issue['path'] !== null) <span class="rc-mono rc-muted">{{ $issue['path'] }}</span> @endif
                                    <br><span class="rc-muted">{{ $issue['reason'] }}</span>
                                </td>
                                <td class="rc-verdict-{{ $issue['verdict'] }}">{{ $verdicts[$issue['verdict']] ?? $issue['verdict'] }}</td>
                                <td class="rc-mono">{{ implode(', ', $issue['found_by']) ?: 'nobody' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>
</x-filament-panels::page>
