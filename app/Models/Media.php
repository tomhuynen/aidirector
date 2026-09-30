<?php

declare(strict_types=1);

namespace App\Models;

use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

/**
 * Media belongs to tenant-scoped models, so it lives in the tenant database.
 */
class Media extends BaseMedia
{
    use HasSqids;
    use UsesTenantConnection;
}
