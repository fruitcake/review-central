<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TagsInput;
use Filament\Schemas\Schema;

/**
 * The profile, with who the user is on the hosts: which pull requests are theirs
 * to act on is read from that.
 */
class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                TagsInput::make('source_accounts')
                    ->label('Your accounts on the hosts')
                    ->placeholder('A nickname, login or account ID')
                    ->nestedRecursiveRules(['string', 'max:120'])
                    ->helperText('Your Bitbucket nickname or account ID and your GitHub login, as the pull requests name you: "With me" under Pull requests lists the ones whose turn is yours. Without any, your name here is matched to the name the host shows.'),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }
}
