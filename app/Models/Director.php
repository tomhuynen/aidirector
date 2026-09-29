<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\Public\ResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

/**
 * A creator using the public app. Lives in the tenant database, separate
 * from the operator accounts in the landlord users table.
 */
class Director extends Authenticatable implements CanResetPasswordContract
{
    /** @use HasFactory<\Database\Factories\DirectorFactory> */
    use HasFactory;
    use HasSqids;
    use Notifiable;
    use UsesTenantConnection;

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
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

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPassword($token));
    }
}
