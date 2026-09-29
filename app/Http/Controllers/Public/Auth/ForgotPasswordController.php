<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Auth;

use App\Http\Requests\Public\Auth\ForgotPasswordRequest;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;

class ForgotPasswordController
{
    public function view()
    {
        return Inertia::render('auth/forgot-password');
    }

    public function store(ForgotPasswordRequest $request)
    {
        Password::broker('directors')->sendResetLink($request->safe()->only('email'));

        return back()->with('status', __('If that address exists, a reset link is on its way.'));
    }
}
