<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Auth;

use App\Http\Requests\Public\Auth\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LoginController
{
    public function view()
    {
        return Inertia::render('auth/login', [
            'canResetPassword' => true,
        ]);
    }

    public function store(LoginRequest $request)
    {
        $credentials = $request->safe()->only(['email', 'password']);

        if (! Auth::guard('director')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('public.projects.index'));
    }

    public function destroy(Request $request)
    {
        Auth::guard('director')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('public.auth.login');
    }
}
