<?php

declare(strict_types=1);

namespace App\Ai\Prompts;

use App\Enums\ShotSize;
use App\Models\Shot;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The prompt for a video model that renders a whole sequence in one clip:
 * the cast by their reference pictures, then the shots in order, each with
 * its framing, the drawn keyframe it looks like and its act. The model cuts
 * between the shots and times them itself.
 */
class SequencePrompt
{
    /** The longest prompt the model takes. */
    private const int LIMIT = 2500;

    /**
     * @param  Collection<int, Shot>  $shots  the shots of the sequence, in order
     * @param  array<int, int>  $frames  per index of a shot after the first, the number of the reference image of its keyframe
     * @param  array<string, int>  $cast  per name of the cast, the number of the reference image of their picture
     */
    public static function compose(Collection $shots, array $frames, array $cast): string
    {
        $lines = [];

        foreach ($cast as $name => $image) {
            $lines[] = "{$name} is <<<image_{$image}>>>: exactly this look in every shot.";
        }

        $lines[] = 'Keep the exact render style, colours and place of the first frame. People move at real, natural speed. Nobody talks or looks at the camera. No text and no music.';

        foreach ($shots->values() as $index => $shot) {
            $framing = self::framing($shot->kindOrScene()->size());
            $look = isset($frames[$index]) ? " framed like <<<image_{$frames[$index]}>>>" : ($index === 0 ? ', starting on the first frame' : '');
            $lines[] = 'Shot ' . ($index + 1) . ": {$framing}{$look}: " . self::act($shot);
        }

        return Str::limit(implode("\n", $lines), self::LIMIT, '');
    }

    private static function framing(ShotSize $size): string
    {
        return match ($size) {
            ShotSize::FULL => 'wide shot, fixed camera',
            ShotSize::MEDIUM => 'medium shot from closer',
            ShotSize::CLOSE_UP => 'close-up on the hands and the object',
            ShotSize::INSERT => 'insert of the one thing',
        };
    }

    /**
     * What happens in a shot, in the director's words.
     */
    private static function act(Shot $shot): string
    {
        return Str::limit(trim((string) ($shot->idea ?: ($shot->chosen_storyline['storyline'] ?? $shot->title))), 400);
    }
}
