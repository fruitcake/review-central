<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Services\Capacity;
use App\Services\PlatformOverview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Dashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

class Platform extends Dashboard
{
    /** Days to look back, by what the button says. */
    public const PERIODS = [1 => '24h', 7 => '7d', 30 => '30d'];

    protected static ?string $title = 'Platform';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.admin.platform';

    #[Url]
    public int $days = 7;

    public function getSubheading(): string|Htmlable|null
    {
        return 'All tenants · last '.($this->period() === 1 ? '24 hours' : "{$this->period()} days");
    }

    protected function getHeaderActions(): array
    {
        $periods = [];

        foreach (self::PERIODS as $days => $label) {
            $periods[] = Action::make("period{$days}")
                ->label($label)
                ->color($this->period() === $days ? 'primary' : 'gray')
                ->outlined($this->period() !== $days)
                ->action(fn () => $this->days = $days);
        }

        return [
            ...$periods,
            Action::make('tenants')->label('Tenants')->icon(Heroicon::OutlinedPlus)->url(TenantResource::getUrl()),
        ];
    }

    protected function period(): int
    {
        return isset(self::PERIODS[$this->days]) ? $this->days : 7;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $since = now()->subDays($this->period());

        return [
            'platform' => app(PlatformOverview::class)->for($since),
            'capacity' => app(Capacity::class)->report($since),
            'horizon' => config('review.container.connection') ? url(config('horizon.path', 'horizon')) : null,
        ];
    }
}
