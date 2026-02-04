<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Search\Contracts\Searchable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Lab404\Impersonate\Models\Impersonate;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Activitylog\Traits\CausesActivity;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Models\Concerns\InteractsWithPasskeys;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasPasskeys, Searchable
{
    use CausesActivity;
    use HasApiTokens;
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use HasRoles;
    use HasSqids;
    use Impersonate;
    use InteractsWithPasskeys;
    use Notifiable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;
    use UsesLandlordConnection;

    protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $attributes = [
        'password' => '',
    ];

    /**
     * @return array{
     *  email_verified_at: 'datetime',
     *  password: 'hashed',
     * }
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isMe(): bool
    {
        return $this->id === auth()->id();
    }

    /**
     * @return Attribute<int, never>
     */
    protected function maxRoleLevel(): Attribute
    {
        return Attribute::make(
            get: fn() => once(fn() => $this->roles()->max('level')),
        );
    }

    public function canImpersonate(): bool
    {
        return $this->can('admin.impersonate');
    }

    public function canBeImpersonated(): bool
    {
        return $this->cannot('admin.impersonate');
    }

    public function searchableColumns(): array
    {
        return [
            'name',
            'email',
        ];
    }
}
