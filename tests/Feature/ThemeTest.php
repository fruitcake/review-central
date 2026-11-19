<?php

use App\Filament\Admin\Resources\Tenants\Pages\ManageTenants;
use App\Filament\Themes;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    // A brand's navy and lime.
    $this->brand = ['ink' => '#161E36', 'accent' => '#C8D35B'];
});

it('wears the default colours in a tenant that set none and on the sign-in page, in the reader\'s own font', function () {
    $this->get('/app/acme')->assertOk()
        ->assertSee('--rc-accent: #0f766e;', false)
        ->assertSee('--rc-sidebar: #042f2e;', false)
        ->assertSee('--rc-font: system-ui', false)
        // No font is fetched from anywhere.
        ->assertDontSee('fonts.bunny.net')
        ->assertDontSee('fonts.googleapis.com');

    auth()->logout();
    $this->get('/app/login')->assertOk()->assertSee('--rc-accent: #0f766e;', false);
});

it('gives the admin panel a darker sidebar, to tell it from a tenant', function () {
    $this->get('/admin')->assertOk()
        ->assertSee('--rc-accent: #0f766e;', false)
        ->assertSee('--rc-sidebar: #021c1b;', false);
});

it('wears the colours a tenant set, and leaves another tenant its own', function () {
    $this->tenant->update(['theme_colors' => $this->brand]);

    $this->get('/app/acme')->assertOk()
        ->assertSee('--rc-ink: #161e36;', false)
        ->assertSee('--rc-sidebar: #161e36;', false)
        ->assertSee('--rc-accent: #c8d35b;', false)
        // The button follows the accent, with the ink on it: white would not be read on lime.
        ->assertSee('--rc-button: #c8d35b;', false)
        ->assertSee('--rc-button-text: #161e36;', false)
        ->assertDontSee('--rc-accent: #0f766e;', false);

    Tenant::factory()->create(['slug' => 'other']);

    $this->get('/app/other')->assertOk()->assertSee('--rc-accent: #0f766e;', false);
});

it('leaves the default colours exactly as they were chosen when a tenant sets none', function () {
    expect(Themes::for(null))->toBe(Themes::DEFAULT)
        ->and(Themes::for(new Tenant(['theme_colors' => []])))->toBe(Themes::DEFAULT)
        ->and(array_keys(Themes::for(new Tenant(['theme_colors' => $this->brand]))))->toBe(array_keys(Themes::DEFAULT));
});

it('works out shades that can be read from the colours a tenant set', function () {
    $contrast = function (string $one, string $other): float {
        $luminance = function (string $colour): float {
            [$r, $g, $b] = array_map(fn (string $pair): float => ($v = hexdec($pair) / 255) <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, str_split(substr($colour, 1), 2));

            return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        };

        return (max($luminance($one), $luminance($other)) + 0.05) / (min($luminance($one), $luminance($other)) + 0.05);
    };

    $colours = Themes::for(new Tenant(['theme_colors' => $this->brand]));

    // Lime is too light to be text on white; the shade for text is not.
    expect($contrast('#c8d35b', '#ffffff'))->toBeLessThan(4.5)
        ->and($contrast($colours['accent-text'], '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        ->and($contrast($colours['on-accent'], $colours['accent']))->toBeGreaterThanOrEqual(4.5)
        ->and($contrast($colours['button-text'], $colours['button']))->toBeGreaterThanOrEqual(4.5)
        ->and($contrast($colours['text'], '#ffffff'))->toBeGreaterThanOrEqual(4.5);

    // A dark button gets white on it, and its own hover.
    $dark = Themes::for(new Tenant(['theme_colors' => $this->brand + ['button' => '#161E36']]));

    expect($dark['button'])->toBe('#161e36')->and($dark['button-text'])->toBe('#ffffff')
        ->and($dark['accent'])->toBe('#c8d35b');

    // What a tenant does not set stays the default.
    expect($colours['danger'])->toBe('#dc2626')->and($colours['ground'])->toBe('#f8fafc');
});

it('takes nothing but a colour, so nothing else reaches the stylesheet', function (mixed $value) {
    $colours = Themes::for(new Tenant(['theme_colors' => ['accent' => $value, 'ground' => '#000000', 'unknown' => '#ffffff']]));

    expect($colours)->toBe(Themes::DEFAULT);
})->with([
    'ends the rule' => ['#fff; } body { display: none'],
    'opens a tag' => ['#ffffff</style><script>'],
    'a name' => ['red'],
    'three digits' => ['#fff'],
    'not a string' => [['#ffffff']],
]);

it('lets an administrator set a tenant\'s colours, and refuses what is no colour', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    livewire(ManageTenants::class)
        ->callAction(TestAction::make('edit')->table($this->tenant), ['theme_colors' => ['ink' => '#161e36', 'accent' => '#c8d35b', 'button' => null]])
        ->assertHasNoFormErrors();

    expect(Themes::for($this->tenant->refresh()))->toMatchArray(['ink' => '#161e36', 'accent' => '#c8d35b', 'button' => '#c8d35b']);

    livewire(ManageTenants::class)
        ->callAction(TestAction::make('edit')->table($this->tenant), ['theme_colors' => ['accent' => 'lime; }']])
        ->assertHasFormErrors(['theme_colors.accent']);

    expect(Themes::for($this->tenant->refresh())['accent'])->toBe('#c8d35b');
});
