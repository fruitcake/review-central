<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Themes;
use App\Services\PlatformSettings;
use App\Services\SignInCodes;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The code that shows an address is the person's who signs in with an account
 * at Bitbucket or GitHub that is not linked yet. Sent at once, not queued: they are waiting
 * for it on the page.
 */
class SignInCode extends Notification
{
    /**
     * @param  string  $provider  where the account is, by name: Bitbucket or GitHub
     */
    public function __construct(public string $code, public string $account, public string $provider) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = app(PlatformSettings::class)->appName();

        return (new MailMessage)
            ->markdown('notifications::email', ['colours' => Themes::for(null)])
            ->subject("Your code to sign in to {$name}: {$this->code}")
            ->line("The {$this->provider} account {$this->account} is signing in to {$name} with this address. Type this code on the page that asks for it:")
            ->line("**{$this->code}**")
            ->line('It works for '.SignInCodes::MINUTES.' minutes, and only in the browser that signed in.')
            ->line('Was this not you? Then ignore this mail, and give the code to nobody: without it, nobody signs in with your address.');
    }
}
