<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Themes;
use App\Models\User;
use App\Services\PlatformSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Tells someone an account was made for them, with a link to choose its password.
 */
class Invitation extends Notification implements ShouldQueue
{
    use Queueable;

    public const VALID_DAYS = 7;

    public function __construct(public ?string $invitedBy = null) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $name = app(PlatformSettings::class)->appName();
        // Someone invited to one tenant gets the mail in that tenant's colours.
        $tenants = $notifiable->tenants()->get();
        $tenant = $tenants->count() === 1 ? $tenants->first() : null;

        return (new MailMessage)
            ->markdown('notifications::email', ['colours' => Themes::for($tenant)])
            ->subject($tenant === null ? "You have been invited to {$name}" : "You have been invited to {$tenant->name} on {$name}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->invitedBy === null
                ? "An account has been made for you on {$name}."
                : "{$this->invitedBy} has made an account for you on {$name}.")
            ->line('Choose a password to start using it.')
            ->action('Choose a password', self::url($notifiable))
            ->line('This link works for '.self::VALID_DAYS.' days, and until a password has been chosen. After that, use "Forgot password?" on the sign-in page.');
    }

    /**
     * A password reset link lasts an hour, too short for an invitation. This one
     * lasts days and is traded for a fresh reset link when it is opened.
     */
    public static function url(User $user): string
    {
        return URL::temporarySignedRoute('invitation.accept', now()->addDays(self::VALID_DAYS), [
            'user' => $user->getKey(),
            'state' => self::state($user),
        ]);
    }

    /**
     * Changes with the password, which ends the invitation once one is chosen, and
     * with the address, so that one sent to a mistyped address ends when it is put right.
     */
    public static function state(User $user): string
    {
        return substr(hash_hmac('sha256', $user->email."\n".$user->password, (string) config('app.key')), 0, 32);
    }
}
