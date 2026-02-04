<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Str;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\NotificationLog\Models\NotificationLogItem as Model;

class NotificationLogItem extends Model
{
    use HasSqids;
    use UsesLandlordConnection;

    public function name()
    {
        $name = $this->notification_type;

        if (str_contains($name, '\\')) {
            $name = Str::afterLast($name, '\\');
        }

        return Str::headline($name);
    }
}
