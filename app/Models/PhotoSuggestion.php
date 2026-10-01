<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

/**
 * A photo found by image search and offered in the intake chat. Batches are
 * the galleries shown; picking one downloads it into the project's content
 * references.
 */
class PhotoSuggestion extends Model
{
    /** @use HasFactory<\Database\Factories\PhotoSuggestionFactory> */
    use HasFactory;
    use HasSqids;
    use UsesTenantConnection;

    protected $guarded = [];

    /**
     * @return array{
     *  from_website: 'boolean',
     *  picked_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'from_website' => 'boolean',
            'picked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isPicked(): bool
    {
        return $this->picked_at !== null;
    }
}
