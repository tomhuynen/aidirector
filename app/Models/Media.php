<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\URL;
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

    /**
     * A short-lived link to the file or one of its conversions. The tenant
     * disks are private, so the public media route streams it.
     */
    public function signedUrl(?string $conversion = null): string
    {
        return URL::temporarySignedRoute('public.media.view', now()->addHours(2), array_filter([
            'media' => $this,
            'conversion' => $conversion,
        ]));
    }
}
