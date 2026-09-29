<?php

declare(strict_types=1);

namespace App\Notifications\Public;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;

class ResetPassword extends BaseResetPassword
{
    /**
     * The reset link points at the public frontend instead of Fortify's admin page.
     */
    protected function resetUrl($notifiable): string
    {
        return route('public.auth.reset-password', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
