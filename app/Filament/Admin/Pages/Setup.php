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

    public static function getNavigationBadge(): ?string
    {
        // Kept for a minute: the menu is on every page, and one of the checks asks another server.
        $wrong = rescue(fn (): int => (int) Cache::remember('setup-check:wrong', 60, fn (): int => app(SetupCheck::class)->wrong()), 0, false);

        return $wrong === 0 ? null : (string) $wrong;
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
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['groups' => app(SetupCheck::class)->groups()];
    }
}
