<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ElementType;
use App\Events\ElementDeleting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

/**
 * A recurring person, place or object of a project. Its description is
 * repeated word for word in every prompt and its reference image is attached
 * to every keyframe it appears in, so it looks the same in every shot.
 */
class Element extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\ElementFactory> */
    use HasFactory;
    use HasSqids;
    use InteractsWithMedia;
    use UsesTenantConnection;

    public const REFERENCE = 'reference';

    public const THUMBNAIL = 'thumbnail';

    protected $guarded = [];

    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'deleting' => ElementDeleting::class,
    ];

    /**
     * @return array{
     *  type: 'App\Enums\ElementType',
     * }
     */
    protected function casts(): array
    {
        return [
            'type' => ElementType::class,
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsToMany<Keyframe, $this> */
    public function keyframes(): BelongsToMany
    {
        return $this->belongsToMany(Keyframe::class);
    }

    /** @return MorphMany<Generation, $this> */
    public function generations(): MorphMany
    {
        return $this->morphMany(Generation::class, 'generatable');
    }

    public function reference(): ?BaseMedia
    {
        return $this->getFirstMedia(self::REFERENCE);
    }

    /**
     * The line that introduces the element in a prompt.
     */
    public function promptLine(): string
    {
        return "{$this->name} ({$this->type->value}): {$this->description}";
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::REFERENCE)->singleFile()->acceptsMimeTypes(['image/png', 'image/jpeg', 'image/webp']);
    }

    public function registerMediaConversions(?BaseMedia $media = null): void
    {
        $this->addMediaConversion(self::THUMBNAIL)
            ->performOnCollections(self::REFERENCE)
            ->nonQueued()
            ->fit(Fit::Max, 480, 480)
            ->format('jpg')
            ->quality(80);
    }
}
