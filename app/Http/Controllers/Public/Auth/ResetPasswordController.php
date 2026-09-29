<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Auth;

use App\Http\Requests\Public\Auth\ResetPasswordRequest;
use App\Models\Director;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ResetPasswordController
{
    public function view(Request $request, string $token)
    {
        return Inertia::render('auth/reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
            'passwordRules' => PasswordRule::default()->appliedRules(),
            'suggestion' => collect(range(1, 4))->map(fn() => Str::random(4))->join('-'),
        ]);
    }

    public function store(ResetPasswordRequest $request)
    {
        $status = Password::broker('directors')->reset(
            $request->safe()->only(['email', 'password', 'password_confirmation', 'token']),
            function (Director $director, string $password): void {
                $director->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($director));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        return redirect()->route('public.auth.login')->with('status', __($status));
    }
}
