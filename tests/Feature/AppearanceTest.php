<?php

use App\Filament\Admin\Pages\Settings;
use App\Filament\Themes;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PlatformSettings;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('wears the mark, the default name and the credit until an administrator sets otherwise', function () {
    $this->get('/app/acme')->assertOk()
        ->assertSee('class="rc-brand-mark"', false)
        ->assertSee('<title>', false)
        ->assertSee('Review Central')
        ->assertSee('Open source · developed by')
        ->assertSee('favicon.svg');
});

it('lets an administrator give the installation its own name, logo and colour, and drop the credit', function () {
    $logo = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>');

    livewire(Settings::class)
        ->fillForm(['app_name' => 'Acme Review', 'colour' => '#4F46E5', 'credit' => false, 'logo_upload' => $logo])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(PlatformSettings::class);

    expect($settings->appName())->toBe('Acme Review')
        ->and($settings->colour())->toBe('#4f46e5')
        ->and($settings->logo())->toStartWith('data:image/svg+xml;base64,');

    $this->get('/app/acme')->assertOk()
        ->assertSee('Acme Review')
        // The logo in place of the mark, and as the favicon.
        ->assertSee('class="rc-brand-logo" src="data:image/svg+xml;base64,', false)
        ->assertDontSee('class="rc-brand-mark"', false)
        ->assertDontSee('Open source · developed by')
        // The accent and the button, with white on indigo, and a sidebar in a deep shade of it.
        ->assertSee('--rc-accent: #4f46e5;', false)
        ->assertSee('--rc-button-text: #ffffff;', false)
        ->assertDontSee('--rc-sidebar: #042f2e;', false);

    livewire(Settings::class)
        ->fillForm(['remove_logo' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(PlatformSettings::class)->logo())->toBeNull()
        ->and(app(PlatformSettings::class)->appName())->toBe('Acme Review');
});

it('keeps a tenant\'s own colours over the installation\'s', function () {
    app(PlatformSettings::class)->save([PlatformSettings::COLOUR => '#4f46e5']);
    $this->tenant->update(['theme_colors' => ['accent' => '#c8d35b']]);

    expect(Themes::for($this->tenant)['accent'])->toBe('#c8d35b')
        ->and(Themes::for(null)['accent'])->toBe('#4f46e5')
        ->and(Themes::for(null)['sidebar-admin'])->not->toBe(Themes::DEFAULT['sidebar-admin']);
});

it('refuses a logo that is no PNG or SVG, and a colour that is no colour', function () {
    livewire(Settings::class)
        ->fillForm(['colour' => 'red; }', 'logo_upload' => UploadedFile::fake()->create('logo.gif', 10, 'image/gif')])
        ->call('save')
        ->assertHasFormErrors(['colour', 'logo_upload']);

    expect(app(PlatformSettings::class)->colour())->toBeNull();
});
