@php
    use App\Filament\App\Resources\Repositories\RepositoryResource;
    use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
    use App\Filament\RunPresenter;
    use App\Services\Currency;
@endphp

<x-filament-panels::page>
    <div class="rc-stack">
        @if ($overview['attention'] !== [])
            <section class="rc-attention" aria-labelledby="rc-attention">
                <div class="rc-attention-head">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/></svg>
                    <h2 id="rc-attention">Needs your attention</h2>
                    <span>{{ count($overview['attention']) }} {{ count($overview['attention']) === 1 ? 'item' : 'items' }}</span>
                </div>
                <div class="rc-attention-grid">
                    @foreach ($overview['attention'] as $item)
                        <div class="rc-attention-card">
                            <span class="rc-chip rc-chip-{{ $item['tone'] }}">{{ $item['tag'] }}</span>
                            <strong>{{ $item['title'] }}</strong>
                            <span class="rc-muted">{{ $item['body'] }}</span>
                            @if (in_array($item['target'], $adminOnly, true))
                                <span class="rc-muted">An admin of this team sets this up.</span>
                            @else
                                <a href="{{ $targets[$item['target']] }}">{{ $item['action'] }} →</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="rc-figures" aria-label="Key figures">
            @foreach ($overview['figures'] as $figure)
                <div class="rc-card rc-figure">
                    <span class="rc-muted">{{ $figure['label'] }}</span>
                    <span class="rc-figure-value">{{ $figure['value'] }}</span>
                    <span class="rc-figure-note rc-tone-{{ $figure['tone'] }}">{{ $figure['note'] }}</span>
                    @if ($figure['bar'] !== null)
                        <div class="rc-bar"><div class="rc-bar-fill rc-fill-{{ $figure['tone'] === 'danger' ? 'danger' : 'primary' }}" style="width: {{ $figure['bar'] }}%"></div></div>
                    @endif
                </div>
            @endforeach
        </section>

        <div class="rc-row">
            <section class="rc-card rc-pad rc-grow-2" aria-labelledby="rc-per-day">
                <div class="rc-card-head">
                    <div>
                        <h2 id="rc-per-day">Reviews per day</h2>
                        <span class="rc-muted">Finished reviews, by how they ended</span>
                    </div>
                    <div class="rc-legend">
                        <span><i class="rc-fill-primary"></i>Approved</span>
                        <span><i class="rc-fill-danger"></i>Changes requested</span>
                    </div>
                </div>
                <div class="rc-chart" role="img" aria-label="Finished reviews per day over the period">
                    @foreach ($overview['days']['bars'] as $bar)
                        <div class="rc-chart-day" title="{{ $bar['date'] }}: {{ $bar['approved'] }} approved, {{ $bar['changes'] }} changes requested">
                            <div class="rc-fill-danger" style="height: {{ $bar['changes'] / $overview['days']['max'] * 100 }}%"></div>
                            <div class="rc-fill-primary" style="height: {{ $bar['approved'] / $overview['days']['max'] * 100 }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="rc-chart-axis">
                    <span>{{ \Illuminate\Support\Carbon::parse($overview['days']['bars'][0]['date'])->format('j M') }}</span>
                    <span>{{ \Illuminate\Support\Carbon::parse($overview['days']['bars'][intdiv(count($overview['days']['bars']), 2)]['date'])->format('j M') }}</span>
                    <span>{{ \Illuminate\Support\Carbon::parse(last($overview['days']['bars'])['date'])->format('j M') }}</span>
                </div>
            </section>

            <section class="rc-card rc-pad rc-grow-1" aria-labelledby="rc-categories">
                <div>
                    <h2 id="rc-categories">Findings by category</h2>
                    <span class="rc-muted">How often the team accepts what the reviewer finds</span>
                </div>
                @foreach ($overview['categories'] as $category)
                    <div class="rc-category">
                        <div class="rc-category-head">
                            <strong>{{ $category['name'] }}</strong>
                            <span>
                                {{ $category['findings'] }} {{ $category['findings'] === 1 ? 'finding' : 'findings' }}
                                @if ($category['rated'] > 0)
                                    · <strong class="rc-tone-primary">{{ round($category['accepted'] / $category['rated'] * 100) }}% accepted</strong>
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
                    <span><i class="rc-fill-primary"></i>Accepted</span>
                    <span><i class="rc-fill-grey"></i>Dismissed</span>
                    <span class="rc-muted">of the findings people rated</span>
                </div>
            </section>
        </div>

        <div class="rc-row">
            <section class="rc-card rc-grow-2" aria-labelledby="rc-repositories">
                <div class="rc-card-bar">
                    <h2 id="rc-repositories">Repositories</h2>
                    <a href="{{ $targets['repositories'] }}">All {{ count($overview['repositories']) }} repositories →</a>
                </div>
                <div class="rc-scroll">
                    <table class="rc-table">
                        <thead>
                            <tr>
                                <th scope="col">Repository</th>
                                <th scope="col">Runs on</th>
                                <th scope="col" class="rc-num">Reviews</th>
                                <th scope="col" class="rc-num">Changes requested</th>
                                <th scope="col" class="rc-num">Accepted</th>
                                <th scope="col" class="rc-num">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($overview['repositories'] as $row)
                                <tr>
                                    <td>
                                        <div class="rc-repository">
                                            <span class="rc-dot {{ $row['ready'] ? 'rc-fill-primary' : 'rc-fill-danger' }}" title="{{ $row['ready'] ? 'Ready to review' : 'Cannot start reviews yet' }}"></span>
                                            <span>
                                                <strong><a href="{{ RepositoryResource::getUrl('view', ['record' => $row['repository']]) }}">{{ $row['repository']->slug }}</a></strong>
                                                <span class="rc-muted">{{ collect($row['repository']->review_stack ?? [])->map(fn (string $stack): string => ucfirst($stack))->implode(' · ') ?: 'No stack' }}</span>
                                            </span>
                                        </div>
                                    </td>
                                    <td><span class="rc-tag">{{ $row['repository']->runner()->label() }}</span></td>
                                    <td class="rc-num">{{ $row['reviews'] }}</td>
                                    <td class="rc-num">{{ $row['changes'] }}</td>
                                    <td class="rc-num"><strong class="rc-tone-primary">{{ $row['accepted'] }}</strong></td>
                                    <td class="rc-num">{{ Currency::show($row['cost']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="rc-muted">No repositories yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rc-card rc-grow-1" aria-labelledby="rc-activity">
                <div class="rc-card-bar">
                    <h2 id="rc-activity">Latest reviews</h2>
                    <a href="{{ $targets['reviews'] }}">All →</a>
                </div>
                <div class="rc-activity">
                    @forelse ($overview['activity'] as $run)
                        @php($outcome = RunPresenter::outcome($run))
                        <a href="{{ ReviewRunResource::getUrl('view', ['record' => $run]) }}">
                            <span class="rc-dot rc-dot-{{ $outcome['tone'] }}"></span>
                            <span>
                                <strong>{{ $run->slug }}@if ($run->pull_request_id) #{{ $run->pull_request_id }}@endif · {{ $outcome['label'] }}</strong>
                                <span class="rc-muted">
                                    {{ collect([RunPresenter::duration($run->duration_seconds), $run->cost === null ? null : Currency::show($run->cost), $run->started_at->diffForHumans()])->filter()->implode(' · ') }}
                                </span>
                            </span>
                        </a>
                    @empty
                        <span class="rc-muted rc-pad">No reviews yet.</span>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</x-filament-panels::page>
