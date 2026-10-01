<?php

declare(strict_types=1);

namespace App\Models;

use App\Events\KeyframeDeleting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class Keyframe extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\KeyframeFactory> */
    use HasFactory;
    use HasSqids;
    use InteractsWithMedia;
    use UsesTenantConnection;

    /**
     * Every generated image for this keyframe, so a tweak can be undone by picking an earlier one.
     */
    public const RENDERS = 'renders';

    public const THUMBNAIL = 'thumbnail';

    protected $guarded = [];

    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'deleting' => KeyframeDeleting::class,
    ];

    /**
     * @return array{
     *  rendering: 'boolean',
     * }
     */
    protected function casts(): array
    {
        return [
            'rendering' => 'boolean',
        ];
    }

    /** @return BelongsTo<Shot, $this> */
    public function shot(): BelongsTo
    {
        return $this->belongsTo(Shot::class);
    }

    /**
     * The cast and sets that appear in this keyframe.
     *
     * @return BelongsToMany<Element, $this>
     */
    public function elements(): BelongsToMany
    {
        return $this->belongsToMany(Element::class);
    }

    /** @return MorphMany<Generation, $this> */
    public function generations(): MorphMany
    {
        return $this->morphMany(Generation::class, 'generatable');
    }

    /**
     * Every render of this keyframe, oldest first.
     *
     * @return \Illuminate\Support\Collection<int, Media>
     */
    public function renders(): Collection
    {
        return $this->getMedia(self::RENDERS);
    }

    /**
     * The chosen render of this keyframe: the one the director picked, or else the newest.
     */
    public function render(): ?Media
    {
        $renders = $this->renders();

        return $renders->firstWhere('id', $this->render_id) ?? $renders->last();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::RENDERS)->acceptsMimeTypes(['image/png', 'image/jpeg', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion(self::THUMBNAIL)
            ->performOnCollections(self::RENDERS)
            ->width(480)
            ->height(480);
    }
}
