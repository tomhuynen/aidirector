<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StyleOptionStatus;
use App\Events\StyleOptionDeleting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

/**
 * One tile in the style exploration: a style sheet rendered from the
 * project's content photos in a candidate style. Rounds branch from a
 * parent option ("more like this") until one is pinned as the project's
 * style anchor.
 */
class StyleOption extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\StyleOptionFactory> */
    use HasFactory;
    use HasSqids;
    use InteractsWithMedia;
    use UsesTenantConnection;

    public const RENDER = 'render';

    public const THUMBNAIL = 'thumbnail';

    public const THUMBNAIL_MAX_EDGE = 768;

    protected $guarded = [];

    /**
     * Relations and files are removed by listeners, never by the database.
     */
    protected $dispatchesEvents = [
        'deleting' => StyleOptionDeleting::class,
    ];

    /**
     * @return array{
     *  style: 'array',
     *  status: 'App\Enums\StyleOptionStatus',
     *  pinned_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'style' => 'array',
            'status' => StyleOptionStatus::class,
            'pinned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<StyleOption, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(StyleOption::class, 'parent_id');
    }

    /** @return HasMany<StyleOption, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(StyleOption::class, 'parent_id');
    }

    /** @return MorphMany<Generation, $this> */
    public function generations(): MorphMany
    {
        return $this->morphMany(Generation::class, 'generatable');
    }

    /**
     * The style this tile was rendered in.
     *
     * @return array{name: string, look: string, medium: string, mood: string, palette: string, lighting: string}
     */
    public function style(): array
    {
        $style = $this->style ?? [];

        return [
            'name' => (string) ($style['name'] ?? ''),
            'look' => (string) ($style['look'] ?? ''),
            'medium' => (string) ($style['medium'] ?? ''),
            'mood' => (string) ($style['mood'] ?? ''),
            'palette' => (string) ($style['palette'] ?? ''),
            'lighting' => (string) ($style['lighting'] ?? ''),
        ];
    }

    public function render(): ?Media
    {
        /** @var Media|null */
        return $this->getFirstMedia(self::RENDER);
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
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
            ->fit(Fit::Max, self::THUMBNAIL_MAX_EDGE, self::THUMBNAIL_MAX_EDGE)
            ->format('jpg')
            ->quality(80);
    }
}
