@php
    use App\Enums\ReviewRuleKind;
    use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
    use App\Filament\RunPresenter;
    use App\Models\OpenFinding;
    use App\Services\Currency;
    use Illuminate\Support\Carbon;

    $severityLabel = fn (string $severity): string => match (strtolower($severity)) {
        'critical' => __('Critical'),
        'high' => __('High'),
        'medium' => __('Medium'),
        'low' => __('Low'),
        default => ucfirst(strtolower($severity)),
    };
@endphp

<x-filament-panels::page>
    <div class="rc-stack">
        <section class="rc-figures" aria-label="{{ __('Key figures') }}">
            @foreach ($overview['figures'] as $figure)
                <div class="rc-card rc-figure">
                    <span class="rc-muted">{{ $figure['label'] }}</span>
                    <span class="rc-figure-value">{{ $figure['value'] }}</span>
                    <span class="rc-figure-note rc-tone-{{ $figure['tone'] }}">{{ $figure['note'] }}</span>
                </div>
            @endforeach
        </section>

        <div class="rc-row">
            <section class="rc-card rc-pad rc-grow-2" aria-labelledby="rc-per-day">
                <div class="rc-card-head">
                    <div>
                        <h2 id="rc-per-day">{{ __('Reviews per day') }}</h2>
                        <span class="rc-muted">{{ __('Finished reviews, by how they ended') }}</span>
                    </div>
                    <div class="rc-legend">
                        <span><i class="rc-fill-primary"></i>{{ __('Approved') }}</span>
                        <span><i class="rc-fill-danger"></i>{{ __('Changes requested') }}</span>
                    </div>
                </div>
                <div class="rc-chart" role="img" aria-label="{{ __('Finished reviews per day over the period') }}">
                    @foreach ($overview['days']['bars'] as $bar)
                        <div class="rc-chart-day" title="{{ __(':date: :approved approved, :changes changes requested', ['date' => $bar['date'], 'approved' => $bar['approved'], 'changes' => $bar['changes']]) }}">
                            <div class="rc-fill-danger" style="height: {{ $bar['changes'] / $overview['days']['max'] * 100 }}%"></div>
                            <div class="rc-fill-primary" style="height: {{ $bar['approved'] / $overview['days']['max'] * 100 }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="rc-chart-axis">
                    <span>{{ Carbon::parse($overview['days']['bars'][0]['date'])->translatedFormat('j M') }}</span>
                    <span>{{ Carbon::parse($overview['days']['bars'][intdiv(count($overview['days']['bars']), 2)]['date'])->translatedFormat('j M') }}</span>
                    <span>{{ Carbon::parse(last($overview['days']['bars'])['date'])->translatedFormat('j M') }}</span>
                </div>
            </section>

            <section class="rc-card rc-pad rc-grow-1" aria-labelledby="rc-categories">
                <div>
                    <h2 id="rc-categories">{{ __('Findings by category') }}</h2>
                    <span class="rc-muted">{{ __('How often the team accepts what the reviewer finds here') }}</span>
                </div>
                @foreach ($overview['categories'] as $category)
                    <div class="rc-category">
                        <div class="rc-category-head">
                            <strong>{{ $category['name'] }}</strong>
                            <span>
                                {{ $category['findings'] === 1 ? __(':count finding', ['count' => $category['findings']]) : __(':count findings', ['count' => $category['findings']]) }}
                                @if ($category['rated'] > 0)
                                    · <strong class="rc-tone-primary">{{ __(':percent% accepted', ['percent' => round($category['accepted'] / $category['rated'] * 100)]) }}</strong>
                                @endif
                            </span>
                        </div>
                        <div class="rc-bar rc-bar-tall">
                            @if ($category['rated'] > 0)
                                <div class="rc-fill-primary" style="width: {{ $category['accepted'] / $category['rated'] * 100 }}%"></div>
                                <div class="rc-fill-grey" style="width: {{ ($category['rated'] - $category['accepted']) / $category['rated'] * 100 }}%"></div>
                            @endif
                        </div>
                    </div>
                @endforeach
                <div class="rc-legend">
                    <span><i class="rc-fill-primary"></i>{{ __('Accepted') }}</span>
                    <span><i class="rc-fill-grey"></i>{{ __('Dismissed') }}</span>
                    <span class="rc-muted">{{ __('of the findings people rated') }}</span>
                </div>
                @include('filament.app.partials.finding-outcomes', ['outcomes' => $overview['outcomes']])
            </section>
        </div>

        <div class="rc-row">
            <section class="rc-card rc-grow-2" aria-labelledby="rc-pull-requests">
                <div class="rc-card-bar">
                    <h2 id="rc-pull-requests">{{ __('Open pull requests') }}</h2>
                    <a href="{{ $targets['pullRequests'] }}">{{ __('All pull requests') }} →</a>
                </div>
                <div class="rc-scroll">
                    <table class="rc-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Pull request') }}</th>
                                <th scope="col">{{ __('Author') }}</th>
                                <th scope="col">{{ __('Last review') }}</th>
                                <th scope="col" class="rc-num">{{ __('Still open') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($overview['pullRequests'] as $row)
                                @php($pullRequest = $row['pullRequest'])
                                @php($run = $row['run'])
                                <tr>
                                    <td>
                                        <strong>#{{ $pullRequest->pull_request_id }}</strong>
                                        <span>{{ $pullRequest->title }}</span>
                                        @if ($pullRequest->draft) <span class="rc-tag">{{ __('Draft') }}</span> @endif
                                        @if ($pullRequest->awaiting_approval) <span class="rc-tag">{{ __('Waiting to be started') }}</span> @endif
                                    </td>
                                    <td>{{ $pullRequest->author ?? '—' }}</td>
                                    <td>
                                        @if ($run !== null)
                                            @php($outcome = RunPresenter::outcome($run))
                                            <a href="{{ ReviewRunResource::getUrl('view', ['record' => $run]) }}"><span class="rc-chip rc-chip-{{ $outcome['tone'] }}">{{ $outcome['label'] }}</span></a>
                                        @else
                                            <span class="rc-muted">{{ __('Not reviewed yet') }}</span>
                                        @endif
                                    </td>
                                    <td class="rc-num rc-muted">{{ $pullRequest->opened_at?->diffForHumans(short: true) ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="rc-muted">{{ __('No open pull requests.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rc-card rc-grow-1" aria-labelledby="rc-activity">
                <div class="rc-card-bar">
                    <h2 id="rc-activity">{{ __('Latest reviews') }}</h2>
                    <a href="{{ $targets['reviews'] }}">{{ __('All') }} →</a>
                </div>
                <div class="rc-activity">
                    @forelse ($overview['activity'] as $run)
                        @php($outcome = RunPresenter::outcome($run))
                        <a href="{{ ReviewRunResource::getUrl('view', ['record' => $run]) }}">
                            <span class="rc-dot rc-dot-{{ $outcome['tone'] }}"></span>
                            <span>
                                <strong>@if ($run->pull_request_id)#{{ $run->pull_request_id }} · @endif{{ $outcome['label'] }}</strong>
                                <span class="rc-muted">
                                    {{ collect([RunPresenter::reviewer($run), RunPresenter::duration($run->duration_seconds), $run->cost === null ? null : Currency::show($run->cost), $run->started_at->diffForHumans()])->filter()->implode(' · ') }}
                                </span>
                            </span>
                        </a>
                    @empty
                        <span class="rc-muted rc-pad">{{ __('No reviews yet.') }}</span>
                    @endforelse
                </div>
            </section>
        </div>

        <div class="rc-row">
            <section class="rc-card rc-grow-1" aria-labelledby="rc-open-findings">
                <div class="rc-card-bar">
                    <h2 id="rc-open-findings">{{ __('Open findings') }}</h2>
                    <a href="{{ $targets['openFindings'] }}">{{ __('All') }} →</a>
                </div>
                <div class="rc-activity">
                    @forelse ($overview['openFindings'] as $finding)
                        @php($severity = strtolower((string) ($finding->severity ?? 'low')))
                        <a href="{{ $targets['openFindings'] }}">
                            <span class="rc-chip rc-chip-{{ in_array($severity, ['high', 'medium', 'low'], true) ? $severity : 'low' }}">{{ $severityLabel($severity) }}</span>
                            <span>
                                <strong>{{ $finding->title }}</strong>
                                <span class="rc-muted rc-mono">{{ $finding->path }}@if ($finding->line):{{ $finding->line }}@endif</span>
                                <span class="rc-muted">#{{ $finding->pull_request_id }} · {{ OpenFinding::statusLabels()[$finding->status] ?? $finding->status }}</span>
                            </span>
                        </a>
                    @empty
                        <span class="rc-muted rc-pad">{{ __('Nothing left open on merged pull requests.') }}</span>
                    @endforelse
                </div>
            </section>

            <section class="rc-card rc-grow-1" aria-labelledby="rc-rules">
                <div class="rc-card-bar">
                    <h2 id="rc-rules">{{ __('Rules') }}</h2>
                    <a href="{{ $targets['rules'] }}">{{ $isAdmin ? __('Manage') : __('All') }} →</a>
                </div>
                <div class="rc-activity">
                    @forelse ($overview['rules'] as $rule)
                        <div class="rc-pad">
                            <span class="rc-tag">{{ $rule->kind->text() }}</span>
                            <strong>{{ $rule->title }}</strong>
                            <span class="rc-muted">
                                {{ collect([
                                    $rule->repository_id === null ? __('Every repository') : __('This repository'),
                                    $rule->paths ? __('only in :paths', ['paths' => implode(', ', $rule->paths)]) : null,
                                    $rule->kind === ReviewRuleKind::Check && $rule->severity ? __(':severity when broken', ['severity' => $severityLabel($rule->severity)]) : null,
                                ])->filter()->implode(' · ') }}
                            </span>
                        </div>
                    @empty
                        <span class="rc-muted rc-pad">{{ __('No rules yet.') }} {{ $isAdmin ? __('Add one above, or mark a finding "Not a problem" and make it a rule.') : '' }}</span>
                    @endforelse
                </div>
            </section>

            <section class="rc-card rc-grow-1" aria-labelledby="rc-reviewers">
                <div class="rc-card-bar">
                    <h2 id="rc-reviewers">{{ __('Reviewers') }}</h2>
                </div>
                <div class="rc-scroll">
                    <table class="rc-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Reviewer') }}</th>
                                <th scope="col" class="rc-num">{{ __('Reviews') }}</th>
                                <th scope="col" class="rc-num">{{ __('Accepted') }}</th>
                                <th scope="col" class="rc-num">{{ __('Median') }}</th>
                                <th scope="col" class="rc-num">{{ __('Cost') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($overview['reviewers'] as $reviewer)
                                <tr>
                                    <td>{{ $reviewer['name'] }}</td>
                                    <td class="rc-num">{{ $reviewer['reviews'] }}</td>
                                    <td class="rc-num"><strong class="rc-tone-primary">{{ $reviewer['accepted'] }}</strong></td>
                                    <td class="rc-num">{{ $reviewer['median'] ?? '—' }}</td>
                                    <td class="rc-num">{{ Currency::show($reviewer['cost']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="rc-muted">{{ __('No reviews in the period.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</x-filament-panels::page>
