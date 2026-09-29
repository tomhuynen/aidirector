<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class ResetPasswordController
{
    public function view(Request $request, string $token)
    {
        return Inertia::render('auth/reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
            'passwordRules' => Password::default()->appliedRules(),
            'suggestion' => collect(range(1, 4))->map(fn() => Str::random(4))->join('-'),
        ]);
    }
}
