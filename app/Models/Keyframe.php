<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KeyframeStatus;
use App\Events\KeyframeDeleting;
use App\Support\Shots\KeyframeStaging;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * Custom properties on a render made by an adjustment: what the director
     * asked, and the instruction the image model actually received.
     */
    public const TWEAK_REQUEST = 'tweak_request';

    /** On a render: the adjustment was asked by the automatic check (Fix), not by the director. */
    public const TWEAK_FROM_CHECK = 'tweak_from_check';

    public const TWEAK_INSTRUCTION = 'tweak_instruction';

    /** On a render: how the place differs from keyframe 1, found after drawing. */
    public const PLACE_ISSUES = 'place_issues';

    /** On a render drawn on a base image: the share of the background that stayed in place, from 0 to 1. */
    public const STILLNESS = 'stillness';

    /** On a render: its background moved compared with the image it was drawn on, even after drawing it again. */
    public const BACKGROUND_MOVED = 'background_moved';

    /** On a render: what the automatic check found wrong with it, kept as notes; nothing is redrawn by itself. */
    public const CHECK_ISSUES = 'check_issues';

    /** On a render: what the image model got, its prompt, the model and what each attached image was. */
    public const SENT = 'sent';

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'planned',
        'draw_version' => 0,
    ];

    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'deleting' => KeyframeDeleting::class,
    ];

    /**
     * @return array{
     *  status: 'App\\Enums\\KeyframeStatus',
     *  layout: 'array',
     *  staging: 'array',
     * }
     */
    protected function casts(): array
    {
        return [
            'status' => KeyframeStatus::class,
            'layout' => 'array',
            'staging' => 'array',
        ];
    }

    /**
     * How the keyframe is staged, in the fields the director edits.
     */
    public function staged(): KeyframeStaging
    {
        return KeyframeStaging::fromArray($this->staging);
    }

    /**
     * Stages the keyframe anew; its description is built from the fields. Saved by the caller.
     */
    public function restage(KeyframeStaging $staging): static
    {
        return $this->forceFill($staging->fields());
    }

    /**
     * The keyframe this one is drawn from: the one before it in the shot, or
     * the keyframe of another shot this shot continues from.
     *
     * @return BelongsTo<self, $this>
     */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_id');
    }

    /**
     * The keyframes drawn from this one.
     *
     * @return HasMany<self, $this>
     */
    public function dependents(): HasMany
    {
        return $this->hasMany(self::class, 'depends_on_id');
    }

    /**
     * Keyframes with work queued or running.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function busy(Builder $query): void
    {
        $query->whereIn('status', KeyframeStatus::busy());
    }

    public function isBusy(): bool
    {
        return $this->status->isBusy();
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
     * What the keyframe shows as the models get it: the description with the
     * spatial fact as its last sentence.
     */
    public function fullDescription(): string
    {
        return self::joined((string) $this->description, $this->spatial);
    }

    /**
     * A description with its spatial fact added as the last sentence, for a keyframe or a planned one.
     */
    public static function joined(string $description, ?string $spatial): string
    {
        $description = trim($description);
        $spatial = trim((string) $spatial);

        if ($spatial === '') {
            return $description;
        }

        return ($description === '' || preg_match('/[.!?]$/', $description) === 1 ? $description : $description . '.') . ' ' . $spatial;
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
