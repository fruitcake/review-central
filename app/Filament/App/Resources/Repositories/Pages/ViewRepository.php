<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\Repositories\Pages;

use App\Filament\App\Pages\Overview;
use App\Filament\App\Resources\OpenFindings\OpenFindingResource;
use App\Filament\App\Resources\Repositories\RepositoryResource;
use App\Filament\App\Resources\ReviewRules\ReviewRuleResource;
use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Filament\Concerns\RefreshesOnBroadcast;
use App\Filament\TenantRole;
use App\Models\Repository;
use App\Services\RepositoryOverview;
use App\Services\RepositorySetup;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * One repository at a glance: how its reviews went over a period, its open pull
 * requests and the findings left open on merged ones, and the rules its review
 * reads.
 *
 * @property Repository $record
 */
class ViewRepository extends ViewRecord
{
    use RefreshesOnBroadcast;

    protected static string $resource = RepositoryResource::class;

    protected string $view = 'filament.app.repository';

    #[Url]
    public int $days = 30;

    public function getTitle(): string|Htmlable
    {
        return $this->record->fullName();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return implode(' · ', array_filter([
            $this->record->name,
            $this->record->runner()->label(),
            collect($this->record->review_stack ?? [])->map(fn (string $stack): string => ucfirst($stack))->implode(', ') ?: null,
            $this->record->reviews_enabled ? null : 'Not reviewed by itself',
            "last {$this->period()} days",
        ]));
    }

    protected function getHeaderActions(): array
    {
        $periods = array_map(fn (int $days): Action => Action::make("period{$days}")
            ->label("{$days}d")
            ->color($this->period() === $days ? 'primary' : 'gray')
            ->outlined($this->period() !== $days)
            ->action(fn () => $this->days = $days), Overview::PERIODS);

        return [
            ...$periods,
            Action::make('addRule')
                ->label('Add a rule')
                ->icon(Heroicon::OutlinedScale)
                ->color('gray')
                ->visible(fn (): bool => TenantRole::isAdmin())
                ->fillForm(fn (): array => ['kind' => 'check', 'repository_id' => $this->record->getKey(), 'enabled' => true])
                ->schema(ReviewRuleResource::fields())
                ->action(function (array $data): void {
                    $this->record->tenant->reviewRules()->create($data);

                    Notification::make()->title('The rule is saved')->body('The next review reads it.')->success()->send();
                }),
            RepositoryResource::hostAction(),
            ActionGroup::make([
                RepositoryResource::fetchPullRequestsAction(),
                RepositoryResource::readinessAction()->label('Can it be reviewed?'),
                RepositoryResource::reviewSettingsAction(),
                EditAction::make()
                    ->using(fn (Repository $record, array $data, RepositorySetup $setup): Repository => $setup->update($record, $data)),
            ]),
        ];
    }

    /**
     * A period typed into the address counts only when it is one of the three.
     */
    protected function period(): int
    {
        return in_array($this->days, Overview::PERIODS, true) ? $this->days : 30;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $id = $this->record->getKey();

        return [
            'repository' => $this->record,
            'overview' => app(RepositoryOverview::class)->forRepository($this->record, $this->period()),
            'isAdmin' => TenantRole::isAdmin(),
            'targets' => [
                'reviews' => ReviewRunResource::getUrl('index', ['filters' => ['repository_id' => ['value' => $id]]]),
                'pullRequests' => RepositoryResource::pullRequestsUrl($this->record),
                'openFindings' => OpenFindingResource::getUrl('index', ['filters' => ['repository' => ['value' => $id]]]),
                'rules' => ReviewRuleResource::getUrl('index', ['filters' => ['repository_id' => ['value' => $id]]]),
            ],
        ];
    }
}
