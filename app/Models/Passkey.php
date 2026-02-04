<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\LaravelPasskeys\Models\Passkey as Base;
use Spatie\LaravelPasskeys\Support\Serializer;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Webauthn\PublicKeyCredentialSource;

class Passkey extends Base
{
    use HasSqids;
    use UsesLandlordConnection;

    public function data(): Attribute
    {
        $serializer = Serializer::make();

        return new Attribute(
            get: fn(string $value) => $serializer->fromJson(
                $value,
                PublicKeyCredentialSource::class
            ),
            set: fn(PublicKeyCredentialSource $value) => [
                'credential_id' => bin2hex($value->publicKeyCredentialId),
                'data' => $serializer->toJson($value),
            ],
        );
    }
}
