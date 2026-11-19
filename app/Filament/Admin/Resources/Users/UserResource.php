<?php

namespace App\Filament\Admin\Resources\Users;

use App\Filament\Admin\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Notifications\Invitation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->minLength(12)
                    // A new user chooses their own, from the invitation.
                    ->visibleOn('edit')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('Leave empty to keep the current password.'),
                Select::make('tenants')->relationship('tenants', 'name')->multiple()->preload(),
                Toggle::make('is_admin')
                    ->label('Administrator')
                    ->helperText('May manage tenants and users, and open every tenant.'),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('tenants.name')->badge(),
                IconColumn::make('is_admin')->label('Administrator')->boolean(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('invite')
                        ->label('Send invitation')
                        ->icon(Heroicon::OutlinedEnvelope)
                        ->requiresConfirmation()
                        ->modalDescription(fn (User $record): string => "Mails {$record->email} a link to choose a password, which works for ".Invitation::VALID_DAYS.' days. Their current password keeps working until they do.')
                        ->action(function (User $record): void {
                            $record->notify(new Invitation(auth()->user()?->name));

                            Notification::make()->title("Invitation sent to {$record->email}")->success()->send();
                        }),
                    Action::make('passwordReset')
                        ->label('Send password reset')
                        ->icon(Heroicon::OutlinedKey)
                        ->requiresConfirmation()
                        ->modalDescription(fn (User $record): string => "Mails {$record->email} the link \"Forgot password?\" sends, which works for an hour. Their current password keeps working until they choose another.")
                        ->action(function (User $record): void {
                            $panel = $record->panel();
                            $token = Password::broker($panel->getAuthPasswordBroker())->createToken($record);

                            $notification = app(ResetPassword::class, ['token' => $token]);
                            $notification->url = $panel->getResetPasswordUrl($token, $record);
                            $record->notify($notification);

                            Notification::make()->title("Password reset sent to {$record->email}")->success()->send();
                        }),
                ])->icon(Heroicon::OutlinedEnvelope)->tooltip('Mail a way to sign in'),
                EditAction::make(),
                DeleteAction::make()->hidden(fn (User $record): bool => $record->is(auth()->user())),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
