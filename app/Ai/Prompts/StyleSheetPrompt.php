<?php

declare(strict_types=1);

namespace App\Ai\Prompts;

use App\Models\Project;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The image prompt for one style sheet: a 2x2 grid showing the project's
 * subjects in a candidate style. The model composes the panels itself from
 * the attached content photos, so nothing is pasted together on our side.
 */
class StyleSheetPrompt
{
    public const PANELS = 4;

    /**
     * @param  array{name: string, look: string, medium: string, mood: string, palette: string, lighting: string}  $style
     * @param  Collection<int, Media>  $photos
     */
    public static function for(array $style, Collection $photos): string
    {
        $panels = min(self::PANELS, max(1, $photos->count()));
        $subjects = $photos->values()
            ->map(fn(Media $media, int $index) => ($index + 1) . '. ' . ($media->getCustomProperty(Project::CAPTION) ?? 'the subject in reference photo ' . ($index + 1)))
            ->join("\n");
        $layout = match ($panels) {
            1 => 'a single full-frame image',
            2 => 'two panels side by side',
            3 => 'three panels in a row',
            default => 'a 2x2 grid of four equal panels',
        };

        return <<<PROMPT
            Create one image laid out as {$layout}, with thin white gutters between panels and nothing else in the frame. Each panel shows one of the attached reference photos, in this order:
            {$subjects}

            Render every panel in exactly this style:
            Style: {$style['name']}
            Look: {$style['look']}
            Medium: {$style['medium']}
            Mood: {$style['mood']}
            Palette: {$style['palette']}
            Lighting: {$style['lighting']}

            Keep each subject faithful to its reference photo: same shapes, proportions, markings and colours of the object, only the rendering style changes. Keep the framing of each photo. No text, no labels, no logos, no watermark, no borders other than the gutters.
            PROMPT;
    }
}
