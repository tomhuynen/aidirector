<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\HasSequentialNavigation;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Activitylog\Models\Activity as SpatieActivity;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class Activity extends SpatieActivity
{
    use HasSequentialNavigation;
    use HasSqids;
    use UsesTenantConnection;
}
