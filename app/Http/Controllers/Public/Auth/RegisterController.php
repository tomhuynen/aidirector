<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Auth;

use App\Http\Requests\Public\Auth\RegisterRequest;
use App\Models\Director;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
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

    public function store(RegisterRequest $request)
    {
        $director = Director::create($request->safe()->only(['name', 'email', 'password']));

        event(new Registered($director));

        Auth::guard('director')->login($director);

        $request->session()->regenerate();

        return redirect()->route('public.projects.index');
    }
}
