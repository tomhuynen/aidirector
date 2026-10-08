<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

/**
 * A director's token is stamped with its tenant and only found while that
 * tenant is current: director ids repeat across the tenant databases, so the
 * id alone would also match a director of another tenant.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use UsesLandlordConnection;

    protected static function booted(): void
    {
        static::creating(function (self $token) {
            if ($token->tokenable_type === (new Director())->getMorphClass()) {
                $token->tenant_id = Tenant::current()?->getKey();
            }
        });
    }

    /**
     * @param  string  $token
     */
    public static function findToken($token): ?static
    {
        $found = parent::findToken($token);

        if ($found === null || $found->tokenable_type !== (new Director())->getMorphClass()) {
            return $found;
        }

        $tenant = Tenant::current()?->getKey();

        return $tenant !== null && (int) $found->tenant_id === (int) $tenant ? $found : null;
    }
}
