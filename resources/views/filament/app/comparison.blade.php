@php
    use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
    use App\Filament\RunPresenter;
    use App\Models\ReviewComparison;
    use App\Services\Currency;
    use App\Services\ReviewComparisons;
    use App\Services\ReviewSettings;

    $verdicts = ['valid' => __('Holds'), 'false_positive' => __('False positive'), 'unclear' => __('Unclear')];
    $commits = $columns->map(fn ($column) => $column['run']?->commit)->filter()->unique();
@endphp

<x-filament-panels::page>
    {{-- Until every review and the analysis have ended, it looks again now and then. --}}
    <div class="rc-stack" @if (! $settled || $comparison->analysis_status === ReviewComparison::ANALYSIS_RUNNING) wire:poll.15s @endif>
        @if ($commits->count() > 1)
            <p class="rc-note">{{ __('Not every review is of the same commit: the pull request moved on between them. Compare with that in mind.') }}</p>
        @endif

        <div class="rc-compare">
            {{-- The columns left out last, faded: still there to bring back. --}}
            @foreach ($columns->sortBy(fn ($column, $label) => $comparison->isExcluded($label) ? 1 : 0) as $label => $column)
                @php($run = $column['run'])
                @php($excluded = $comparison->isExcluded($label))
                <section @class(['rc-card', 'rc-pad', 'rc-excluded' => $excluded]) aria-labelledby="rc-col-{{ $label }}">
                    <div class="rc-card-head">
                        <div>
                            <h2 id="rc-col-{{ $label }}">{{ $label }}{{ $label === 'A' ? ' · '.__('started from') : '' }}{{ $excluded ? ' · '.__('left out') : '' }}</h2>
                            <span class="rc-muted">
                                @if ($run !== null)
                                    {{ ReviewComparisons::setup($run) }}
                                @else
                                    {{ implode(' · ', array_filter([$column['row']['provider'] ?? null, $column['row']['model'] ?? null, isset($column['row']['effort']) ? __('effort :effort', ['effort' => $column['row']['effort']]) : null, empty($column['row']['modules']) ? null : ReviewSettings::describeModules($column['row']['modules'])])) ?: __('the repository\'s setup') }}
                                @endif
                            </span>
                        </div>
                        @if ($run !== null)
                            @php($outcome = RunPresenter::outcome($run))
                            <span class="rc-chip rc-chip-{{ $outcome['tone'] }}">{{ $outcome['label'] }}</span>
                        @elseif (ReviewComparison::notStarted($column) !== null)
                            <span class="rc-chip rc-chip-failed">{{ __('Not started') }}</span>
                        @else
                            <span class="rc-chip rc-chip-running">{{ __('Waiting') }}</span>
                        @endif
                    </div>

                    <button type="button" class="rc-link-button" wire:click="toggleColumn(@js($label))">{{ $excluded ? __('Bring back') : __('Leave out') }}</button>

                    @if ($run === null)
                        <p class="rc-muted">{{ ReviewComparison::notStarted($column) ?? __('Its review has not started yet.') }}</p>
                    @else
                        <dl class="rc-details">
                            <dt>{{ __('Cost') }}</dt><dd>{{ $run->cost === null ? '—' : Currency::show((float) $run->cost) }}</dd>
                            <dt>{{ __('Took') }}</dt><dd>{{ $run->duration_seconds === null ? '—' : RunPresenter::duration((int) $run->duration_seconds) }}</dd>
                            <dt>{{ __('Commit') }}</dt><dd class="rc-mono">{{ substr($run->commit, 0, 12) }}</dd>
                            {{-- Scores only from an analysis: before one, a row of noughts says nothing. --}}
                            @if ($comparison->analysis !== null && isset($scores[$label]))
                                <dt>{{ __('Holds') }}</dt><dd>{{ $scores[$label]['valid'] }}</dd>
                                <dt>{{ __('False positives') }}</dt><dd>{{ $scores[$label]['false_positive'] }}</dd>
                                <dt>{{ __('Unclear') }}</dt><dd>{{ $scores[$label]['unclear'] }}</dd>
                                <dt>{{ __('Missed') }}</dt><dd>{{ $scores[$label]['missed'] }}</dd>
                            @endif
                        </dl>
                        <a href="{{ ReviewRunResource::getUrl('view', ['record' => $run]) }}">{{ __('Open the review') }}</a>
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
                                <li class="rc-muted">{{ in_array($run->status, ['queued', 'running', 'pending'], true) ? __('Still reviewing.') : __('No findings.') }}</li>
                            @endforelse
                        </ol>
                    @endif
                </section>
            @endforeach
        </div>

        <section class="rc-card rc-pad" aria-labelledby="rc-analysis">
            <div class="rc-card-head">
                <div>
                    <h2 id="rc-analysis">{{ __('Analysis') }}</h2>
                    @if ($comparison->analysis_model !== null)
                        <span class="rc-muted">{{ __('By :model', ['model' => $comparison->analysis_model]) }}{{ $comparison->analysis_cost === null ? '' : ' · '.__('about :cost', ['cost' => Currency::show($comparison->analysis_cost, null, 4)]) }}</span>
                    @endif
                </div>
            </div>
            {{-- What the scores in the columns are of, also while another attempt runs or after one failed. --}}
            @if ($analysed !== null)
                <p class="rc-muted">
                    {{ __('The scores are of :labels.', ['labels' => implode(', ', $analysed)]) }}
                    @if ($analysed !== $analysable)
                        {{ __('The reviews to analyse are :labels now: analyse again to judge those.', ['labels' => implode(', ', $analysable) ?: __('none')]) }}
                    @endif
                </p>
            @endif
            @if ($comparison->analysis_status === ReviewComparison::ANALYSIS_RUNNING)
                <p class="rc-muted">{{ __('The model is reading the reviews.') }}</p>
            @elseif ($comparison->analysis_status === ReviewComparison::ANALYSIS_FAILED)
                <p>{{ __('It failed: :error', ['error' => $comparison->analysis_error]) }}</p>
            @elseif ($comparison->analysis === null)
                <p class="rc-muted">{{ $done ? __('Analyse has one model judge the findings of the reviews not left out together: which hold, which are false, and who missed what.') : __('Once every review not left out has ended, Analyse has one model judge their findings together.') }}</p>
            @else
                @if (filled($comparison->analysis['summary'] ?? null))
                    <p>{{ $comparison->analysis['summary'] }}</p>
                @endif
                <table class="rc-steps">
                    <thead><tr><th scope="col">{{ __('Issue') }}</th><th scope="col">{{ __('Verdict') }}</th><th scope="col">{{ __('Found by') }}</th></tr></thead>
                    <tbody>
                        @foreach ($comparison->analysis['findings'] ?? [] as $issue)
                            <tr>
                                <td>
                                    <strong>{{ $issue['title'] }}</strong>
                                    @if ($issue['path'] !== null) <span class="rc-mono rc-muted">{{ $issue['path'] }}</span> @endif
                                    <br><span class="rc-muted">{{ $issue['reason'] }}</span>
                                </td>
                                <td class="rc-verdict-{{ $issue['verdict'] }}">{{ $verdicts[$issue['verdict']] ?? $issue['verdict'] }}</td>
                                <td class="rc-mono">{{ implode(', ', $issue['found_by']) ?: __('nobody') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>
</x-filament-panels::page>
