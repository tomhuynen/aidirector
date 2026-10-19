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
use Closure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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

    /** The spoken voice-over tracks, one per language, with the locale as a custom property. */
    public const VOICE_OVERS = 'voice_overs';

    /** On a merged shot's video: when each part starts in it, in seconds. */
    public const PART_STARTS = 'part_starts';

    /** On a merged shot's video: how long it lasts, in seconds. */
    public const VIDEO_SECONDS = 'seconds';

    /** The video as it was rendered, kept while the shot's video is a part cut out of it. */
    public const VIDEO_ORIGINAL = 'video_original';

    /** On a trimmed video: where it starts and ends in the rendered video, in seconds, and that video's media id. */
    public const VIDEO_TRIM = 'trim';

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
     * The brief as the writers read it: the director's idea for the shot.
     */
    public function brief(): string
    {
        return filled($this->idea) ? "Idea: {$this->idea}" : '';
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
     * @return list<array{title: string, description: string, staging?: array<string, mixed>, spatial?: string, elements?: list<string>, copied?: bool, seconds?: float|int}>
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

    public function isInsert(): bool
    {
        return $this->kind === ShotKind::INSERT;
    }

    /**
     * Whether every keyframe is drawn on its own instead of on one shared place.
     */
    public function drawsStandalone(): bool
    {
        return $this->kindOrScene()->drawsStandalone();
    }

    /**
     * Whether the start frame is the end frame of another shot seen from this
     * shot's camera, as a match cut: the same instant, the same poses, only
     * the framing changes. It is drawn once, from that end frame, without
     * options to choose from.
     */
    public function continuesPose(): bool
    {
        $from = $this->settingFrom();

        return $from !== null && $from['keyframe'] !== 0 && ! $this->drawsStandalone() && ! $this->rendersInSequence();
    }

    /**
     * Whether this shot is rendered as part of its whole sequence in one
     * clip, when a sequence video model is set: it draws one keyframe, its
     * start frame, in the place of the master plate, and the video model
     * plays the act and times the cuts. A montage and a presenter render on
     * their own.
     */
    public function rendersInSequence(): bool
    {
        return self::rendersSequences() && $this->group_key !== null && ! $this->isMontage() && ! $this->isPresenter();
    }

    /**
     * Whether sequences render as one clip, with the sequence video model.
     */
    public static function rendersSequences(): bool
    {
        return filled(Config::get('pipeline.models.sequence_video'));
    }

    /**
     * Whether the director picks the start frame from options drawn in the shot's place.
     */
    public function choosesStartFrame(): bool
    {
        return ! $this->drawsStandalone() && ! $this->continuesPose();
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

    /** The setting from another shot's end frame, whichever position that turns out to be. */
    public const LAST_KEYFRAME = -1;

    /**
     * Where the keyframes take their setting from: a keyframe of another shot,
     * its end frame (-1), or its place (0). Agreed in the plan chat or when
     * a story is directed into shots; without one, a shot planned together
     * with the shots before it continues from the end frame of the last of
     * them with people in it, as a match cut on the same instant. An insert
     * takes only the place from that frame. A shot that renders in its
     * sequence takes the place of the master plate.
     *
     * @return array{shot: self, keyframe: int, chosen: bool}|null
     */
    public function settingFrom(): ?array
    {
        if ($this->rendersInSequence()) {
            $master = $this->masterShot();

            return $master === null ? null : ['shot' => $master, 'keyframe' => 1, 'chosen' => false];
        }

        $chosen = $this->storyline['setting_from'] ?? null;

        if (is_array($chosen)) {
            $source = isset($chosen['shot_id']) ? self::query()->where('project_id', $this->project_id)->whereKey($chosen['shot_id'])->first() : null;

            return $source === null || $source->is($this) ? null : ['shot' => $source, 'keyframe' => max(self::LAST_KEYFRAME, (int) ($chosen['keyframe'] ?? 0)), 'chosen' => true];
        }

        $previous = $this->shotBeforeInSequence();

        return $previous === null ? null : ['shot' => $previous, 'keyframe' => self::LAST_KEYFRAME, 'chosen' => false];
    }

    /**
     * The nearest shot before this one in its sequence whose frames hold
     * people, which this shot continues from. A montage or a presenter
     * starts on its own. The parts of a merged shot keep their place in the
     * sequence; the merged shot itself has no frames.
     */
    private function shotBeforeInSequence(): ?self
    {
        if ($this->group_key === null || $this->isMontage() || $this->isPresenter()) {
            return null;
        }

        return self::query()->where('project_id', $this->project_id)->whereNull('merge_transition')
            ->where('group_key', $this->group_key)->where('position', '<', $this->position)->orderByDesc('position')->get()
            ->first(fn(self $shot) => ! $shot->drawsStandalone());
    }

    /**
     * The first shot of this shot's sequence with people in its frames, whose
     * start frame is the master plate: every later shot of the sequence takes
     * the place from it, so the place is never copied from a copy. Null for
     * that first shot itself, a shot outside a sequence, a montage and a presenter.
     */
    public function masterShot(): ?self
    {
        if ($this->group_key === null || $this->isMontage() || $this->isPresenter()) {
            return null;
        }

        return self::query()->where('project_id', $this->project_id)->whereNull('merge_transition')
            ->where('group_key', $this->group_key)->where('position', '<', $this->position)->orderBy('position')->get()
            ->first(fn(self $shot) => ! $shot->drawsStandalone());
    }

    /**
     * The start frame of the master shot of the sequence, when it is drawn.
     */
    public function masterPlate(): ?Media
    {
        return $this->masterShot()?->keyframes()->with('media')->where('position', 1)->first()?->render();
    }

    /**
     * The latest earlier shot of the sequence filmed from the camera this
     * shot returns to: the same size of shot at the same aspect ratio. The
     * start frame is then drawn on that shot's end frame, so the place stays
     * exactly as it was. Only for a shot that continues the shot before.
     * The shots of a sequence share a project, so the same override means
     * the same aspect ratio.
     */
    public function returnShot(): ?self
    {
        if (! $this->continuesPose() || $this->group_key === null) {
            return null;
        }

        return self::query()->where('project_id', $this->project_id)->whereNull('merge_transition')
            ->where('group_key', $this->group_key)->where('position', '<', $this->position)->orderByDesc('position')->get()
            ->first(fn(self $shot) => ! $shot->drawsStandalone() && $shot->kindOrScene()->size() === $this->kindOrScene()->size() && $shot->aspect_ratio_override === $this->aspect_ratio_override);
    }

    /**
     * The end frame of the shot this one returns to the camera of, when it is drawn: the last view from that camera.
     */
    public function returnFrame(): ?Media
    {
        return $this->returnShot()?->endFrame()?->render();
    }

    /**
     * The render of the frame or the place this shot takes its setting from, when it is drawn.
     */
    public function settingRender(): ?Media
    {
        $from = $this->settingFrom();

        if ($from === null) {
            return null;
        }

        $source = $from['shot'];
        $position = $from['keyframe'] === self::LAST_KEYFRAME ? (int) $source->keyframes()->max('position') : $from['keyframe'];

        return $source->keyframes()->with('media')->where('position', max(1, $position))->first()?->render();
    }

    /**
     * The end frame: the last keyframe of the shot.
     */
    public function endFrame(): ?Keyframe
    {
        return $this->keyframes()->with('media')->reorder('position', 'desc')->first();
    }

    /**
     * Whether the director confirmed the end frame as it is drawn now, so the
     * shots that continue from it may be drawn. A changed end frame drops
     * the confirmation.
     */
    public function endFrameConfirmed(): bool
    {
        $end = $this->endFrame();
        $render = $end?->render();

        return $render !== null && $end->confirmed_render_id === $render->id;
    }

    /**
     * Whether the end frame can be confirmed: drawn and checked, and not confirmed yet.
     */
    public function canConfirmEndFrame(): bool
    {
        return in_array($this->status, [ShotStatus::KEYFRAMES_READY, ShotStatus::VIDEO_PENDING, ShotStatus::VIDEO_READY], true)
            && ! $this->reviewing
            && ! $this->keyframes()->busy()->exists()
            && $this->endFrame()?->render() !== null
            && ! $this->endFrameConfirmed();
    }

    /**
     * Confirms the end frame as it is drawn now, and draws the shots that waited for it.
     */
    public function confirmEndFrame(): void
    {
        $end = $this->endFrame();

        $end->forceFill(['confirmed_render_id' => $end->render()?->id])->save();

        self::drawWaitingShots($this->project_id);
    }

    /**
     * The shots after this one that continue from its end frame.
     *
     * @return Collection<int, self>
     */
    public function continuedBy(): Collection
    {
        return self::query()->where('project_id', $this->project_id)->whereNull('merge_transition')->where('position', '>', $this->position)->orderBy('position')->get()
            ->filter(function (self $shot) {
                $from = $shot->settingFrom();

                return $from !== null && $from['shot']->is($this) && $from['keyframe'] === self::LAST_KEYFRAME;
            })
            ->values();
    }

    /**
     * The shots that continue from this one and were drawn from an earlier
     * version of its end frame than the one drawn now.
     *
     * @return Collection<int, self>
     */
    public function staleFollowers(): Collection
    {
        $current = $this->endFrame()?->render()?->id;

        return $this->continuedBy()->filter(function (self $shot) use ($current) {
            $source = $shot->keyframes()->where('position', 1)->value('source_render_id');

            return $source !== null && $source !== $current;
        })->values();
    }

    /**
     * Starts the shots that continue from this one over, and the shots that
     * continue from those: they are drawn again once the end frame they
     * start from is confirmed.
     */
    public function resetFollowers(): void
    {
        $this->continuedBy()->each->startOverWhenReady();
    }

    /**
     * Keeps the shots that continue from this one as they are drawn, from the
     * end frame as it is now.
     */
    public function acceptFollowers(): void
    {
        $current = $this->endFrame()?->render()?->id;

        $this->staleFollowers()->each(fn(self $shot) => $shot->keyframes()->where('position', 1)->update(['source_render_id' => $current]));
    }

    /**
     * Starts the plan over and has it drawn as soon as what it waits for is
     * there, along with every shot that continues from this one.
     */
    public function startOverWhenReady(): void
    {
        $this->startPlanOver();
        $this->updateStoredJson('storyline', fn(?array $storyline) => [...($storyline ?? []), 'draw_when_ready' => true]);

        $this->continuedBy()->each->startOverWhenReady();
    }

    /**
     * What the setting from another shot needs that is not there yet, such as
     * "the place of SH100" before its keyframe 1 is chosen; null when it is
     * ready or the shot has its own setting.
     */
    public function settingWaitsFor(): ?string
    {
        $from = $this->settingFrom();

        if ($from === null) {
            return null;
        }

        $source = $from['shot'];

        return match (true) {
            $from['keyframe'] === 0 => $source->keyframes()->where('position', 1)->whereNotNull('render_id')->exists() ? null : __('the place of :shot', ['shot' => $source->code()]),
            $from['keyframe'] === self::LAST_KEYFRAME => $source->endFrameConfirmed() && ! $source->keyframes()->busy()->exists()
                ? null
                : __('your confirmation of the end frame of :shot', ['shot' => $source->code()]),
            default => $source->keyframes()->where('position', $from['keyframe'])->whereNotNull('render_id')->exists() ? null : __('keyframe :n of :shot', ['n' => $from['keyframe'], 'shot' => $source->code()]),
        };
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
        // A shot that continues the shot before has no start frame to choose: its keyframes are drawn in a row.
        $this->forceFill(['status' => $this->continuesPose() ? ShotStatus::KEYFRAMES_PENDING : ShotStatus::FIRST_KEYFRAME_PENDING, 'storyline_error' => null])->save();

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
     * place was chosen, an end frame was confirmed or a picture of the cast is ready.
     */
    public static function drawWaitingShots(int $projectId): void
    {
        self::query()->with('project')->where('project_id', $projectId)->where('status', ShotStatus::STORYLINE_READY)->get()
            ->filter(fn(self $shot) => $shot->waitsToDraw() && $shot->waitsFor() === null)
            ->each->drawKeyframes();
    }

    /**
     * Starts the plan over after it was drawn: the keyframes and the video
     * are thrown away, and whatever is still drawn or rendered for
     * the old plan stops. The plan and the conversation stay.
     */
    public function startPlanOver(): void
    {
        // First, so running jobs of the old plan stop at their next check.
        $this->increment('plan_version');

        $this->forgetKeyframes();
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
     * Whether this shot of a sequence is still as the direction left it:
     * nothing drawn and no conversation beyond its opening message. Only
     * such a shot may be changed or removed when the sequence is agreed anew.
     */
    public function isUntouchedInSequence(): bool
    {
        return in_array($this->status, [ShotStatus::DRAFT, ShotStatus::STORYLINE_READY], true)
            && count((array) ($this->plan_chat ?? [])) <= 1
            && ! $this->keyframes()->exists();
    }

    /**
     * A new shot at a position of the film, in a group, with an empty plan;
     * the shots from that position on move one place on.
     *
     * @param  array<model-property<self>, mixed>  $attributes
     */
    public static function insertInto(Project $project, int $position, string $group, self $like, array $attributes): self
    {
        $project->shots()->where('position', '>=', $position)->increment('position');

        return $project->allShots()->create([
            'position' => $position,
            'group_key' => $group,
            'purpose_override' => $like->purpose_override,
            'aspect_ratio_override' => $like->aspect_ratio_override,
            'status' => ShotStatus::STORYLINE_READY,
            'chosen_storyline' => ['storyline' => ''],
            'storyline' => ['framing' => ['spot' => '', 'seconds' => null], 'keyframes' => []],
            ...$attributes,
        ]);
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
     * Change the planned keyframe at a position: merge `$changes` into it and
     * drop the `$forget` keys, such as a spatial fact that no longer fits.
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
     * Whether the start frame is an exact copy of a keyframe of another shot,
     * made when this shot was added after it. It is never drawn, and the plan
     * goes on from it.
     */
    public function opensWithCopy(): bool
    {
        return ($this->storyline['opening_copy'] ?? false) === true;
    }

    /**
     * Whether the planned keyframe at a position is a copy the director has not described yet.
     */
    public function plannedKeyframeIsCopy(int $position): bool
    {
        return (bool) ($this->storylineKeyframes()[$position - 1]['copied'] ?? false);
    }

    /**
     * Remove the rendered keyframes and their images, for example when the plan changes.
     */
    public function forgetKeyframes(): void
    {
        // The start frame of a shot that opens with a copy stays: it is never drawn, only copied when the shot was added.
        $this->keyframes()->when($this->opensWithCopy(), fn($keyframes) => $keyframes->where('position', '>', 1))->get()->each->delete();
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
        $this->clearMediaCollection(self::VIDEO_ORIGINAL);

        $this->forceFill(['video_prompt' => null, 'video_summary' => null, 'video_job_id' => null, 'video_error' => null])->save();
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

    /**
     * The rendered video the shot's video was cut from, while it is cut.
     */
    public function videoOriginal(): ?Media
    {
        $original = $this->getFirstMedia(self::VIDEO_ORIGINAL);
        $trim = $this->video()?->getCustomProperty(self::VIDEO_TRIM);

        return $original !== null && is_array($trim) && ($trim['original'] ?? null) === $original->getKey() ? $original : null;
    }

    /**
     * Whether the video can be cut shorter: a video of its own, made from
     * the keyframes; a merged shot is timed by its parts and a presenter has
     * a video per language.
     */
    public function canTrimVideo(): bool
    {
        return $this->video() !== null && ! $this->isMerged() && ! $this->isPresenter() && is_numeric($this->video()->getCustomProperty(self::VIDEO_SECONDS));
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::VOICE_OVERS)->acceptsMimeTypes(['audio/mpeg', 'audio/mp3']);
        $this->addMediaCollection(self::VIDEO)->singleFile()->acceptsMimeTypes(['video/mp4', 'video/webm', 'video/quicktime']);
        $this->addMediaCollection(self::VIDEO_ORIGINAL)->singleFile()->acceptsMimeTypes(['video/mp4', 'video/webm', 'video/quicktime']);
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
     * How long each keyframe lasts, in order, as the planner timed them: for
     * a scene or close-up how long the act takes to reach the end frame and
     * how long that is held, for a montage how long each still shows, as
     * shares of the shot length. Without a plan the length is shared evenly.
     *
     * @param  Collection<int, Keyframe>|null  $keyframes
     * @return list<float>
     */
    public function keyframeSeconds(?Collection $keyframes = null): array
    {
        $keyframes = ($keyframes ?? $this->keyframes()->get())->sortBy('position')->values();
        $plans = $this->storylineKeyframes();
        $count = $keyframes->isEmpty() ? count($plans) : $keyframes->count();
        $planned = array_map(fn(int $index) => is_numeric($plans[$index]['seconds'] ?? null) ? max(0.0, (float) $plans[$index]['seconds']) : null, range(0, max($count - 1, 0)));
        $length = (float) self::clampSeconds($this->durationInSeconds());
        $timed = array_filter($planned, fn(?float $seconds) => $seconds !== null);

        if ($count === 0) {
            return [];
        }

        if (array_sum($timed) <= 0) {
            return array_fill(0, $count, $length / $count);
        }

        // A keyframe the planner did not time, such as a copied start frame, takes the average of the others.
        $planned = array_map(fn(?float $seconds) => $seconds ?? array_sum($timed) / count($timed), $planned);

        return array_map(fn(float $seconds) => $seconds * $length / array_sum($planned), $planned);
    }

    /**
     * A length the video model accepts.
     */
    public static function clampSeconds(int $seconds): int
    {
        return max((int) Config::get('pipeline.video.min_duration'), min((int) Config::get('pipeline.video.max_duration'), $seconds));
    }
}
