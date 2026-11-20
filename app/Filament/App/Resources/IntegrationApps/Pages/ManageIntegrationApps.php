<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\IntegrationApps\Pages;

use App\Connections\Integrations;
use App\Filament\App\Resources\IntegrationApps\IntegrationAppResource;
use App\Filament\Components\IntegrationAppForm;
use App\Filament\CurrentTenant;
use App\Models\IntegrationApp;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageIntegrationApps extends ManageRecords
{
    protected static string $resource = IntegrationAppResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $tenant = CurrentTenant::get();
        $shared = collect(['asana', 'atlassian', 'github_app'])
            ->flatMap(fn (string $type): array => array_map(
                fn ($app): string => $app->name.' ('.Integrations::TYPES[$type]['service'].')',
                array_filter(Integrations::available($type, $tenant), fn ($app): bool => ! IntegrationApp::query()->whereKey($app->key)->where('tenant_id', $tenant->getKey())->exists()),
            ))
            ->implode(', ');

        return 'Your own apps at Asana or Atlassian, for this team alone: a connection signs in there through one instead of taking a pasted token.'
            .($shared === '' ? '' : " The installation's, for this team too: {$shared}.");
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add app')
                // Its own: for this tenant alone, whatever the form held.
                ->mutateDataUsing(fn (array $data): array => ['tenant_id' => CurrentTenant::get()->getKey(), 'for_all_tenants' => false] + IntegrationAppForm::save($data)),
        ];
    }
}
