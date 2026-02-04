<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings\Passkeys;

use Illuminate\Http\Request;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Support\Config;

class OptionsController
{
    public function create(Request $request)
    {
        $generatePassKeyOptionsAction = Config::getAction('generate_passkey_register_options', GeneratePasskeyRegisterOptionsAction::class);

        return $generatePassKeyOptionsAction->execute($request->user());
    }
}
