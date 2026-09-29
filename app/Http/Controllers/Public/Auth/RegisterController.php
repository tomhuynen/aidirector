<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Auth;

use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class RegisterController
{
    public function view()
    {
        return Inertia::render('auth/register', [
            'inviteRequired' => filled(Config::get('app.invite_code')),
            'passwordRules' => Password::default()->appliedRules(),
        ]);
    }
}
