<?php

declare(strict_types=1);

namespace App\Support\Corrections;

use App\Enums\CorrectionSource;
use App\Jobs\ClassifyCorrection;
use App\Models\Keyframe;
use App\Models\Project;
use App\Models\Shot;
use Illuminate\Support\Facades\Config;

/**
 * Keeps a change by the director, or a finding of the keyframe check, and has
 * it labelled in the background.
 */
class RecordCorrection
{
    public static function record(Project $project, CorrectionSource $source, string $request, ?Shot $shot = null, ?Keyframe $keyframe = null, ?string $context = null): void
    {
        if (! Config::get('pipeline.rules.enabled') || trim($request) === '') {
            return;
        }

        $correction = $project->corrections()->create([
            'shot_id' => $shot?->id,
            'keyframe_id' => $keyframe?->id,
            'source' => $source,
            'request' => $request,
            'context' => $context,
        ]);

        ClassifyCorrection::dispatch($correction);
    }

    /**
     * What the keyframe was meant to show, as context for the classifier.
     */
    public static function keyframeContext(Keyframe $keyframe): string
    {
        return implode(' ', [
            "Takeaway of the shot: {$keyframe->shot->takeaway}.",
            "The keyframe should show: {$keyframe->description}",
        ]);
    }
}
