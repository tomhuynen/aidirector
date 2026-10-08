<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Enums\ShotTransition;
use App\Events\ShotDeleting;
use App\Jobs\GenerateKeyframes;
use App\Jobs\GenerateVoiceOverAudio;
use App\Jobs\RetimeShot;
use App\Jobs\ReviewShot;
use Closure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class Shot extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\ShotFactory> */
    use HasFactory;
    use HasSqids;
    use InteractsWithMedia;
    use UsesTenantConnection;

    public const VIDEO = 'video';

    /**
     * The place of the shot without people, made from keyframe 1 when it
     * shows people, so later keyframes add people to it instead of moving
     * them through a redrawn place.
     */
    public const PLATE = 'plate';

    /** On the plate: the render of keyframe 1 it was made from. */
    public const PLATE_FROM = 'from_render';

    /** Empty places drawn from the whole plan, for the director to choose the one every keyframe is drawn on. */
    public const PLATE_OPTIONS = 'plate_options';

    /** On the plate: it was chosen from the options, so it is the base of every keyframe. */
    public const PLATE_CHOSEN = 'chosen';

    /** The place in a later state, such as a door that is closed by now: the keyframe it starts at and later ones are drawn on it. */
    public const PLACE_STATES = 'place_states';

    /** On a place state: the place and the changes up to its keyframe it was made for, so a new plan or place makes it unused. */
    public const PLACE_STATE_KEY = 'key';

    /** The spoken voice-over tracks, one per language, with the locale as a custom property. */
    public const VOICE_OVERS = 'voice_overs';

    /** On a merged shot's video: when each part starts in it, in seconds. */
    public const PART_STARTS = 'part_starts';

    /** On a merged shot's video: how long it lasts, in seconds. */
    public const VIDEO_SECONDS = 'seconds';

    /** The clip of each still of a montage, with its position as a custom property, kept until they are joined. */
    public const MONTAGE_CLIPS = 'montage_clips';

    /** The video of a presenter shot per language, with its sound, with the locale as a custom property. */
    public const PRESENTER_VIDEOS = 'presenter_videos';

    protected $guarded = [];

    /**
     * Relations and files are removed by listeners, never by the database.
     */
    protected $dispatchesEvents = [
        'deleting' => ShotDeleting::class,
    ];

    protected $attributes = [
        'status' => ShotStatus::DRAFT->value,
    ];

    /**
     * @return array{
     *  status: 'App\Enums\ShotStatus',
     *  kind: 'App\Enums\ShotKind',
     *  montage_clips: 'array',
     *  purpose_override: 'App\Enums\ProjectPurpose',
     *  aspect_ratio_override: 'App\Enums\AspectRatio',
     *  keyframe_review: 'array',
     *  voice_over_tracks: 'array',
     *  chosen_storyline: 'array',
     *  storyline: 'array',
     *  plan_chat: 'array',
     *  plan_version: 'integer',
     *  merge_transition: 'App\Enums\ShotTransition',
     *  video_submitted_at: 'datetime',
     *  reviewing: 'boolean',
     * }
     */
    protected function casts(): array
    {
        return [
            'status' => ShotStatus::class,
            'kind' => ShotKind::class,
            'montage_clips' => 'array',
            'purpose_override' => ProjectPurpose::class,
            'aspect_ratio_override' => AspectRatio::class,
            'keyframe_review' => 'array',
            'voice_over_tracks' => 'array',
            'chosen_storyline' => 'array',
            'storyline' => 'array',
            'plan_chat' => 'array',
            'plan_version' => 'integer',
            'merge_transition' => ShotTransition::class,
            'video_submitted_at' => 'datetime',
            'reviewing' => 'boolean',
        ];
    }

    /**
     * The shots this one was merged from, in their order. They are hidden
     * from the shot list but kept as they were, so the merge can be undone.
     *
     * @return HasMany<Shot, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id')->orderBy('position');
    }

    /**
     * The merged shot this one is part of, if any.
     *
     * @return BelongsTo<Shot, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /**
     * Whether this shot was made by merging others: its video is their clips joined.
     */
    public function isMerged(): bool
    {
        return $this->merge_transition !== null;
    }

    /** @return HasMany<Keyframe, $this> */
    public function keyframes(): HasMany
    {
        return $this->hasMany(Keyframe::class)->orderBy('position');
    }

    /** @return MorphMany<Generation, $this> */
    public function generations(): MorphMany
    {
        return $this->morphMany(Generation::class, 'generatable');
    }

    /**
     * The brief as the writers read it: the takeaway, and the idea agreed for
     * this shot when it is part of a sequence.
     */
    public function brief(): string
    {
        return implode("\n", array_filter([
            "Takeaway: {$this->takeaway}",
            filled($this->notes) ? "The idea for this shot: {$this->notes}" : null,
        ]));
    }

    /**
     * Start the spoken tracks of every voice-over language of the project,
     * when the shot has a voice-over text. Each language is its own job.
     */
    public function startVoiceOverAudio(): void
    {
        $locales = $this->project->settings->enabledLocales();

        // A presenter speaks the voice-over in the video itself, in every language.
        if ($locales === [] || blank($this->voice_over) || $this->isPresenter()) {
            return;
        }

        foreach ($locales as $locale) {
            $this->setVoiceOverTrack($locale, 'pending');
            GenerateVoiceOverAudio::dispatch($this, $locale);
        }
    }

    /**
     * Record how the track of one language is doing: pending, ready or failed.
     */
    public function setVoiceOverTrack(string $locale, string $status, ?string $error = null): void
    {
        $this->updateStoredJson('voice_over_tracks', fn(?array $tracks) => [
            ...($tracks ?? []),
            $locale => array_filter(['status' => $status, 'error' => $error]),
        ]);
    }

    /**
     * Change one JSON column starting from what is stored right now, with the
     * row locked, and save only that column. Jobs and requests that work on the
     * same shot at the same time then add to each other's changes instead of
     * writing back a copy they loaded earlier.
     *
     * @param  Closure(mixed): mixed  $change  gets the stored value and returns the new one
     */
    public function updateStoredJson(string $column, Closure $change): void
    {
        $this->getConnection()->transaction(function () use ($column, $change) {
            $stored = static::query()->lockForUpdate()->find($this->getKey());

            if ($stored === null) {
                return;
            }

            $stored->forceFill([$column => $change($stored->getAttribute($column))])->save();

            $this->setAttribute($column, $stored->getAttribute($column));
            $this->syncOriginalAttribute($column);
        });
    }

    /**
     * The storyline the director picked from the suggestions.
     *
     * @return array{title: string, storyline: string}|null
     */
    public function chosenStoryline(): ?array
    {
        return $this->chosen_storyline;
    }

    /**
     * The keyframes planned for the chosen storyline.
     *
     * Older plans also carry a `prompt`: an image instruction no longer written or used; the description goes to the image model.
     *
     * @return list<array{title: string, description: string, spatial?: string, place_change?: string, place_part?: string, prompt?: string, elements?: list<string>, copied?: bool}>
     */
    public function storylineKeyframes(): array
    {
        return array_values($this->storyline['keyframes'] ?? []);
    }

    /**
     * Whether the shot is a row of separate stills instead of one place with a camera that does not move.
     */
    public function isMontage(): bool
    {
        return $this->kind === ShotKind::MONTAGE;
    }

    /**
     * The kind of shot; a shot planned before kinds existed is a scene.
     */
    public function kindOrScene(): ShotKind
    {
        return $this->kind ?? ShotKind::SCENE;
    }

    /**
     * Whether one person speaks the voice-over to the camera, with a video per language.
     */
    public function isPresenter(): bool
    {
        return $this->kind === ShotKind::PRESENTER;
    }

    /**
     * Whether every keyframe is drawn on its own instead of on one shared place.
     */
    public function drawsStandalone(): bool
    {
        return $this->isMontage() || $this->isPresenter();
    }

    /**
     * The label of the shot in the sequence, such as SH070.
     */
    public function code(): string
    {
        return 'SH' . str_pad((string) ($this->position * 10), 3, '0', STR_PAD_LEFT);
    }

    /**
     * The shot directly before this one in the sequence, if any.
     */
    public function previousShot(): ?self
    {
        return self::query()->where('project_id', $this->project_id)->whereNull('merged_into_id')
            ->where('position', '<', $this->position)->orderByDesc('position')->first();
    }

    /**
     * The shot directly after this one in the sequence, if any.
     */
    public function nextShot(): ?self
    {
        return self::query()->where('project_id', $this->project_id)->whereNull('merged_into_id')
            ->where('position', '>', $this->position)->orderBy('position')->first();
    }

    /** The setting from another shot's last keyframe, whichever position that turns out to be. */
    public const LAST_KEYFRAME = -1;

    /**
     * Where the keyframes take their setting from: a keyframe of another shot,
     * its last keyframe (-1), or its place (0). Agreed in the plan chat or when
     * a story is split into shots; without one, a close-up planned together
     * with the shot before it continues from that shot's last keyframe, as a
     * cut-in on the same moment.
     *
     * @return array{shot: self, keyframe: int, chosen: bool}|null
     */
    public function settingFrom(): ?array
    {
        $chosen = $this->storyline['setting_from'] ?? null;

        if (is_array($chosen)) {
            $source = isset($chosen['shot_id']) ? self::query()->where('project_id', $this->project_id)->whereKey($chosen['shot_id'])->first() : null;

            return $source === null || $source->is($this) ? null : ['shot' => $source, 'keyframe' => max(self::LAST_KEYFRAME, (int) ($chosen['keyframe'] ?? 0)), 'chosen' => true];
        }

        $previous = $this->kindOrScene() === ShotKind::CLOSE_UP && $this->group_key !== null ? $this->previousShot() : null;

        return $previous !== null && $previous->group_key === $this->group_key
            ? ['shot' => $previous, 'keyframe' => self::LAST_KEYFRAME, 'chosen' => false]
            : null;
    }

    /**
     * What the setting from another shot needs that is not there yet, such as
     * "the place of SH100" before that place is chosen; null when it is ready
     * or the shot has its own setting.
     */
    public function settingWaitsFor(): ?string
    {
        $from = $this->settingFrom();

        if ($from === null) {
            return null;
        }

        $source = $from['shot'];

        return match (true) {
            $from['keyframe'] === 0 => $source->hasChosenPlate() || $source->keyframes()->where('position', 1)->whereNotNull('render_id')->exists() ? null : __('the place of :shot', ['shot' => $source->code()]),
            $from['keyframe'] === self::LAST_KEYFRAME => in_array($source->status, [ShotStatus::KEYFRAMES_READY, ShotStatus::VIDEO_PENDING, ShotStatus::VIDEO_READY], true) && ! $source->keyframes()->where('rendering', true)->exists()
                ? null
                : __('the keyframes of :shot', ['shot' => $source->code()]),
            default => $source->keyframes()->where('position', $from['keyframe'])->whereNotNull('render_id')->exists() ? null : __('keyframe :n of :shot', ['n' => $from['keyframe'], 'shot' => $source->code()]),
        };
    }

    /**
     * The chosen place of the shot this scene takes its place from: the same
     * place, used as it is, so nothing new is offered to choose from.
     */
    public function inheritedPlate(): ?Media
    {
        $from = $this->settingFrom();

        if ($from === null || $from['keyframe'] !== 0 || ! $this->kindOrScene()->usesPlace()) {
            return null;
        }

        return $from['shot']->media()->where('collection_name', self::PLATE)->where('custom_properties->' . self::PLATE_CHOSEN, true)->latest('id')->first();
    }

    /**
     * What the keyframes wait for before they can be drawn: the pictures of
     * new cast and sets, or the setting from another shot; null when nothing.
     */
    public function waitsFor(): ?string
    {
        $named = collect($this->storylineKeyframes())->flatMap(fn(array $keyframe) => (array) ($keyframe['elements'] ?? []))->unique()->all();

        if ($named !== [] && Element::query()->where('project_id', $this->project_id)->whereIn('name', $named)->where('rendering', true)->exists()) {
            return __('the pictures of the new cast and sets');
        }

        return $this->settingWaitsFor();
    }

    /**
     * Starts drawing the keyframes of the plan, or has them drawn as soon as
     * what they wait for is there.
     */
    public function drawKeyframes(): void
    {
        if ($this->waitsFor() !== null) {
            $this->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), 'draw_when_ready' => true]);

            return;
        }

        $this->updateStoredJson('storyline', fn(?array $storyline) => array_diff_key($storyline ?? [], ['draw_when_ready' => true]));
        $this->forceFill(['status' => ShotStatus::FIRST_KEYFRAME_PENDING, 'storyline_error' => null])->save();

        GenerateKeyframes::dispatch($this);
    }

    /**
     * Whether drawing was asked for and the keyframes still wait for something.
     */
    public function waitsToDraw(): bool
    {
        return ($this->storyline['draw_when_ready'] ?? false) === true && $this->status === ShotStatus::STORYLINE_READY;
    }

    /**
     * Draws the shots of the project that waited and have everything now: a
     * place was chosen, keyframes were drawn or a picture of the cast is ready.
     */
    public static function drawWaitingShots(int $projectId): void
    {
        self::query()->with('project')->where('project_id', $projectId)->where('status', ShotStatus::STORYLINE_READY)->get()
            ->filter(fn(self $shot) => $shot->waitsToDraw() && $shot->waitsFor() === null)
            ->each->drawKeyframes();
    }

    /**
     * Starts the plan over after it was drawn: the keyframes, the places and
     * the video are thrown away, and whatever is still drawn or rendered for
     * the old plan stops. The plan and the conversation stay.
     */
    public function startPlanOver(): void
    {
        // First, so running jobs of the old plan stop at their next check.
        $this->increment('plan_version');

        $this->forgetKeyframes();
        $this->clearMediaCollection(self::PLATE_OPTIONS);
        $this->clearMediaCollection(self::PLATE);
        $this->clearMediaCollection(self::PLACE_STATES);
        $this->clearMediaCollection(self::MONTAGE_CLIPS);
        $this->clearMediaCollection(self::PRESENTER_VIDEOS);

        $this->forceFill([
            'status' => ShotStatus::STORYLINE_READY,
            'storyline_error' => null,
            'montage_clips' => null,
            'reviewing' => false,
        ])->save();
    }

    /**
     * Whether this shot of a sequence is still as the split left it: nothing
     * planned or drawn and no conversation beyond its opening message. Only
     * such a shot may be changed or removed when the sequence is agreed anew.
     */
    public function isUntouchedInSequence(): bool
    {
        return in_array($this->status, [ShotStatus::DRAFT, ShotStatus::STORYLINE_READY], true)
            && $this->storylineKeyframes() === []
            && count((array) ($this->plan_chat ?? [])) <= 1
            && ! $this->keyframes()->exists();
    }

    /**
     * Whether the director chose the empty place every keyframe is drawn on.
     */
    public function hasChosenPlate(): bool
    {
        return $this->media()->where('collection_name', self::PLATE)->where('custom_properties->' . self::PLATE_CHOSEN, true)->exists();
    }

    /**
     * Where in the place the shot plays and how long it lasts. The camera
     * follows the kind of shot and the light is always the visual style's.
     *
     * @return array{spot: string, seconds: int|null}|null
     */
    public function storylineFraming(): ?array
    {
        $framing = $this->storyline['framing'] ?? null;

        if (! is_array($framing)) {
            return null;
        }

        return [
            'spot' => (string) ($framing['spot'] ?? ''),
            'seconds' => is_numeric($framing['seconds'] ?? null) ? self::clampSeconds((int) $framing['seconds']) : null,
        ];
    }

    /**
     * Replace the planned keyframes and keep the rest of the plan, such as the framing.
     *
     * @param  array<int, array<string, mixed>>  $keyframes
     */
    public function replacePlannedKeyframes(array $keyframes): void
    {
        $this->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), 'keyframes' => array_values($keyframes)]);
    }

    /**
     * Add a planned keyframe at the end of the stored plan.
     *
     * @param  array<string, mixed>  $keyframe
     */
    public function appendPlannedKeyframe(array $keyframe): void
    {
        $this->updateStoredJson('storyline', fn(?array $storyline) => [
            ...($storyline ?? []),
            'keyframes' => [...array_values($storyline['keyframes'] ?? []), $keyframe],
        ]);
    }

    /**
     * Change the planned keyframe at a position: merge `$changes` into it and
     * drop the `$forget` keys, such as a must show that no longer fits.
     *
     * @param  array<string, mixed>  $changes
     * @param  list<string>  $forget
     */
    public function updatePlannedKeyframe(int $position, array $changes, array $forget = []): void
    {
        $this->updateStoredJson('storyline', function (?array $storyline) use ($position, $changes, $forget) {
            $keyframes = array_values($storyline['keyframes'] ?? []);
            $index = $position - 1;

            if (isset($keyframes[$index])) {
                $keyframes[$index] = Arr::except([...$keyframes[$index], ...$changes], $forget);
            }

            return [...($storyline ?? []), 'keyframes' => $keyframes];
        });
    }

    /**
     * Whether the planned keyframe at a position is a copy the director has not described yet.
     */
    public function plannedKeyframeIsCopy(int $position): bool
    {
        return (bool) ($this->storylineKeyframes()[$position - 1]['copied'] ?? false);
    }

    /**
     * Keyframes can be moved or deleted once the shot has its keyframes and
     * none of them is being drawn.
     *
     * @param  iterable<Keyframe>  $keyframes
     */
    public function canArrangeKeyframes(iterable $keyframes): bool
    {
        if (! in_array($this->status, [ShotStatus::KEYFRAMES_READY, ShotStatus::VIDEO_READY], true)) {
            return false;
        }

        foreach ($keyframes as $keyframe) {
            if ($keyframe->rendering) {
                return false;
            }
        }

        return true;
    }

    /**
     * Number the given keyframes in their order and keep the planned keyframes
     * in step, since the plan is read by position. Keyframes left out keep
     * their row; the caller deletes them.
     *
     * @param  list<Keyframe>  $keyframes
     */
    public function arrangeKeyframes(array $keyframes): void
    {
        $plans = $this->storylineKeyframes();

        $arranged = array_map(fn(Keyframe $keyframe) => $plans[$keyframe->position - 1] ?? array_filter([
            'title' => $keyframe->title,
            'description' => $keyframe->description,
            'spatial' => $keyframe->spatial,
        ]), $keyframes);

        // Old position => new one. A copy still has its original's position and comes after it, so the original keeps the mapping.
        $moved = [];

        foreach ($keyframes as $index => $keyframe) {
            $moved[$keyframe->position] ??= $index + 1;
        }

        foreach ($keyframes as $index => $keyframe) {
            $keyframe->forceFill(['position' => $index + 1])->save();
        }

        $this->unsetRelation('keyframes');
        $this->updateStoredJson('keyframe_review', fn(?array $review) => self::renumberedReview($review, $moved));
        $this->replacePlannedKeyframes($arranged);
        // A keyframe deleted or copied: the shot is timed again for what its keyframes show now.
        if (count($plans) !== count($arranged)) {
            RetimeShot::dispatch($this);
        }

        // The order is what the review judges, so it looks again; the renumbered notes stand until then.
        ReviewShot::after($this);
    }

    /**
     * The review with its notes and resolved issues moved along with their
     * keyframes. Notes about deleted keyframes go; older notes that only name
     * keyframes in their text cannot be moved and go too.
     *
     * @param  array<string, mixed>|null  $review
     * @param  array<int, int>  $moved  old position => new position
     * @return array<string, mixed>|null
     */
    private static function renumberedReview(?array $review, array $moved): ?array
    {
        if ($review === null) {
            return null;
        }

        // Position 0 marks an issue resolved for the whole shot.
        $renumber = fn(array $positions) => array_values(array_filter(
            array_map(fn(mixed $position) => (int) $position === 0 ? 0 : ($moved[(int) $position] ?? null), $positions),
            fn(?int $position) => $position !== null,
        ));

        $notes = collect((array) ($review['notes'] ?? []))
            ->filter(fn(mixed $note) => is_array($note))
            ->map(fn(array $note) => [...$note, 'keyframes' => $renumber((array) ($note['keyframes'] ?? [])), 'named' => (array) ($note['keyframes'] ?? []) !== []])
            ->reject(fn(array $note) => $note['named'] && $note['keyframes'] === [])
            ->map(fn(array $note) => Arr::except($note, 'named'))
            ->values()
            ->all();

        $resolved = collect((array) ($review['resolved'] ?? []))
            ->map(fn(mixed $positions) => $renumber((array) $positions))
            ->filter()
            ->all();

        return [
            ...$review,
            'clear' => $notes === [] ? true : (bool) ($review['clear'] ?? true),
            'notes' => $notes,
            'resolved' => $resolved,
        ];
    }

    /**
     * Remove the rendered keyframes and their images, for example when the plan changes.
     */
    public function forgetKeyframes(): void
    {
        $this->keyframes()->get()->each->delete();
        $this->unsetRelation('keyframes');
        $this->forceFill(['keyframe_review' => null]);
        $this->forgetVideo();
    }

    /**
     * Remove the video, which no longer matches once the keyframes change.
     */
    public function forgetVideo(): void
    {
        $this->clearMediaCollection(self::VIDEO);

        $this->forceFill(['video_prompt' => null, 'video_job_id' => null, 'video_error' => null])->save();
    }

    /**
     * The resolution videos render at, which is set on the project.
     */
    public function videoResolution(): string
    {
        return $this->project->videoResolution();
    }

    public function video(): ?Media
    {
        return $this->getFirstMedia(self::VIDEO);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::VOICE_OVERS)->acceptsMimeTypes(['audio/mpeg', 'audio/mp3']);
        $this->addMediaCollection(self::VIDEO)->singleFile()->acceptsMimeTypes(['video/mp4', 'video/webm', 'video/quicktime']);
        $this->addMediaCollection(self::PLATE)->singleFile()->acceptsMimeTypes(['image/png', 'image/jpeg', 'image/webp']);
        $this->addMediaCollection(self::PLATE_OPTIONS)->acceptsMimeTypes(['image/png', 'image/jpeg', 'image/webp']);
        $this->addMediaCollection(self::PLACE_STATES)->acceptsMimeTypes(['image/png', 'image/jpeg', 'image/webp']);
        $this->addMediaCollection(self::PRESENTER_VIDEOS)->acceptsMimeTypes(['video/mp4', 'video/webm', 'video/quicktime']);
        $this->addMediaCollection(self::MONTAGE_CLIPS)->acceptsMimeTypes(['video/mp4', 'video/webm', 'video/quicktime']);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function purpose(): ProjectPurpose
    {
        return $this->purpose_override ?? $this->project->purpose;
    }

    public function aspectRatio(): AspectRatio
    {
        return $this->aspect_ratio_override ?? $this->project->aspect_ratio;
    }

    public function durationInSeconds(): int
    {
        return $this->duration ?? $this->storylineFraming()['seconds'] ?? $this->project->default_duration;
    }

    /**
     * A length the video model accepts.
     */
    public static function clampSeconds(int $seconds): int
    {
        return max((int) Config::get('pipeline.video.min_duration'), min((int) Config::get('pipeline.video.max_duration'), $seconds));
    }
}
