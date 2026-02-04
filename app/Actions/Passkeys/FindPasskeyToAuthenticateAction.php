<?php

declare(strict_types=1);

namespace App\Actions\Passkeys;

use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction as Base;
use Spatie\LaravelPasskeys\Models\Passkey;
use Spatie\LaravelPasskeys\Support\Config;
use Webauthn\PublicKeyCredential;

class FindPasskeyToAuthenticateAction extends Base
{
    protected function findPasskey(PublicKeyCredential $publicKeyCredential): ?Passkey
    {
        $passkeyModel = Config::getPassKeyModel();

        return $passkeyModel::firstWhere('credential_id', bin2hex($publicKeyCredential->rawId));
    }
}
