<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Components\SetupGuideAction;
use App\Jobs\DeployCloudflareWorker;
use App\Jobs\PrepareCloudflareSandbox;
use App\Runners\Container\CloudflareSandbox;
use App\Runners\Container\WorkerDeployment;
use App\Services\SetupCheck;
use App\Support\SetupGuide;
use App\Support\SetupGuides;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

/**
 * Whether this installation is set up to review, with what to do about each
 * thing that is not.
 */
class Setup extends Page
{
    protected static ?string $title = 'Setup';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.admin.setup';

    /** The count of checks that are not right, with when it was made: see the badge. */
    public const WRONG_CACHE = 'setup-check:wrong';

    /** Whether the checks have been run since the page loaded. */
    public bool $checked = false;

    /**
     * The count of the last time the checks ran. The menu is on every page and the
     * checks ask the Worker and the bucket, so they never run in the page's own
     * request: a count older than a minute, or none, is made again after the
     * response has gone out, and the badge shows the old one until then.
     */
    public static function getNavigationBadge(): ?string
    {
        // The menu is on every admin page: a cache that cannot be reached hides the badge, no more.
        return rescue(function (): ?string {
            $counted = Cache::get(self::WRONG_CACHE);

            if ((! is_array($counted) || $counted['at'] < now()->subMinute()->getTimestamp()) && Cache::add(self::WRONG_CACHE.':counting', true, 60)) {
                defer(fn () => rescue(function (): void {
                    try {
                        self::remember(app(SetupCheck::class)->groups());
                    } finally {
                        Cache::forget(self::WRONG_CACHE.':counting');
                    }
                }, report: false));
            }

            $wrong = is_array($counted) ? (int) $counted['count'] : 0;

            return $wrong === 0 ? null : (string) $wrong;
        }, null, false);
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public function getSubheading(): string
    {
        return 'What is in place for reviews to run, and what to do about what is not.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('askWorker')
                ->label('Ask the worker')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (): bool => (bool) config('review.docker.enabled') || CloudflareSandbox::configured())
                ->action(function (SetupCheck $setup): void {
                    $setup->askWorker();

                    Notification::make()->title('The worker is asked')->body('Its answer shows here once it has looked: refresh in a moment. No answer within a minute means no worker is listening.')->success()->send();
                }),
            // This installation's copy of the Worker, deployed with the account and
            // token under Settings: the first time, and after an update.
            Action::make('deployWorker')
                ->label('Deploy the Worker')
                ->icon(Heroicon::OutlinedCloudArrowUp)
                ->color('gray')
                ->visible(fn (): bool => WorkerDeployment::ready())
                ->requiresConfirmation()
                ->modalDescription('Wrangler deploys the Cloudflare Worker from this installation\'s copy, makes the bucket when it is not there, and gives the Worker the token this installation asks it with. On the reviews\' queue, whose worker needs Node 20 or later; a first deploy installs Wrangler and takes a few minutes.')
                ->action(function (): void {
                    DeployCloudflareWorker::ask();

                    Notification::make()->title('The Worker is being deployed')->body('On the reviews\' queue. This page says how it went: refresh in a few minutes.')->success()->send();
                }),
            // The sandbox's tools, installed and kept as a snapshot before the first
            // review needs them, instead of by it.
            Action::make('prepareSandbox')
                ->label('Prepare the sandbox')
                ->icon(Heroicon::OutlinedCube)
                ->color('gray')
                ->visible(fn (): bool => CloudflareSandbox::configured() && CloudflareSandbox::setupScript() !== null)
                ->requiresConfirmation()
                ->modalDescription('A Cloudflare sandbox installs PHP, Composer and the reviewers, and the Worker keeps a snapshot for reviews to start from. That takes a few minutes and is done once for each version of the setup script; nothing happens when the snapshot is there.')
                ->action(function (): void {
                    PrepareCloudflareSandbox::ask();

                    Notification::make()->title('The sandbox is being prepared')->body('On the reviews\' queue. This page says when it is done: refresh in a few minutes.')->success()->send();
                }),
        ];
    }

    /**
     * "How to get this" on a check that names a guide: where its keys are made,
     * the steps and the scopes, without leaving the page for the docs.
     */
    public function guideAction(): Action
    {
        return SetupGuideAction::make(fn (array $arguments, SetupGuides $guides): ?SetupGuide => $guides->named((string) ($arguments['guide'] ?? '')), 'guide');
    }

    /**
     * Runs the checks once the page is there, instead of before it is sent.
     */
    public function check(): void
    {
        $this->checked = true;
    }

    /**
     * @param  array<int, array{title: string, checks: array<int, array{state: string}>}>  $groups
     */
    protected static function remember(array $groups): void
    {
        Cache::put(self::WRONG_CACHE, ['count' => app(SetupCheck::class)->wrong($groups), 'at' => now()->getTimestamp()], now()->addDay());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        if (! $this->checked) {
            return ['groups' => null];
        }

        $groups = app(SetupCheck::class)->groups();
        // What this page found is what the badge says from now on.
        self::remember($groups);

        return ['groups' => $groups];
    }
}
