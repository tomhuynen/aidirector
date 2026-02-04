<?php

declare(strict_types=1);

namespace App\Models\Auth;

use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\Permission\Models\Role as Model;

/**
 * @property string $title
 * @property string $description
 * @property int $level
 */
class Role extends Model
{
    use UsesLandlordConnection;

    protected $attributes = [
        'description' => '',
    ];

    public function isTenantSpecific()
    {
        return in_array($this->name, []);
    }

    public function isGroupSpecific()
    {
        return in_array($this->name, []);
    }
}
