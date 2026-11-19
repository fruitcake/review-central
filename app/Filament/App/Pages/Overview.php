<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\AiProviders\AiProviderResource;
use App\Filament\App\Resources\Repositories\RepositoryResource;
use App\Filament\App\Resources\ReviewInstructions\ReviewInstructionResource;
use App\Filament\App\Resources\ReviewRuns\ReviewRunResource;
use App\Filament\App\Resources\SourceControl\SourceControlResource;
use App\Filament\Concerns\RefreshesOnBroadcast;
use App\Filament\CurrentTenant;
use App\Services\TenantOverview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

class Overview extends Dashboard
{
    use RefreshesOnBroadcast;

    public const PERIODS = [7, 30, 90];

    protected static ?string $title = 'Overview';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.app.overview';

    #[Url]
    public int $days = 30;

    public function getSubheading(): string|Htmlable|null
    {
        return CurrentTenant::get()->name." · last {$this->period()} days";
    }

    protected function getHeaderActions(): array
    {
        $periods = array_map(fn (int $days): Action => Action::make("period{$days}")
            ->label("{$days}d")
            ->color($this->period() === $days ? 'primary' : 'gray')
            ->outlined($this->period() !== $days)
            ->action(fn () => $this->days = $days), self::PERIODS);

        return [
            ...$periods,
            Action::make('addRepository')
                ->label('Add repository')
                ->icon(Heroicon::OutlinedPlus)
                ->url(RepositoryResource::getUrl()),
        ];
    }

    /**
     * A period typed into the address counts only when it is one of the three.
     */
    protected function period(): int
    {
        return in_array($this->days, self::PERIODS, true) ? $this->days : 30;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'overview' => app(TenantOverview::class)->for(CurrentTenant::get(), $this->period()),
            'targets' => [
                'source-control' => SourceControlResource::getUrl(),
                'ai-providers' => AiProviderResource::getUrl(),
                'repositories' => RepositoryResource::getUrl(),
                'instructions' => ReviewInstructionResource::getUrl(),
                'action' => ReviewRunResource::getUrl('index', ['tab' => 'action']),
                'failed' => ReviewRunResource::getUrl('index', ['tab' => 'failed']),
                'reviews' => ReviewRunResource::getUrl(),
            ],
        ];
    }
}
