<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Themes;
use App\Runners\Runners;
use App\Services\Currency;
use App\Services\PlatformSettings;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * What holds for the whole installation: the currency and the time zone of the
 * admin panel, what a new tenant starts with, and how the installation looks.
 *
 * @property-read Schema $form
 */
class Settings extends Page
{
    protected static ?string $title = 'Settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 90;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $settings = app(PlatformSettings::class);

        $this->form->fill([
            PlatformSettings::CURRENCY => $settings->currency(),
            PlatformSettings::TIMEZONE => $settings->timezone(),
            // Only a pick: what follows the configuration and what is set up is the placeholder.
            PlatformSettings::RUNNER => $settings->runner(),
            PlatformSettings::APP_NAME => $settings->get(PlatformSettings::APP_NAME),
            PlatformSettings::COLOUR => $settings->colour(),
            PlatformSettings::CREDIT => $settings->credit(),
            PlatformSettings::CLOUDFLARE_ACCOUNT => $settings->text(PlatformSettings::CLOUDFLARE_ACCOUNT),
            PlatformSettings::CLOUDFLARE_WORKER => $settings->text(PlatformSettings::CLOUDFLARE_WORKER),
            PlatformSettings::CLOUDFLARE_BUCKET => $settings->text(PlatformSettings::CLOUDFLARE_BUCKET),
            PlatformSettings::R2_ACCESS_KEY_ID => $settings->text(PlatformSettings::R2_ACCESS_KEY_ID),
            // Secrets are never sent back to the browser.
            PlatformSettings::CLOUDFLARE_API_TOKEN => null,
            PlatformSettings::R2_SECRET_ACCESS_KEY => null,
            'remove_logo' => false,
        ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'For the whole platform. A tenant reads its own pages in its own currency and time zone, set on the tenant, and a repository can pick its own runner.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->schema([
                    Select::make(PlatformSettings::RUNNER)
                        ->label('Reviews run on')
                        ->options(function (Runners $runners): array {
                            $options = $runners->options();
                            $picked = app(PlatformSettings::class)->runner();

                            // A pick that is no longer set up stays visible, so it can be changed.
                            if ($picked !== null && ! array_key_exists($picked, $options)) {
                                $options[$picked] = rescue(fn (): string => $runners->get($picked)->label(), $picked, false);
                            }

                            return $options;
                        })
                        // Unpicked, the platform follows REVIEW_DEFAULT_RUNNER, or the first runner that is set up.
                        ->placeholder(fn (Runners $runners): string => 'As configured: '.rescue(fn (): string => $runners->get($runners->default())->label(), $runners->default(), false))
                        ->helperText('For every repository that does not pick its own runner, from its next review on. Docker and a Cloudflare sandbox run the reviewer a repository\'s settings pick; the diff alone sends the diff, with the pull request\'s context, to the AI provider. Only runners that are set up can be picked; see Setup.'),
                    Select::make(PlatformSettings::CURRENCY)
                        ->label('Currency')
                        ->options(Currency::NAMES)
                        ->required()
                        ->selectablePlaceholder(false)
                        ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (! app(Currency::class)->ensureRate((string) $value)) {
                                $fail('The exchange rate for this currency could not be fetched. Try again later.');
                            }
                        })
                        ->helperText('What the admin panel shows costs in, and what a new tenant starts with. A review is costed in US dollars; another currency is converted at the latest rate of the European Central Bank. Model prices stay in dollars, as the providers set them.'),
                    Select::make(PlatformSettings::TIMEZONE)
                        ->label('Time zone')
                        ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                        ->required()
                        ->searchable()
                        ->selectablePlaceholder(false)
                        ->helperText('The zone the admin panel shows times in, and what a new tenant starts with. Times are stored in UTC; this only changes how they are shown.'),
                ]),
                Section::make('Appearance')
                    ->description('Name, logo and colour of this installation, in both panels and on the sign-in pages. A tenant\'s own colours, set on the tenant, go over them.')
                    ->columns(2)
                    ->schema([
                        TextInput::make(PlatformSettings::APP_NAME)
                            ->label('App name')
                            ->placeholder(PlatformSettings::DEFAULT_NAME)
                            ->maxLength(40),
                        ColorPicker::make(PlatformSettings::COLOUR)
                            ->label('Primary colour')
                            ->placeholder(Themes::DEFAULT['accent'])
                            ->regex(Themes::COLOUR)
                            ->validationMessages(['regex' => 'A colour as six hex digits, such as #0f766e.'])
                            ->helperText('Buttons, the active menu item and the mark; the sidebar takes a deep shade of it. Text on it switches between white and dark by itself, so every colour stays readable. Empty is the default teal.'),
                        FileUpload::make('logo_upload')
                            ->label('Logo')
                            ->acceptedFileTypes(['image/png', 'image/svg+xml'])
                            ->maxSize(PlatformSettings::LOGO_KILOBYTES)
                            ->storeFiles(false)
                            ->helperText(fn (): string => 'An SVG or a PNG of at most '.PlatformSettings::LOGO_KILOBYTES.' KB, shown 32 pixels high in place of the mark and used as the favicon.'
                                .(app(PlatformSettings::class)->logo() !== null ? ' A logo is set; upload another to replace it.' : '')),
                        Toggle::make('remove_logo')
                            ->label('Remove the logo')
                            ->helperText('Shows the mark again.')
                            ->visible(fn (): bool => app(PlatformSettings::class)->logo() !== null),
                        Toggle::make(PlatformSettings::CREDIT)
                            ->label('Show "developed by Fruitcake"')
                            ->helperText('A small credit at the foot of the sidebar.'),
                    ]),
                Section::make('Cloudflare sandboxes')
                    ->description(new HtmlString('To run reviewers in a Cloudflare sandbox, deployed from here: <strong>Deploy the Worker</strong> on the Setup page then makes the bucket, deploys the Worker and keeps its address and token. The account needs the Workers Paid plan. What the environment sets (CLOUDFLARE_ACCOUNT_ID, CLOUDFLARE_API_TOKEN, R2_*, REVIEW_CLOUDFLARE_*) goes before these.'))
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(fn (): bool => app(PlatformSettings::class)->text(PlatformSettings::CLOUDFLARE_ACCOUNT) === null)
                    ->schema([
                        TextInput::make(PlatformSettings::CLOUDFLARE_ACCOUNT)
                            ->label('Account ID')
                            ->regex('/^[a-f0-9]{32}$/')
                            ->validationMessages(['regex' => 'The 32 characters shown on the account\'s overview in the Cloudflare dashboard.'])
                            ->helperText('On the account\'s overview in the dashboard, under API.'),
                        TextInput::make(PlatformSettings::CLOUDFLARE_API_TOKEN)
                            ->label('API token')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->placeholder(fn (): ?string => app(PlatformSettings::class)->secret(PlatformSettings::CLOUDFLARE_API_TOKEN) !== null ? 'Saved; type to replace' : null)
                            ->helperText(new HtmlString('<a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener" class="underline">Create one</a> for this account with Workers Scripts, Containers and Workers R2 Storage: Edit, and Account Settings: Read. Used only to deploy.')),
                        TextInput::make(PlatformSettings::CLOUDFLARE_WORKER)
                            ->label('Worker name')
                            ->placeholder(PlatformSettings::DEFAULT_WORKER)
                            ->regex('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/')
                            ->validationMessages(['regex' => 'Lower case letters, digits and dashes.']),
                        TextInput::make(PlatformSettings::CLOUDFLARE_BUCKET)
                            ->label('Bucket')
                            ->placeholder(PlatformSettings::DEFAULT_BUCKET)
                            ->regex('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/')
                            ->validationMessages(['regex' => 'Lower case letters, digits and dashes, 3 to 63 of them.'])
                            ->helperText('Made by the deploy when it is not there.'),
                        TextInput::make(PlatformSettings::R2_ACCESS_KEY_ID)
                            ->label('Bucket access key ID')
                            ->helperText('R2 → Manage API tokens → Create API token, with Object Read & Write on the bucket only.'),
                        TextInput::make(PlatformSettings::R2_SECRET_ACCESS_KEY)
                            ->label('Bucket secret access key')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->placeholder(fn (): ?string => app(PlatformSettings::class)->secret(PlatformSettings::R2_SECRET_ACCESS_KEY) !== null ? 'Saved; type to replace' : null),
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
        $upload = $state['logo_upload'] ?? null;

        // Kept as a data URI in the settings, so no storage has to be served for it.
        if ($upload instanceof TemporaryUploadedFile) {
            $state[PlatformSettings::LOGO] = 'data:'.($upload->getMimeType() === 'image/png' ? 'image/png' : 'image/svg+xml').';base64,'.base64_encode((string) $upload->get());
        } elseif ($state['remove_logo'] ?? false) {
            $state[PlatformSettings::LOGO] = null;
        }

        app(PlatformSettings::class)->save($state);
        // Filled again from what was saved: the upload is gone, the logo is in the settings.
        $this->mount();

        Notification::make()->title('Saved')->success()->send();
    }
}
