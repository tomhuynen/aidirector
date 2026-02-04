<?php

declare(strict_types=1);

namespace App\Models\Auth;

use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\Permission\Models\Permission as Model;

class Permission extends Model
{
    use UsesLandlordConnection;
}
