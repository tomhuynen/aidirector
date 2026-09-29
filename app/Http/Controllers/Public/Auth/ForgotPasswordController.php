<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Auth;

use Inertia\Inertia;

class ForgotPasswordController
{
    public function view()
    {
        return Inertia::render('auth/forgot-password');
    }
}
