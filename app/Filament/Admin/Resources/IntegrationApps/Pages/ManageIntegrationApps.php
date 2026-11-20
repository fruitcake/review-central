<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\IntegrationApps\Pages;

use App\Connections\Integrations;
use App\Filament\Admin\Resources\IntegrationApps\IntegrationAppResource;
use App\Filament\Components\IntegrationAppForm;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageIntegrationApps extends ManageRecords
{
    protected static string $resource = IntegrationAppResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $fromEnv = collect(Integrations::TYPES)->keys()->filter(fn (string $type): bool => Integrations::fromEnv($type) !== null)
            ->map(fn (string $type): string => Integrations::TYPES[$type]['service'])->implode(', ');

        return 'The apps a person signs in at a service through, instead of a pasted token. Signing in with Google, and for now the GitHub App, has one; of Asana and Atlassian there can be several, each for every tenant or for the tenants picked. A tenant can add its own under its Apps.'
            .($fromEnv === '' ? '' : " .env sets one for {$fromEnv} as well, for every tenant; for Google and the GitHub App it goes before the one here.");
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add app')
                ->mutateDataUsing(fn (array $data): array => IntegrationAppResource::platform(IntegrationAppForm::save($data))),
        ];
    }
}
