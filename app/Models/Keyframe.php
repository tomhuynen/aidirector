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

    /** The whole cut-out of what was moved by hand on a version, also the part outside the frame, so it comes back whole when moved again; with the version, where it lies and whether it is a thing. */
    public const LAYERS = 'layers';

    /** The grey staging a staged keyframe 1 was made from, kept per render (custom property 'render'), to see what the edit model got. */
    public const STAGINGS = 'stagings';

    public const THUMBNAIL = 'thumbnail';

    /** A presenter's keyframe without the presenter: the still background their video is put on, with the render it was made from. */
    public const PRESENTER_BACKGROUND = 'presenter_background';

    /** On the presenter background: the id of the render it was made from. */
    public const FROM_RENDER = 'from_render';

    /**
     * Custom properties on a render made by an adjustment: what the director
     * asked, and the instruction the image model actually received.
     */
    public const TWEAK_REQUEST = 'tweak_request';

    /** On a render: the adjustment was asked by the automatic check (Fix), not by the director. */
    public const TWEAK_FROM_CHECK = 'tweak_from_check';

    public const TWEAK_INSTRUCTION = 'tweak_instruction';

    /** While rendering: the drawn image is being checked. */
    public const STAGE_CHECKING = 'checking';

    /** While rendering: the drawn people are cut out and placed on the chosen place. */
    public const STAGE_COMPOSITING = 'compositing';

    /** On a render: after a move, why the image no longer fits the keyframe's point in the story. */
    public const MOVE_WARNING = 'move_warning';

    /** On a version where a person was moved by hand: where they were put, as shares of the image, for fitting their pose to it. */
    public const MOVED_BOX = 'moved_box';

    /** On a version: what lies on top of the place, small: the people as found by the cut-out model and the things taken from the drawing, such as a helicopter flying in, so a click can pick one whole. */
    public const TOP_LAYER_MASK = 'people_mask';

    /** On a version: what its top-layer mask was made from; a mask made from another cast or other handled things is made again. */
    public const TOP_LAYER_KEY = 'top_layer_key';

    /** On a version: of what lies on top of the place, only the things, such as a helicopter flying in, small; they move without a shadow on the floor. */
    public const THINGS_MASK = 'things_mask';

    /** On a version where a thing, not a person, was moved by hand: it has no pose to fit. */
    public const MOVED_THING = 'moved_thing';

    /** On a version where a thing of the place was moved by hand, in this keyframe only: it lies on the place without its named things, and is no composite of the place any more. */
    public const ON_EMPTIED_PLACE = 'on_emptied_place';

    /** On a render drawn on a base image: the share of the background that stayed in place, from 0 to 1. */
    public const STILLNESS = 'stillness';

    /** On a render: its background moved compared with the image it was drawn on, even after drawing it again. */
    public const BACKGROUND_MOVED = 'background_moved';

    /** On a render drawn on a place: only its people and the objects they touch were kept, the rest is the place itself. */
    public const COMPOSITED = 'composited';

    /** On a render drawn on a place: why it was kept as it was drawn instead of put onto the place. */
    public const NOT_COMPOSITED = 'not_composited';

    /** On a render: what the automatic check found wrong with it, kept as notes; nothing is redrawn by itself. */
    public const CHECK_ISSUES = 'check_issues';

    /** On a render: what the image model got, its prompt, the model and what each attached image was. */
    public const SENT = 'sent';

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
     * What the keyframe shows as the models get it: the description with the
     * spatial fact as its last sentence.
     */
    public function fullDescription(): string
    {
        $text = self::joined((string) $this->description, $this->spatial);

        // A change the plan makes to the place at this keyframe, such as a door that closes now, so the checks, the review and the video expect it.
        $change = $this->relationLoaded('shot') ? trim((string) ($this->shot->storylineKeyframes()[$this->position - 1]['place_change'] ?? '')) : '';

        return $change === '' ? $text : "{$text} The place changes here: {$change}";
    }

    /**
     * A description with its spatial fact added as the last sentence, for a
     * keyframe or a planned one; not again when the description already says
     * it, such as one rewritten from the joined text.
     */
    public static function joined(string $description, ?string $spatial): string
    {
        $description = trim($description);
        $spatial = trim((string) $spatial);
        $plain = fn(string $text) => mb_strtolower((string) preg_replace('/\s+/', ' ', rtrim($text, '.!? ')), 'UTF-8');

        if ($spatial === '' || ($description !== '' && str_contains($plain($description), $plain($spatial)))) {
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
        $this->addMediaCollection(self::PRESENTER_BACKGROUND)->singleFile()->acceptsMimeTypes(['image/png']);
        $this->addMediaCollection(self::LAYERS)->acceptsMimeTypes(['image/png']);
        $this->addMediaCollection(self::STAGINGS)->acceptsMimeTypes(['image/png']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion(self::THUMBNAIL)
            ->performOnCollections(self::RENDERS)
            ->width(480)
            ->height(480);
    }
}
