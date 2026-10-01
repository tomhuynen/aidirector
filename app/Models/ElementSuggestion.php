<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ElementSuggestionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

/**
 * A suggested person, place or object in an element round, rendered in the
 * project style. Picking it creates an element that keeps this render as
 * its reference image.
 */
class ElementSuggestion extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\ElementSuggestionFactory> */
    use HasFactory;
    use HasSqids;
    use InteractsWithMedia;
    use UsesTenantConnection;

    public const RENDER = 'render';

    public const THUMBNAIL = 'thumbnail';

    protected $guarded = [];

    /**
     * @return array{
     *  status: 'App\Enums\ElementSuggestionStatus',
     *  picked_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'status' => ElementSuggestionStatus::class,
            'picked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ElementRound, $this> */
    public function round(): BelongsTo
    {
        return $this->belongsTo(ElementRound::class, 'element_round_id');
    }

    /**
     * The uploaded photo this suggestion is based on, if any.
     *
     * @return BelongsTo<Media, $this>
     */
    public function sourcePhoto(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'source_media_id');
    }

    /** @return MorphMany<Generation, $this> */
    public function generations(): MorphMany
    {
        return $this->morphMany(Generation::class, 'generatable');
    }

    public function render(): ?Media
    {
        /** @var Media|null */
        return $this->getFirstMedia(self::RENDER);
    }

    public function isPicked(): bool
    {
        return $this->picked_at !== null;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::RENDER)->singleFile()->acceptsMimeTypes(['image/png', 'image/jpeg', 'image/webp']);
    }

    public function registerMediaConversions(?BaseMedia $media = null): void
    {
        $this->addMediaConversion(self::THUMBNAIL)
            ->performOnCollections(self::RENDER)
            ->nonQueued()
            ->fit(Fit::Max, 480, 480)
            ->format('jpg')
            ->quality(80);
    }
}
