<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

/**
 * A single AI call made for a director, kept for usage and cost tracking.
 */
class Generation extends Model
{
    use UsesTenantConnection;

    protected $guarded = [];

    /**
     * @return array{
     *  usage: 'array',
     *  cost: 'decimal:6',
     * }
     */
    protected function casts(): array
    {
        return [
            'usage' => 'array',
            'cost' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<Director, $this> */
    public function director(): BelongsTo
    {
        return $this->belongsTo(Director::class);
    }

    public function generatable(): MorphTo
    {
        return $this->morphTo();
    }
}
