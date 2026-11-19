<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Notifications\Invitation;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Str;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->modalDescription('The user is mailed a link to choose their own password.')
                // Nobody knows this one: the invitation is the way in.
                ->mutateDataUsing(fn (array $data): array => [...$data, 'password' => Str::password(40)])
                ->after(fn (User $record) => $record->notify(new Invitation(auth()->user()?->name)))
                ->successNotificationTitle('Created, and the invitation is sent'),
        ];
    }
}
