@php
    use App\Filament\RunPresenter;
    use Filament\Facades\Filament;

    $time = fn (?array $spread, string $key): string => $spread === null ? '—' : (string) RunPresenter::duration($spread[$key]);
    $percent = fn (?int $value): string => $value === null ? '—' : "{$value}%";
    $trend = $capacity['trend'];
    $tallest = max(1, ...array_map(fn (array $slice): int => max($slice['running'] + $slice['waiting'] + $slice['held'], $slice['workers']), $trend ?: [['running' => 0, 'waiting' => 0, 'held' => 0, 'workers' => 1]]));
@endphp

<x-filament-panels::page>
    <div class="rc-stack">
        <section class="rc-figures" aria-label="Platform figures">
            @foreach ($platform['figures'] as $figure)
                <div class="rc-card rc-figure">
                    <span class="rc-muted">{{ $figure['label'] }}</span>
                    <span class="rc-figure-value">{{ $figure['value'] }}</span>
                    <span class="rc-figure-note rc-tone-{{ $figure['tone'] }}">{{ $figure['note'] }}</span>
                </div>
            @endforeach
        </section>

        <section class="rc-card" aria-labelledby="rc-tenants">
            <div class="rc-card-bar">
                <h2 id="rc-tenants">Tenants</h2>
                <a href="{{ \App\Filament\Admin\Resources\Tenants\TenantResource::getUrl() }}">Manage tenants →</a>
            </div>
            <div class="rc-scroll">
                <table class="rc-table">
                    <thead>
                        <tr>
                            <th scope="col">Tenant</th>
                            <th scope="col">Connections</th>
                            <th scope="col" class="rc-num">Repos</th>
                            <th scope="col" class="rc-num">Reviews</th>
                            <th scope="col" class="rc-num">Accepted</th>
                            <th scope="col">Cost this month</th>
                            <th scope="col">Health</th>
                            <th scope="col" aria-label="Actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($platform['tenants'] as $row)
                            <tr>
                                <td>
                                    <div class="rc-repository">
                                        <span class="rc-initials" aria-hidden="true">{{ str($row['tenant']->name)->substr(0, 2)->upper() }}</span>
                                        <span>
                                            <strong>{{ $row['tenant']->name }}</strong>
                                            <span class="rc-muted">{{ $row['tenant']->users_count }} {{ $row['tenant']->users_count === 1 ? 'user' : 'users' }}</span>
                                        </span>
                                    </div>
                                </td>
                                <td>{{ $row['connections'] }}</td>
                                <td class="rc-num">{{ $row['tenant']->repositories_count }}</td>
                                <td class="rc-num">{{ $row['reviews'] }}</td>
                                <td class="rc-num"><strong class="rc-tone-primary">{{ $row['accepted'] }}</strong></td>
                                <td>
                                    <div class="rc-budget">
                                        <span>{{ $row['money'] }}</span>
                                        @if ($row['percent'] !== null)
                                            <div class="rc-bar"><div class="rc-bar-fill {{ $row['percent'] >= \App\Services\PlatformOverview::NEAR_BUDGET_PERCENT ? 'rc-fill-danger' : 'rc-fill-primary' }}" style="width: {{ min(100, $row['percent']) }}%"></div></div>
                                        @else
                                            <span class="rc-muted">No budget set</span>
                                        @endif
                                    </div>
                                </td>
                                <td><span class="rc-chip rc-chip-{{ $row['health']['tone'] }}">{{ $row['health']['label'] }}</span></td>
                                <td class="rc-num"><a href="{{ Filament::getPanel('app')->getUrl($row['tenant']) }}">Open as tenant</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="rc-muted">No tenants yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rc-card rc-pad" aria-labelledby="rc-capacity">
            <div class="rc-card-head">
                <div>
                    <h2 id="rc-capacity">Capacity</h2>
                    <span class="rc-muted">
                        Reviews on this server's workers, on Docker or in a Cloudflare sandbox ·
                        @if ($capacity['now'] === null)
                            no measurements yet
                        @else
                            now <strong class="rc-tone-primary">{{ $capacity['now']['running'] }} of {{ $capacity['now']['workers'] }} workers busy</strong>{{ $capacity['now']['running_sandboxes'] > 0 ? " ({$capacity['now']['running_sandboxes']} in a sandbox)" : '' }}, {{ $capacity['now']['waiting'] }} waiting for a worker, {{ $capacity['now']['held'] }} behind another review
                        @endif
                    </span>
                </div>
                @if ($horizon !== null)
                    <a href="{{ $horizon }}" target="_blank" rel="noopener">Open Horizon ↗</a>
                @endif
            </div>

            <div class="rc-advice">
                @foreach ($capacity['advice'] as $advice)
                    <div class="rc-advice-item">
                        <span class="rc-chip rc-chip-{{ $advice['tone'] }}">{{ $advice['tone'] === 'approved' ? 'No action' : ($advice['tone'] === 'neutral' ? 'Waiting for data' : 'Upgrade') }}</span>
                        <span><strong>{{ $advice['title'] }}</strong><span class="rc-muted">{{ $advice['body'] }}</span></span>
                    </div>
                @endforeach
            </div>

            <div class="rc-figures rc-figures-plain">
                <div class="rc-figure">
                    <span class="rc-muted">Wait for a worker</span>
                    <span class="rc-figure-value">{{ $time($capacity['wait'], 'average') }}</span>
                    <span class="rc-figure-note rc-tone-neutral">average · p90 {{ $time($capacity['wait'], 'p90') }} · longest {{ $time($capacity['wait'], 'max') }}</span>
                </div>
                <div class="rc-figure">
                    <span class="rc-muted">Review time</span>
                    <span class="rc-figure-value">{{ $time($capacity['duration'], 'average') }}</span>
                    <span class="rc-figure-note rc-tone-neutral">average · p90 {{ $time($capacity['duration'], 'p90') }} · {{ $capacity['reviews'] }} finished{{ $capacity['sandbox_share'] > 0 ? " · {$capacity['sandbox_share']}% in a sandbox" : '' }}</span>
                </div>
                <div class="rc-figure">
                    <span class="rc-muted">All workers busy</span>
                    <span class="rc-figure-value">{{ $percent($capacity['saturated']) }}</span>
                    <span class="rc-figure-note rc-tone-neutral">of the time · peak {{ $capacity['peak_running'] ?? '—' }} side by side</span>
                </div>
                <div class="rc-figure">
                    <span class="rc-muted">Waiting for a worker</span>
                    <span class="rc-figure-value">{{ $percent($capacity['queued']) }}</span>
                    <span class="rc-figure-note rc-tone-neutral">of the time · at most {{ $capacity['peak_waiting'] ?? '—' }} at once · {{ $capacity['peak_held'] ?? '—' }} behind another review</span>
                </div>
                <div class="rc-figure">
                    <span class="rc-muted">Processor</span>
                    <span class="rc-figure-value">{{ $percent($capacity['cpu']['average']) }}</span>
                    <span class="rc-figure-note rc-tone-neutral">average load · peak {{ $percent($capacity['cpu']['peak']) }} · {{ $capacity['cpu']['cpus'] ?? '—' }} cores{{ $capacity['sandbox_share'] > 0 ? ' · of this server; a sandbox uses its own' : '' }}</span>
                </div>
                <div class="rc-figure">
                    <span class="rc-muted">Memory</span>
                    <span class="rc-figure-value">{{ $percent($capacity['memory']['average']) }}</span>
                    <span class="rc-figure-note rc-tone-neutral">average · peak {{ $percent($capacity['memory']['peak']) }} · disk {{ $percent($capacity['disk']) }}</span>
                </div>
            </div>

            @if ($trend !== [])
                <div>
                    <div class="rc-card-head">
                        <span class="rc-muted">Reviews side by side over the period; the line is the number of workers</span>
                        <div class="rc-legend">
                            <span><i class="rc-fill-primary"></i>Running on Docker</span>
                            <span><i class="rc-fill-sky"></i>Running in a Cloudflare sandbox</span>
                            <span><i class="rc-fill-danger"></i>Waiting for a worker</span>
                            <span><i class="rc-fill-grey"></i>Behind another review of its pull request</span>
                        </div>
                    </div>
                    <div class="rc-chart rc-chart-short" role="img" aria-label="Reviews running, waiting for a worker and held behind another review, over the period">
                        <div class="rc-chart-line" style="bottom: {{ ($trend[0]['workers'] ?? 0) / $tallest * 100 }}%"></div>
                        @foreach ($trend as $slice)
                            <div class="rc-chart-day" title="{{ $slice['at'] }}: {{ $slice['running'] }} running ({{ $slice['sandboxes'] }} in a sandbox), {{ $slice['waiting'] }} waiting for a worker, {{ $slice['held'] }} behind another review{{ $slice['load'] === null ? '' : ", processor {$slice['load']}%" }}">
                                <div class="rc-fill-grey" style="height: {{ $slice['held'] / $tallest * 100 }}%"></div>
                                <div class="rc-fill-danger" style="height: {{ $slice['waiting'] / $tallest * 100 }}%"></div>
                                <div class="rc-fill-sky" style="height: {{ min($slice['sandboxes'], $slice['running']) / $tallest * 100 }}%"></div>
                                <div class="rc-fill-primary" style="height: {{ max(0, $slice['running'] - $slice['sandboxes']) / $tallest * 100 }}%"></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="rc-chart-axis"><span>{{ $trend[0]['at'] }}</span><span>{{ end($trend)['at'] }}</span></div>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
