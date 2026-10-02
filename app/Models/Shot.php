<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AspectRatio;
use App\Enums\ProjectPurpose;
use App\Enums\ShotStatus;
use App\Events\ShotDeleting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
     *  storyline_options: 'array',
     *  chosen_storyline: 'array',
     *  storyline: 'array',
     * }
     */
    protected function casts(): array
    {
        return [
            'status' => ShotStatus::class,
            'purpose_override' => ProjectPurpose::class,
            'aspect_ratio_override' => AspectRatio::class,
            'storyline_options' => 'array',
            'chosen_storyline' => 'array',
            'storyline' => 'array',
        ];
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
        return $this->duration ?? $this->project->default_duration;
    }
}
