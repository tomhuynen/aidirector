<?php

declare(strict_types=1);

namespace App\Support\Shots;

use App\Ai\Agents\PlanPlaceAligner;
use App\Models\Shot;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Before keyframe 1 is drawn on the chosen place, the plan is matched to it
 * once: left and right, and where people stand and go, follow where the fixed
 * things are in the picture. The chat says what changed.
 */
class PlanToPlace
{
    /** On the storyline: the place the keyframes were last matched to. */
    public const ALIGNED = 'aligned_place';

    public function align(Shot $shot, Media $place): void
    {
        $planned = $shot->storylineKeyframes();

        if ($planned === [] || (int) ($shot->storyline[self::ALIGNED] ?? 0) === $place->id) {
            return;
        }

        $aligner = new PlanPlaceAligner($shot);

        try {
            /** @var StructuredAgentResponse $response */
            $response = $aligner->prompt($aligner->promptFor(), attachments: [ImageFile::fromStorage($place->getPathRelativeToRoot(), $place->disk)], provider: 'openrouter', model: (string) Config::get('pipeline.models.text'));
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $answer = $response->toArray();
        $aligned = array_values(array_filter((array) ($answer['keyframes'] ?? []), is_array(...)));
        $note = trim((string) ($answer['note'] ?? ''));

        // A plan with keyframes added or lost is not this plan: it stays as it was.
        $fits = count($aligned) === count($planned);

        $shot->updateStoredJson('storyline', fn(?array $storyline) => [
            ...($storyline ?? []),
            self::ALIGNED => $place->id,
            ...($fits ? ['keyframes' => array_map(fn(array $keyframe, int $index) => [
                ...$keyframe,
                'description' => trim((string) ($aligned[$index]['description'] ?? '')) ?: $keyframe['description'],
                'spatial' => trim((string) ($aligned[$index]['spatial'] ?? '')),
            ], array_values($storyline['keyframes'] ?? []), array_keys(array_values($storyline['keyframes'] ?? [])))] : []),
        ]);

        if (! $fits || $note === '') {
            return;
        }

        foreach ($shot->keyframes()->get() as $keyframe) {
            $plan = $shot->storylineKeyframes()[$keyframe->position - 1] ?? null;

            if ($plan !== null) {
                $keyframe->forceFill(['description' => $plan['description'], 'spatial' => filled($plan['spatial'] ?? null) ? $plan['spatial'] : null])->save();
            }
        }

        $shot->say(__('I matched the plan to the chosen place: :note', ['note' => $note]));
    }
}
