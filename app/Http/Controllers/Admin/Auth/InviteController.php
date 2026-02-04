<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class InviteController
{
    public function view(Request $request, User $user)
    {
        $this->validateUser($request, $user);

        return Inertia::render('auth/invite', [
            'email' => $user->email,
            'passwordRules' => Password::default()->appliedRules(),
            'suggestion' => collect(range(1, 4))->map(fn() => Str::random(4))->join('-'),
        ]);
    }

    public function store(Request $request, User $user)
    {
        $this->validateUser($request, $user);

        $request->validate([
            'password' => Password::default(),
            'password_confirmation' => ['required', 'same:password'],
        ]);

        $user->password = Hash::make($request->input('password'));
        $user->save();

        return redirect('/admin');
    }

    private function validateUser(Request $request, $user)
    {
        if (! $request->hasValidSignature()) {
            throw new InvalidSignatureException();
        }

        if (! empty($user->password)) {
            throw ValidationException::withMessages([__('Account already activated.')]);
        }
    }
}
