<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

use App\Models\Shot;

final class RenderCapabilities
{
    public static function brief(): string
    {
        return Shot::rendersSequences() ? self::sequence() : self::keyframePairs();
    }

    public static function sequence(): string
    {
        $limits = collect(self::sequenceLimits())
            ->map(fn(array $limit) => "- {$limit['risk']}: fails because {$limit['why']}. Instead: {$limit['instead']}.")
            ->join("\n");

        return <<<BRIEF
            How the shots are rendered: the whole sequence is one video clip.
            - Drawn: one start image per shot, from the start you stage, in the place of the shot. No end image is drawn.
            - Sent to the video model: the start image of the first shot as the opening image; the start images of the other shots and the pictures of the people as references; and one line per shot with its framing and its action. No end frame is sent.
            - The video model plays the actions, cuts between the shots and times them itself. The seconds of a shot are a guide for its length, not an exact timing.
            - The camera stands still in every shot.
            - A shot that plays wrong can be rendered again on its own, between its own first and last frame as the clip shows them, with the same action line.

            So write:
            - start: the image of the moment before the action of the shot, staged so that the action can begin from it.
            - action: what plays after the start image, every movement in order. It is the only text the video model reads about the shot, so it holds every event that the shot depicts.
            - end: the expected state after the action, for continuity with the next shot and for the review only. No image is drawn from it and the video model does not get it, so nothing happens through the end that the action does not do.
            - The start of each shot after the first agrees with the expected end of the shot before: the same positions, held objects, hands, facing and gaze, seen from the new camera. A shot may end on a completed action.

            What a shot cannot do in this mode, each with why and what to do instead:
            {$limits}
            BRIEF;
    }

    public static function keyframePairs(): string
    {
        $limits = VideoLimitsBrief::brief();

        return <<<BRIEF
            How the shots are rendered: every shot is its own clip between two drawn keyframes.
            - Drawn: a start keyframe and an end keyframe per shot, from the start and the end you stage. The end keyframe is drawn on the start keyframe, in the same place from the same camera.
            - The video model plays the action between the two keyframes. The camera stands still.
            - Every shot after the first continues from the end frame of the shot before, as a match on action: its start frame is that end frame seen from the new camera position, with the same place, light, positions, poses and hand positions; only the framing changes. An insert shows its one thing in the place of the shot before it, and the shot after the insert continues from the last shot with people.
            - So an end frame that another shot continues from is mid-action: a hand halfway to the handle, a foot still in the air, a head still turning, never a pose at rest; the next shot picks the movement up there and the video plays it on.
            - Where a person goes out of the frame or something changes hands, place or state, the start frame has it before and the end frame after; nothing appears or disappears between them.

            So write: start and end as the two keyframes that are drawn, and action as the one act the video plays between them.

            What a shot cannot do in this mode, each with why and what to do instead:
            {$limits}
            BRIEF;
    }

    /**
     * @return list<array{risk: string, why: string, instead: string}>
     */
    private static function sequenceLimits(): array
    {
        return [
            [
                'risk' => 'The camera moves: it follows someone, pans, zooms or drives along',
                'why' => 'every shot is one start image from a fixed camera, and the camera stands still',
                'instead' => 'a cut to a new shot from another camera position',
            ],
            [
                'risk' => 'The place changes within a shot, such as walking through a gate into the area behind it, or into another room',
                'why' => 'the start image shows one view of one place, so the model has to invent the new place from the action sentence',
                'instead' => 'end the shot at the gate or the doorway, and show the new place in a new shot with its own start image',
            ],
            [
                'risk' => 'A big change in distance towards the camera, such as someone walking from far away right up to the camera',
                'why' => 'the start image shows them small, so the model has to invent their size and every detail along the way',
                'instead' => 'start closer, or split it into a wide shot and a closer shot with its own start image',
            ],
            [
                'risk' => 'A tiny detail carries the message in a wide frame, such as a dial, a seal, what is on a badge, or hands that clip or buckle something',
                'why' => 'the start image cannot show it clearly at that size, and the model cannot make readable what the image does not show',
                'instead' => 'a close-up or an insert of that detail, with its own start image',
            ],
            [
                'risk' => 'The action is hidden, behind a counter, a vehicle, a back or another person',
                'why' => 'the model plays what the start image shows, so the viewer cannot see the point of the shot',
                'instead' => 'a camera position where the action is in plain view in the start image',
            ],
            [
                'risk' => 'Fast or physical motion: running, falling, a collision, liquid, smoke or fire',
                'why' => 'the model animates it unreliably from one image and one sentence, and it looks wrong',
                'instead' => 'show the moment before and the result, in separate shots, without the motion itself',
            ],
            [
                'risk' => 'A jump in time within a shot, such as "an hour later" or "afterwards"',
                'why' => 'a shot plays as one continuous movement from its start image',
                'instead' => 'a cut to a new shot',
            ],
            [
                'risk' => 'Several changes at once in one shot, or several people doing different things at the same time',
                'why' => 'one action sentence for a few seconds cannot keep them apart, so the model mixes them up',
                'instead' => 'one main change per shot, with the movements of one person in order; split the rest into shots',
            ],
            [
                'risk' => 'Something appears, disappears or changes hands without being seen to do so',
                'why' => 'the model starts from what the start image shows and cannot invent where a thing comes from',
                'instead' => 'have it in the start image, or let the action show it come in or change hands',
            ],
            [
                'risk' => 'Text, numbers, screens or lettering carry the message',
                'why' => 'the image and the video models cannot write',
                'instead' => 'show it with shapes, colours, pictograms and what people do',
            ],
        ];
    }
}
