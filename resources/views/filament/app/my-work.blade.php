@php
    use App\Filament\App\Resources\PullRequests\PullRequestResource;
    use App\Filament\App\Resources\Repositories\RepositoryResource;
    use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
    use App\Filament\Auth\EditProfile;
    use App\Filament\RunPresenter;

    $hostUrl = fn ($record): ?string => rescue(fn (): string => $record->repository->sourceControl()->pullRequestUrl($record->pull_request_id), null, false);
@endphp

<x-filament-panels::page>
    <div class="rc-stack">
        @if ($repositories->isEmpty() && $turn->isEmpty())
            <section class="rc-card rc-pad">
                <strong>{{ __('Nothing of yours found yet') }}</strong>
                <p class="rc-muted">
                    {{ __('This page lists the pull requests you opened or were asked to review, by the Bitbucket or GitHub account on your profile.') }}
                    <a href="{{ EditProfile::urlForCurrentUser() }}">{{ __('Connect or name your account') }} →</a>
                </p>
            </section>
        @endif

        <section class="rc-card" aria-labelledby="rc-my-turn">
            <div class="rc-card-bar">
                <h2 id="rc-my-turn">{{ __('Your turn') }}</h2>
                <a href="{{ $targets['turn'] }}">{{ __('All with you') }} →</a>
            </div>
            <div class="rc-scroll">
                <table class="rc-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Pull request') }}</th>
                            <th scope="col">{{ __('What to do') }}</th>
                            <th scope="col">{{ __('AI review') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($turn as $record)
                            <tr>
                                <td><div class="rc-turn">
                                    @if ($url = $hostUrl($record))
                                        <strong><a href="{{ $url }}" target="_blank" rel="noopener">{{ $record->title ?? 'Untitled' }}</a></strong>
                                    @else
                                        <strong>{{ $record->title ?? 'Untitled' }}</strong>
                                    @endif
                                    <span class="rc-muted">{{ $record->repository->slug }} #{{ $record->pull_request_id }} · {{ $record->author ?? __('Someone') }}</span>
                                </div></td>
                                <td>{{ PullRequestResource::turnCell($record) }}</td>
                                <td>{{ ReviewRunResource::chip(...PullRequestResource::standing($record)) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="rc-muted">{{ __('Nothing waits for you.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rc-card" aria-labelledby="rc-my-pull-requests">
            <div class="rc-card-bar">
                <h2 id="rc-my-pull-requests">{{ __('Your pull requests') }}</h2>
                <a href="{{ $targets['pullRequests'] }}">{{ __('Of your repositories') }} →</a>
            </div>
            <div class="rc-scroll">
                <table class="rc-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Pull request') }}</th>
                            <th scope="col">{{ __('Whose turn') }}</th>
                            <th scope="col">{{ __('AI review') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($opened as $record)
                            <tr>
                                <td><div class="rc-turn">
                                    @if ($url = $hostUrl($record))
                                        <strong><a href="{{ $url }}" target="_blank" rel="noopener">{{ $record->title ?? 'Untitled' }}</a></strong>
                                    @else
                                        <strong>{{ $record->title ?? 'Untitled' }}</strong>
                                    @endif
                                    <span class="rc-muted">{{ $record->repository->slug }} #{{ $record->pull_request_id }}@if ($record->draft) · {{ __('Draft') }} @endif</span>
                                </div></td>
                                <td>{{ PullRequestResource::turnCell($record) }}</td>
                                <td>{{ ReviewRunResource::chip(...PullRequestResource::standing($record)) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="rc-muted">{{ __('No open pull requests of yours.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="rc-row">
            <section class="rc-card rc-grow-2" aria-labelledby="rc-my-repositories">
                <div class="rc-card-bar">
                    <h2 id="rc-my-repositories">{{ __('Your repositories') }}</h2>
                    <a href="{{ $targets['repositories'] }}">{{ __('All of yours') }} →</a>
                </div>
                <div class="rc-scroll">
                    <table class="rc-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Repository') }}</th>
                                <th scope="col" class="rc-num">{{ __('Open PRs') }}</th>
                                <th scope="col" class="rc-num">{{ __('Open findings') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($repositories as $repository)
                                <tr>
                                    <td><strong><a href="{{ RepositoryResource::getUrl('view', ['record' => $repository]) }}">{{ $repository->slug }}</a></strong></td>
                                    <td class="rc-num"><a href="{{ RepositoryResource::pullRequestsUrl($repository) }}">{{ $repository->open_count }}</a></td>
                                    <td class="rc-num">{{ $repository->findings_count }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="rc-muted">{{ __('None yet: a repository is yours once you opened or reviewed one of its pull requests.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($repositories->sum('findings_count') > 0)
                    <div class="rc-pad"><a href="{{ $targets['findings'] }}">{{ __('Open findings of your repositories') }} →</a></div>
                @endif
            </section>

            <section class="rc-card rc-grow-1" aria-labelledby="rc-my-reviews">
                <div class="rc-card-bar">
                    <h2 id="rc-my-reviews">{{ __('Latest reviews') }}</h2>
                    <a href="{{ $targets['reviews'] }}">{{ __('All') }} →</a>
                </div>
                <div class="rc-activity">
                    @forelse ($reviews as $run)
                        @php($outcome = RunPresenter::outcome($run))
                        <a href="{{ ReviewRunResource::getUrl('view', ['record' => $run]) }}">
                            <span class="rc-dot rc-dot-{{ $outcome['tone'] }}"></span>
                            <span>
                                <strong>{{ $run->slug }}@if ($run->pull_request_id) #{{ $run->pull_request_id }}@endif · {{ $outcome['label'] }}</strong>
                                <span class="rc-muted">{{ $run->branch }} · {{ $run->started_at->diffForHumans() }}</span>
                            </span>
                        </a>
                    @empty
                        <span class="rc-muted rc-pad">{{ __('No reviews of your repositories yet.') }}</span>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</x-filament-panels::page>
