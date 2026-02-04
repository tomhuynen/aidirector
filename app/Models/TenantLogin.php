<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class TenantLogin extends Model
{
    use UsesTenantConnection;

    protected $table = 'logins';

    protected $guarded = [];

    protected $casts = [
        'success' => 'boolean',
        'details' => 'array',
    ];

    protected $attributes = [
        'details' => '[]',
    ];

    public const UPDATED_AT = null;

    public function authenticatable(): MorphTo
    {
        return $this->morphTo('authenticatable');
    }
}
