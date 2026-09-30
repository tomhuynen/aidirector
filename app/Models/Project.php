<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Ai\Models\Conversation;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;
    use HasSqids;
    use UsesTenantConnection;

    protected $guarded = [];

    protected $attributes = [
        'purpose' => ProjectPurpose::EXPLAINER->value,
        'aspect_ratio' => AspectRatio::LANDSCAPE->value,
        'style' => '[]',
        'default_duration' => 5,
    ];

    /**
     * @return array{
     *  purpose: 'App\Enums\ProjectPurpose',
     *  aspect_ratio: 'App\Enums\AspectRatio',
     *  style: 'array',
     *  archived_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'purpose' => ProjectPurpose::class,
            'aspect_ratio' => AspectRatio::class,
            'style' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Director, $this> */
    public function director(): BelongsTo
    {
        return $this->belongsTo(Director::class);
    }

    /**
     * The intake conversation this project was created from, if any.
     *
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return HasMany<Shot, $this> */
    public function shots(): HasMany
    {
        return $this->hasMany(Shot::class)->orderBy('position');
    }

    public function isOwnedBy(Director $director): bool
    {
        return $this->director_id === $director->id;
    }
}
