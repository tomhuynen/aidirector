<?php

declare(strict_types=1);

namespace App\Notifications\Admin;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class AccountInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('admin.auth.invite', now()->addDays(7), ['user' => $notifiable]);

        $subject = __('Your new account on :app', [
            'app' => Config::get('app.title'),
        ]);

        return new MailMessage()
            ->subject($subject)
            ->markdown('notifications.admin.account.invitation', [
                'user' => $notifiable,
                'url' => $url,
            ]);
    }

    public function shouldLog(NotificationSending $event): bool
    {
        return true;
    }
}
