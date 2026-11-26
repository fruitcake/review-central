<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Filament\CurrentTenant;
use App\Filament\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Invitation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who is in the tenant, and what each may do: for its admins to invite people,
 * change their role and take them out.
 */
class Members extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 92;

    public static function canAccess(): bool
    {
        return TenantRole::isAdmin();
    }

    public function getTitle(): string|Htmlable
    {
        return __('Members');
    }

    public static function getNavigationLabel(): string
    {
        return __('Members');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('An admin manages the connections and their keys, the apps, repositories, review settings, instructions and the members. A member reads the reviews, answers and rates their findings, asks the reviewer and starts a review.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            // The users themselves, with their role beside: a join would put the
            // membership's own id where the user's belongs.
            ->query(fn () => User::query()
                ->whereHas('tenants', fn ($query) => $query->whereKey(CurrentTenant::get()->getKey()))
                ->addSelect(['users.*', 'tenant_role' => DB::table('tenant_user')
                    ->select('role')
                    ->whereColumn('tenant_user.user_id', 'users.id')
                    ->where('tenant_user.tenant_id', CurrentTenant::get()->getKey())
                    ->limit(1)]))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                TextColumn::make('email')->label(__('Email'))->searchable(),
                TextColumn::make('role')
                    ->label(__('Role'))
                    ->state(fn (User $record): string => TenantRole::labels()[self::roleOf($record)])
                    ->badge()
                    ->color(fn (User $record): string => self::roleOf($record) === TenantRole::ADMIN ? 'primary' : 'gray'),
            ])
            ->headerActions([
                Action::make('invite')
                    ->label(__('Invite'))
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->schema([
                        TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(255),
                        TextInput::make('name')->label(__('Name'))->maxLength(255)->helperText(__('For someone who has no account here yet.')),
                        Select::make('role')->label(__('Role'))->options(TenantRole::labels())->default(TenantRole::MEMBER)->required()->selectablePlaceholder(false),
                    ])
                    ->action(fn (array $data) => $this->invite(CurrentTenant::get(), $data)),
            ])
            ->recordActions([
                Action::make('role')
                    ->label(__('Change role'))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('gray')
                    ->fillForm(fn (User $record): array => ['role' => self::roleOf($record)])
                    ->schema([Select::make('role')->label(__('Role'))->options(TenantRole::labels())->required()->selectablePlaceholder(false)])
                    ->action(function (User $record, array $data): void {
                        $tenant = CurrentTenant::get();

                        if ($data['role'] !== TenantRole::ADMIN && $this->lastAdmin($tenant, $record)) {
                            Notification::make()->title(__('The team keeps at least one admin'))->body(__('Make someone else an admin first.'))->danger()->send();

                            return;
                        }

                        $tenant->users()->updateExistingPivot($record->getKey(), ['role' => $data['role']]);
                        Notification::make()->title(__(':name is now :role', ['name' => $record->name, 'role' => Str::lower(TenantRole::labels()[$data['role']])]))->success()->send();
                    }),
                Action::make('remove')
                    ->label(__('Remove'))
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (User $record): string => __(':name can no longer open this team. Their account stays, for any other team they are in.', ['name' => $record->name]))
                    ->action(function (User $record): void {
                        $tenant = CurrentTenant::get();

                        if ($this->lastAdmin($tenant, $record)) {
                            Notification::make()->title(__('The team keeps at least one admin'))->body(__('Make someone else an admin first.'))->danger()->send();

                            return;
                        }

                        $tenant->users()->detach($record->getKey());
                        Notification::make()->title(__(':name is removed from the team', ['name' => $record->name]))->success()->send();
                    }),
            ]);
    }

    /**
     * Someone with an account joins with the role given; someone new gets an
     * account and is mailed a link to choose a password.
     *
     * @param  array<string, mixed>  $data  email, name and role, as the form has them
     */
    protected function invite(Tenant $tenant, array $data): void
    {
        $email = Str::lower(trim((string) ($data['email'] ?? '')));
        $role = is_string($data['role'] ?? null) && array_key_exists($data['role'], TenantRole::LABELS) ? $data['role'] : TenantRole::MEMBER;
        $user = User::query()->where('email', $email)->first();

        if ($user !== null && $tenant->users()->whereKey($user->getKey())->exists()) {
            Notification::make()->title(__(':email is in the team already', ['email' => $email]))->body(__('Change their role in the list.'))->warning()->send();

            return;
        }

        if ($user === null) {
            // Nobody knows this one: the invitation is the way in.
            $user = User::query()->create(['name' => filled($data['name'] ?? null) ? (string) $data['name'] : Str::before($email, '@'), 'email' => $email, 'password' => Str::password(40)]);
            $user->notify(new Invitation(auth()->user()?->name));
        }

        $tenant->users()->attach($user->getKey(), ['role' => $role]);

        Notification::make()->title(__(':email is in the team', ['email' => $email]))->body($user->wasRecentlyCreated ? __('They are mailed a link to choose a password.') : __('They can open it with the account they have.'))->success()->send();
    }

    /**
     * A member's role in the team, as the list reads it with them.
     */
    protected static function roleOf(User $user): string
    {
        $role = $user->getAttribute('tenant_role');

        return is_string($role) && array_key_exists($role, TenantRole::LABELS) ? $role : TenantRole::MEMBER;
    }

    /**
     * Whether the user is the team's only admin of its own: an administrator of
     * the platform does not count, as they are no member of it.
     */
    protected function lastAdmin(Tenant $tenant, User $user): bool
    {
        $admins = $tenant->users()->wherePivot('role', TenantRole::ADMIN)->pluck('users.id');

        return $admins->count() === 1 && $admins->first() === $user->getKey();
    }
}
