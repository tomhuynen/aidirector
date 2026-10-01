<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use App\Events\ProjectDeleting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Models\Conversation;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class Project extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;
    use HasSqids;
    use InteractsWithMedia;
    use UsesTenantConnection;

    /**
     * Photos of the real things that must be recognisable in the shots:
     * products, vessels, sites, people. Content, not style.
     */
    public const CONTENT_REFERENCES = 'content_references';

    /**
     * Images that show how the shots should look. Style, not content.
     */
    public const STYLE_REFERENCES = 'style_references';

    /**
     * The size every reference is sent to the models at, whatever arrived.
     */
    public const REFERENCE = 'reference';

    public const REFERENCE_MAX_EDGE = 1536;

    /**
     * The custom property holding a reference's one-line description.
     */
    public const CAPTION = 'caption';

    /** @var list<string> */
    public const REFERENCE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    protected $guarded = [];

    /**
     * Relations and files are removed by listeners, never by the database.
     */
    protected $dispatchesEvents = [
        'deleting' => ProjectDeleting::class,
    ];

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

    /**
     * The cast and sets: recurring people, places and objects.
     *
     * @return HasMany<Element, $this>
     */
    public function elements(): HasMany
    {
        return $this->hasMany(Element::class)->orderBy('type')->orderBy('name');
    }

    /**
     * The cast and sets as a list for the writers, or a note that there are none yet.
     */
    public function elementsBrief(): string
    {
        $elements = $this->elements()->get();

        if ($elements->isEmpty()) {
            return 'None yet.';
        }

        return $elements->map(fn(Element $element) => '- ' . $element->promptLine())->join("\n");
    }

    /** @return MorphMany<Generation, $this> */
    public function generations(): MorphMany
    {
        return $this->morphMany(Generation::class, 'generatable');
    }

    /** @return HasMany<StyleOption, $this> */
    public function styleOptions(): HasMany
    {
        return $this->hasMany(StyleOption::class)->orderBy('round')->orderBy('position');
    }

    /**
     * The content photos a style sheet is composed from: the first few that
     * have a caption, since those are what the prompt can name.
     *
     * @return Collection<int, BaseMedia>
     */
    public function styleSheetSubjects(int $limit = 4): Collection
    {
        $photos = $this->getMedia(self::CONTENT_REFERENCES);

        return $photos
            ->sortByDesc(fn(BaseMedia $media) => filled($media->getCustomProperty(self::CAPTION)))
            ->take($limit)
            ->values();
    }

    /**
     * The pinned style sheet, which image generations attach so every render
     * matches the look the director chose.
     */
    public function styleReference(): ?BaseMedia
    {
        /** @var BaseMedia|null */
        return $this->getFirstMedia(self::STYLE_REFERENCES);
    }

    /**
     * A project started in the intake chat stays in setup until a style is
     * pinned; opening it resumes the conversation.
     */
    public function needsSetup(): bool
    {
        return $this->conversation_id !== null && ! $this->hasMedia(self::STYLE_REFERENCES);
    }

    /**
     * The resolution the project's videos render at, or the pipeline default.
     */
    public function videoResolution(): string
    {
        return $this->video_resolution ?? Config::get('pipeline.video.resolution');
    }

    public function isOwnedBy(Director $director): bool
    {
        return $this->director_id === $director->id;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::CONTENT_REFERENCES)->acceptsMimeTypes(self::REFERENCE_MIME_TYPES);
        $this->addMediaCollection(self::STYLE_REFERENCES)->acceptsMimeTypes(self::REFERENCE_MIME_TYPES);
    }

    /**
     * The reference conversion is made on upload rather than queued, so the
     * models can look at it in the same request that added it.
     */
    public function registerMediaConversions(?BaseMedia $media = null): void
    {
        $this->addMediaConversion(self::REFERENCE)
            ->performOnCollections(self::CONTENT_REFERENCES, self::STYLE_REFERENCES)
            ->nonQueued()
            ->fit(Fit::Max, self::REFERENCE_MAX_EDGE, self::REFERENCE_MAX_EDGE)
            ->format('jpg')
            ->quality(80);
    }
}
