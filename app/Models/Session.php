<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;
use RedExplosion\Sqids\Concerns\HasSqids;

/**
 * @property string $id
 */
class Session extends Model
{
    use HasSqids;

    public const UPDATED_AT = null;
    public const CREATED_AT = null;

    public function casts(): array
    {
        return [
            'id' => 'string',
            'last_activity' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return Attribute<bool, never> */
    protected function isCurrent(): Attribute
    {
        return Attribute::make(
            get: fn() => $this->id === session()->getId(),
        );
    }

    public function getConnectionName(): string
    {
        return Config::get('session.connection');
    }
}
