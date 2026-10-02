<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use App\Enums\ShotSize;
use App\Enums\ShotStatus;
use App\Enums\ShotTransition;
use App\Events\ShotDeleting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
     *  purpose_override: 'App\Enums\ProjectPurpose',
     *  aspect_ratio_override: 'App\Enums\AspectRatio',
     *  preferred_elements: 'array',
     *  storyline_options: 'array',
     *  chosen_storyline: 'array',
     *  storyline: 'array',
     *  merge_transition: 'App\Enums\ShotTransition',
     * }
     */
    protected function casts(): array
    {
        return [
            'status' => ShotStatus::class,
            'purpose_override' => ProjectPurpose::class,
            'aspect_ratio_override' => AspectRatio::class,
            'preferred_elements' => 'array',
            'storyline_options' => 'array',
            'chosen_storyline' => 'array',
            'storyline' => 'array',
            'merge_transition' => ShotTransition::class,
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
     * @return list<array{title: string, description: string, prompt?: string, elements?: list<string>}>
     */
    public function storylineKeyframes(): array
    {
        return array_values($this->storyline['keyframes'] ?? []);
    }

    /**
     * How the shot is framed and where in the place it plays, as the planner
     * chose it. Older plans have none.
     *
     * @return array{size: ShotSize, spot: string, light: string|null, seconds: int|null}|null
     */
    public function storylineFraming(): ?array
    {
        $framing = $this->storyline['framing'] ?? null;
        $size = is_array($framing) ? ShotSize::tryFrom((string) ($framing['size'] ?? '')) : null;

        if ($size === null) {
            return null;
        }

        $light = trim((string) ($framing['light'] ?? ''));

        return [
            'size' => $size,
            'spot' => (string) ($framing['spot'] ?? ''),
            'light' => $light === '' || str_starts_with(strtolower($light), 'as the visual style') ? null : $light,
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
        $this->forceFill(['storyline' => [...($this->storyline ?? []), 'keyframes' => array_values($keyframes)]])->save();
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

        $arranged = array_map(fn(Keyframe $keyframe) => $plans[$keyframe->position - 1] ?? [
            'title' => $keyframe->title,
            'description' => $keyframe->description,
            'prompt' => $keyframe->description,
        ], $keyframes);

        foreach ($keyframes as $index => $keyframe) {
            $keyframe->forceFill(['position' => $index + 1])->save();
        }

        $this->unsetRelation('keyframes');
        $this->replacePlannedKeyframes($arranged);
    }

    /**
     * Remove the rendered keyframes and their images, for example when the plan changes.
     */
    public function forgetKeyframes(): void
    {
        $this->keyframes()->get()->each->delete();
        $this->unsetRelation('keyframes');
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
        $this->addMediaCollection(self::VIDEO)->singleFile()->acceptsMimeTypes(['video/mp4', 'video/webm', 'video/quicktime']);
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
