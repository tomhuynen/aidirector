<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings\Passkeys;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Support\Config;
use Throwable;

class CreateController
{
    public function store(Request $request)
    {
        $user = $request->user();

        $storePasskeyAction = Config::getAction('store_passkey', StorePasskeyAction::class);

        $request->validate([
            'options' => ['required'],
            'passkey' => ['required'],
            'name' => ['required', 'string', 'min:3', 'max:100'],
        ]);

        try {
            $storePasskeyAction->execute(
                $user,
                $request->input('passkey'),
                $request->input('options'),
                request()->getHost(),
                ['name' => $request->input('name')],
            );
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'name' => ['Someting went wrong while storing the passkey'],
            ]);
        }

        return response(null, Response::HTTP_ACCEPTED);
    }

    protected function previouslyGeneratedPasskeyOptions(): ?string
    {
        return session()->pull('passkey-registration-options');
    }
}
