<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Filament\Components\ReviewSettingsForm;
use App\Filament\CurrentTenant;
use App\Services\ReviewSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The tenant's review settings: which reviewer runs, at what model and effort, for
 * every repository that does not set its own.
 *
 * @property-read Schema $form
 */
class ReviewDefaults extends Page
{
    protected static ?string $title = 'Review defaults';

    protected static ?string $slug = 'review-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 35;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(ReviewSettingsForm::state(ReviewSettings::defaults(CurrentTenant::get())));
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'For every repository of '.CurrentTenant::get()->name.' that does not set its own. The model and effort of Claude and Codex are set with their AI provider.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            // Claude's and Codex's model and effort are set with the AI provider they run on.
            ->components([Section::make()->schema(ReviewSettingsForm::fields(withProviders: false))])
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
        ReviewSettings::saveDefaults(CurrentTenant::get(), $this->form->getState());

        Notification::make()->title('Saved')->success()->send();
    }
}
