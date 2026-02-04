<?php

declare(strict_types=1);

namespace App\Models;

use App\Events\TenantCreated;
use App\Events\TenantCreating;
use App\Events\TenantDeleted;
use App\Events\TenantDeleting;
use App\Support\Search\Contracts\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Multitenancy\Models\Tenant as Base;

class Tenant extends Base implements Searchable
{
    /** @use HasFactory<\Database\Factories\TenantFactory> */
    use HasFactory;
    use HasSqids;

    protected $guarded = [];

    protected $attributes = [
        'settings' => '[]',
    ];

    protected $dispatchesEvents = [
        'creating' => TenantCreating::class,
        'created' => TenantCreated::class,
        'deleting' => TenantDeleting::class,
        'deleted' => TenantDeleted::class,
    ];

    /**
     * @return array{
     *  settings: 'array'
     * }
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function getDatabaseName(): string
    {
        return vsprintf('%s-%s', [
            Config::get('database.connections.landlord.database'),
            Str::slug($this->name),
        ]);
    }

    public function searchableColumns(): array
    {
        return [
            'name',
            'domain',
        ];
    }
}
