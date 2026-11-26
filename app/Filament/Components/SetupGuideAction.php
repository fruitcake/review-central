<?php

declare(strict_types=1);

namespace App\Filament\Components;

use App\Support\SetupGuide;
use Closure;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

/**
 * "How to get this": a modal with where a key or token is made, the steps there
 * and the scopes it needs. As a hint on the field that asks for it, or anywhere
 * else an action goes. It only informs, so it has nothing to submit.
 */
class SetupGuideAction
{
    /**
     * @param  SetupGuide|Closure|null  $guide  a closure returns the guide or null, and is evaluated as the action's own, so it can take $get, $arguments or a service
     */
    public static function make(SetupGuide|Closure|null $guide, string $name = 'setupGuide'): Action
    {
        $resolve = function (Action $action) use ($guide): ?SetupGuide {
            $found = $guide instanceof Closure ? $action->evaluate($guide) : $guide;

            return $found instanceof SetupGuide ? $found : null;
        };

        return Action::make($name)
            ->label(__('How to get this'))
            ->icon(Heroicon::OutlinedQuestionMarkCircle)
            ->color('gray')
            ->link()
            ->visible(fn (Action $action): bool => $resolve($action) !== null)
            ->modalHeading(fn (Action $action): string => $resolve($action)->title ?? '')
            ->modalIcon(Heroicon::OutlinedKey)
            ->modalWidth(Width::TwoExtraLarge)
            ->modalContent(fn (Action $action): ?View => ($found = $resolve($action)) === null ? null : view('filament.setup-guide', ['guide' => $found]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }
}
