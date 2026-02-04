<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Models\Policies\UserPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController
{
    /**
     * Show the user's password settings page.
     */
    public function edit(Request $request): Response
    {
        Gate::authorize(UserPolicy::UPDATE, $request->user());

        return Inertia::render('settings/password');
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        Gate::authorize(UserPolicy::UPDATE, $request->user());

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back();
    }
}
