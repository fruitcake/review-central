<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\IntegrationApps\Pages;

use App\Connections\Integrations;
use App\Connections\OAuthApp;
use App\Filament\App\Resources\IntegrationApps\IntegrationAppResource;
use App\Filament\Components\IntegrationAppForm;
use App\Filament\CurrentTenant;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageIntegrationApps extends ManageRecords
{
    protected static string $resource = IntegrationAppResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $tenant = CurrentTenant::get();
        // Its own apps' keys, once: compared as text, as .env's key is no id.
        $own = $tenant->integrationApps()->pluck('id')->map(fn (int $id): string => (string) $id)->all();
        $shared = collect(['asana', 'atlassian', 'github_app'])
            ->flatMap(fn (string $type): array => array_map(
                fn (OAuthApp $app): string => $app->name.' ('.Integrations::TYPES[$type]['service'].')',
                array_filter(Integrations::available($type, $tenant), fn (OAuthApp $app): bool => ! in_array($app->key, $own, true)),
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
