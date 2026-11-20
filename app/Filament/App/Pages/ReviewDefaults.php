<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Enums\ReviewCommandAccess;
use App\Filament\Components\ReviewSettingsForm;
use App\Filament\CurrentTenant;
use App\Filament\TenantRole;
use App\Services\ReviewSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The tenant's review settings: which reviewer runs, at what model and effort, for
 * every repository that does not set its own; and who may start a review by
 * commenting `/review`, which holds for all its repositories.
 *
 * @property-read Schema $form
 */
class ReviewDefaults extends Page
{
    /** The tenant's review settings are its admins' to change. */
    public static function canAccess(): bool
    {
        return TenantRole::isAdmin();
    }

    protected static ?string $title = 'Review defaults';

    protected static ?string $slug = 'review-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 35;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $tenant = CurrentTenant::get();

        $this->form->fill(ReviewSettingsForm::state(ReviewSettings::defaults($tenant)) + [
            'review_command' => $tenant->review_command->value,
            'review_command_accounts' => $tenant->review_command_accounts ?? [],
            'review_forks' => $tenant->review_forks,
        ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'For every repository of '.CurrentTenant::get()->name.' that does not set its own. The model and effort of Claude and Codex are set with their AI provider.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            // Claude's and Codex's model and effort are set with the AI provider they run on.
            ->components([
                Section::make()->schema(ReviewSettingsForm::fields(withProviders: false)),
                Section::make('Trusted accounts')
                    ->description('For every repository of this team. The accounts whose pull requests a repository that reviews only theirs reviews by itself, and who may comment /review when only they may. The others\' pull requests wait under Pull requests for someone here to start them.')
                    ->schema([
                        TagsInput::make('review_command_accounts')
                            ->label('Accounts')
                            ->helperText('Bitbucket nicknames or GitHub user names, as in the address of their profile, or account IDs. A display name is not enough: anyone can take any.')
                            ->placeholder('Add an account')
                            ->required(fn (Get $get): bool => $get('review_command') === ReviewCommandAccess::Listed->value),
                    ]),
                Section::make('Starting a review by comment')
                    ->description('For every repository of this team. Commenting /review on a pull request starts a review of it. On a repository outside contributors can comment on, that is anyone spending this team\'s budget. On a repository that reviews only the trusted accounts\' pull requests, only they can. A review still starts on every push, and from this panel, whatever this says.')
                    ->schema([
                        Select::make('review_command')
                            ->label('Who may comment /review')
                            ->options(ReviewCommandAccess::options())
                            ->required()
                            ->live(),
                    ]),
                Section::make('Pull requests from forks')
                    ->description('For every repository of this team. A pull request from a fork is anyone\'s code: reviewed, it runs in the sandbox with this team\'s AI key and on its budget. Off, such a pull request is reviewed only when someone here starts it from this panel. Only GitHub keeps a fork\'s head in the repository itself; on Bitbucket a fork\'s pull request can be reviewed from the diff alone, as a trial.')
                    ->schema([
                        Toggle::make('review_forks')
                            ->label('Review pull requests from forks by themselves, on a push and on a comment'),
                    ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([Action::make('save')->label('Save')->submit('save')->keyBindings(['mod+s'])])->key('form-actions'),
                ]),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $tenant = CurrentTenant::get();

        ReviewSettings::saveDefaults($tenant, $state);

        $tenant->update([
            'review_command' => $state['review_command'],
            'review_command_accounts' => array_values(array_filter(array_map(trim(...), $state['review_command_accounts'] ?? []))) ?: null,
            'review_forks' => (bool) ($state['review_forks'] ?? false),
        ]);

        Notification::make()->title('Saved')->success()->send();
    }
}
