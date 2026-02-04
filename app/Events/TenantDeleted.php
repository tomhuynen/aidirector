<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Tenant;
use Illuminate\Queue\SerializesModels;

class TenantDeleted
{
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(public Tenant $tenant)
    {
        //
    }
}
