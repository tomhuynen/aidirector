<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Multitenancy\Models\Concerns\UsesLandlordConnection;

class Login extends Model
{
    /** @use HasFactory<\Database\Factories\LoginFactory> */
    use HasFactory;

    use UsesLandlordConnection;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'details' => 'array',
        ];
    }

    protected $attributes = [
        'details' => '[]',
    ];

    public const UPDATED_AT = null;

    public function authenticatable(): MorphTo
    {
        return $this->morphTo('authenticatable');
    }
}
