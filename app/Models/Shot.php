<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use App\Enums\ShotKind;
use App\Enums\ShotStatus;
use App\Enums\ShotTransition;
use App\Events\ShotDeleting;
use App\Jobs\GenerateVoiceOverAudio;
use App\Jobs\ReviewShot;
use Closure;
use Illuminate\Database\Eloquent\Collection;
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
     *  preferred_elements: 'array',
     *  keyframe_review: 'array',
     *  voice_over_tracks: 'array',
     *  storyline_options: 'array',
     *  chosen_storyline: 'array',
     *  storyline: 'array',
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
            'preferred_elements' => 'array',
            'keyframe_review' => 'array',
            'voice_over_tracks' => 'array',
            'storyline_options' => 'array',
            'chosen_storyline' => 'array',
            'storyline' => 'array',
            'merge_transition' => ShotTransition::class,
            'video_submitted_at' => 'datetime',
            'reviewing' => 'boolean',
            'rules' => 'array',
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
     * The storylines suggested by the AI for the director to choose from.
     *
     * @return list<array{title: string, storyline: string}>
     */
    public function storylineOptions(): array
    {
        return array_values($this->storyline_options ?? []);
    }

    /**
     * The director's brief as the writers read it: the takeaway, the context
     * and the cast and sets the director asked for. Older shots also carry a
     * subject and action written by hand.
     */
    public function brief(): string
    {
        $lines = ["Takeaway: {$this->takeaway}"];

        if (filled($this->subject)) {
            $lines[] = "Subject: {$this->subject}";
        }

        if (filled($this->action)) {
            $lines[] = "Action: {$this->action}";
        }

        if (filled($this->notes)) {
            $lines[] = "Context from the director: {$this->notes}";
        }

        $preferred = $this->preferredElements();

        if ($preferred->isNotEmpty()) {
            $lines[] = "The director wants these in the shot:\n" . $preferred->map(fn(Element $element) => '- ' . $element->promptLine())->join("\n");
        }

        return implode("\n", $lines);
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
     * The cast and sets the director wants the storylines to use, in the
     * library's order. Elements deleted since are left out.
     *
     * @return Collection<int, Element>
     */
    public function preferredElements(): Collection
    {
        $ids = $this->preferred_elements ?? [];

        if ($ids === []) {
            return new Collection();
        }

        return $this->project->elements()->whereKey($ids)->get();
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
     * @return list<array{title: string, description: string, spatial?: string, prompt?: string, must_show?: string, elements?: list<string>, copied?: bool}>
     */
    public function storylineKeyframes(): array
    {
        return array_values($this->storyline['keyframes'] ?? []);
    }

    /**
     * The rules the director set for this shot, such as "she never crosses the red line with more than one foot".
     *
     * @return list<string>
     */
    public function shotRules(): array
    {
        return collect((array) ($this->rules ?? []))->map(fn(mixed $rule) => trim((string) $rule))->filter(fn(string $rule) => $rule !== '')->values()->all();
    }

    /**
     * The shot's rules as lines for the writers, image prompts and checks; empty when there are none.
     */
    public function rulesBrief(): string
    {
        return collect($this->shotRules())->map(fn(string $rule) => "- {$rule}")->join("\n");
    }

    /**
     * Add a rule for this shot, once.
     */
    public function addRule(string $rule): void
    {
        $rule = trim($rule);

        if ($rule === '' || in_array(mb_strtolower($rule), array_map('mb_strtolower', $this->shotRules()), true)) {
            return;
        }

        $this->forceFill(['rules' => [...$this->shotRules(), $rule]])->save();
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
     * Whether the director chose the empty place every keyframe is drawn on.
     */
    public function hasChosenPlate(): bool
    {
        return $this->media()->where('collection_name', self::PLATE)->where('custom_properties->' . self::PLATE_CHOSEN, true)->exists();
    }

    /**
     * Where in the place the shot plays and how long it lasts, as the planner
     * chose it. The camera always frames a full shot and the light is always
     * the visual style's. Older plans have none.
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
