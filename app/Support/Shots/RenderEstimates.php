<?php

declare(strict_types=1);

namespace App\Support\Shots;

use App\Models\Generation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * How long a keyframe image, an element picture and a video usually take,
 * from the latest generations, so the editor can show progress instead of a
 * spinner. The median, so a few slow runs do not stretch every bar.
 */
class RenderEstimates
{
    /** Seconds assumed while there are no generations to learn from yet. */
    public const int KEYFRAME_DEFAULT = 60;

    public const int ELEMENT_DEFAULT = 15;

    public const int ELEMENT_EDIT_DEFAULT = 90;

    public const int VIDEO_DEFAULT = 180;

    /** How many of the latest generations the median is taken over. */
    private const int SAMPLE = 30;

    /**
     * A keyframe: drawing the image plus checking it.
     */
    public function keyframeSeconds(): int
    {
        return Cache::remember('render-estimates:keyframe', now()->addMinutes(10), function (): int {
            $image = $this->medianSeconds('image', 'keyframe');

            return $image === null ? self::KEYFRAME_DEFAULT : (int) round($image + ($this->medianSeconds('text', 'keyframe') ?? 0));
        });
    }

    /**
     * A video, from the moment it is submitted until it is downloaded.
     */
    public function videoSeconds(): int
    {
        return Cache::remember('render-estimates:video', now()->addMinutes(10), fn(): int => (int) round($this->medianSeconds('video', 'shot') ?? self::VIDEO_DEFAULT));
    }

    /**
     * The picture of a person, place or object in the cast and sets: drawn
     * from its description with the image model, or changed with the edit
     * model, which takes much longer.
     *
     * @return array{draw: int, edit: int}
     */
    public function elementSeconds(): array
    {
        return Cache::remember('render-estimates:element', now()->addMinutes(10), fn(): array => [
            'draw' => (int) round($this->medianSeconds('image', 'element', (string) Config::get('pipeline.models.image')) ?? self::ELEMENT_DEFAULT),
            'edit' => (int) round($this->medianSeconds('image', 'element', (string) Config::get('pipeline.models.image_edit')) ?? self::ELEMENT_EDIT_DEFAULT),
        ]);
    }

    private function medianSeconds(string $kind, string $type, ?string $model = null): ?float
    {
        $durations = Generation::query()
            ->where('kind', $kind)
            ->where('generatable_type', $type)
            ->when($model !== null, fn($query) => $query->where('model', $model))
            ->whereNotNull('duration_ms')
            ->whereNull('error')
            ->latest('id')
            ->limit(self::SAMPLE)
            ->pluck('duration_ms');

        return $durations->isEmpty() ? null : (float) $durations->median() / 1000;
    }
}
