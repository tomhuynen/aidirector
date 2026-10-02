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
 * to every keyframe it appears in, so it looks the same in every shot. Every
 * version of that image is kept; reference_id points at the chosen one.
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

    /** A photo of the real person, place or object that its picture is drawn from. */
    public const PHOTO = 'photo';

    /**
     * On a version made by a change request: what the director asked for.
     */
    public const CHANGE_REQUEST = 'change_request';

    protected $guarded = [];

    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'deleting' => ElementDeleting::class,
    ];

    /**
     * @return array{
     *  type: 'App\Enums\ElementType',
     *  rendering: 'boolean',
     * }
     */
    protected function casts(): array
    {
        return [
            'type' => ElementType::class,
            'rendering' => 'boolean',
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

    /**
     * Every version of the reference image, oldest first.
     *
     * @return \Illuminate\Support\Collection<int, BaseMedia>
     */
    public function references(): \Illuminate\Support\Collection
    {
        return $this->getMedia(self::REFERENCE);
    }

    /**
     * The chosen version of the reference image: the one the director picked, or else the newest.
     */
    public function reference(): ?BaseMedia
    {
        $references = $this->references();

        return $references->firstWhere('id', $this->reference_id) ?? $references->last();
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
        $this->addMediaCollection(self::REFERENCE)->acceptsMimeTypes(['image/png', 'image/jpeg', 'image/webp']);
        $this->addMediaCollection(self::PHOTO)->singleFile()->acceptsMimeTypes(['image/png', 'image/jpeg', 'image/webp']);
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
