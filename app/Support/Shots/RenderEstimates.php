<?php

declare(strict_types=1);

namespace App\Support\Shots;

use App\Models\Generation;
use Illuminate\Support\Facades\Cache;

/**
 * How long a keyframe image and a video usually take,
 * from the latest generations, so the editor can show progress instead of a
 * spinner. The median, so a few slow runs do not stretch every bar.
 */
class RenderEstimates
{
    /** Seconds assumed while there are no generations to learn from yet. */
    public const int KEYFRAME_DEFAULT = 60;

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
