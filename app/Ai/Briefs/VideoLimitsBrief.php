<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

/**
 * The director rules: what the image and video models cannot do within one
 * shot, each with why it fails and what to do instead. The planner avoids these; the plan director
 * spots them in a story, tells the director in plain words why it will not
 * work, and proposes the alternative.
 */
class VideoLimitsBrief
{
    /**
     * @return list<array{risk: string, why: string, instead: string}>
     */
    public static function limits(): array
    {
        return [
            [
                'risk' => 'The place changes within the shot, such as driving through a gate to the area behind it, or walking into another room',
                'why' => 'every keyframe is drawn on one image of the place, so the model has to invent a whole new view and the change is too big',
                'instead' => 'end the shot on the moment before, such as the barrier opening, and show the new place in a second shot',
            ],
            [
                'risk' => 'The camera moves: it follows someone, pans, zooms or drives along',
                'why' => 'the camera stands still in every keyframe, drawn on the same image',
                'instead' => 'a cut to a second shot from another camera position',
            ],
            [
                'risk' => 'A big change in distance, such as someone walking from far away right up to the camera',
                'why' => 'their size and every detail have to be invented along the way',
                'instead' => 'start closer, or split it into a wide shot and a closer one',
            ],
            [
                'risk' => 'A small detail carries the message, such as a crack, a dial, a seal or what is on a badge',
                'why' => 'it is too small to read in a wide frame',
                'instead' => 'a close-up of that detail, or a close-up shot right after the scene',
            ],
            [
                'risk' => 'Hands work on something small in a wide frame, such as clipping, buckling or locking',
                'why' => 'hands and small objects come out unclear at that size',
                'instead' => 'a close-up of the hands and the object',
            ],
            [
                'risk' => 'The action is hidden, behind a counter, a vehicle, a back or another person',
                'why' => 'the viewer cannot see the point of the shot',
                'instead' => 'another camera position where the action is in plain view',
            ],
            [
                'risk' => 'Fast or physical motion: running, falling, a collision, liquid, smoke or fire',
                'why' => 'video models animate it unreliably and it looks wrong',
                'instead' => 'show the moment just before and the result, without the motion itself',
            ],
            [
                'risk' => 'A jump in time within the shot, such as "an hour later" or "afterwards"',
                'why' => 'one shot is one continuous movement of a few seconds',
                'instead' => 'a cut to a second shot',
            ],
            [
                'risk' => 'More than about three steps, or several people doing different things at the same time',
                'why' => 'it is too much for one short clip and the model mixes it up',
                'instead' => 'split it into shots, one action each',
            ],
            [
                'risk' => 'Text, numbers, screens or lettering carry the message',
                'why' => 'the models cannot write',
                'instead' => 'show it with shapes, colours and what people do',
            ],
            [
                'risk' => 'One person does two things with their hands at the same time, such as holding a phone to the ear while writing or ticking a list',
                'why' => 'the arms cross in front of the body and the image model draws them at strange angles or loses one',
                'instead' => 'one action per shot, such as the call in a scene and the ticking in a close-up of the hand; the voice-over can carry the rest',
            ],
            [
                'risk' => 'A hand reaches across the body or behind another person to the object',
                'why' => 'crossed arms and hidden shoulders come out wrong',
                'instead' => 'stage it so the hand on the side of the object does the action, with the object beside the person, and name hands by the side of the frame, never as the person\'s own left or right',
            ],
            [
                'risk' => 'The face matters but is seen side-on, under a helmet brim, or with something in front of it, such as a handset or a hand',
                'why' => 'the image model loses the eyes and the expression',
                'instead' => 'a three-quarter view towards the camera with nothing in front of the face, or leave the face out and show the hands',
            ],
            [
                'risk' => 'A small change counted step by step over several keyframes, such as one more tick, one more bolt or a rising level each time',
                'why' => 'the models cannot count or keep the rest exactly the same, and the video model cannot animate small strokes',
                'instead' => 'only before and after: nothing ticked, then all ticked; the voice-over names the steps',
            ],
            [
                'risk' => 'In a close-up, something is taken out of a holder that stays in view, such as a handset off its cradle or a tool off a wall',
                'why' => 'the image model keeps the old one in the holder as well and draws it twice',
                'instead' => 'frame the close-up so the holder is out of view, or show the taking in a scene, where the place keeps the holder right',
            ],
            [
                'risk' => 'Two people who look alike, such as the same helmet and similar clothes, while it matters who does what',
                'why' => 'the image model mixes them up and swaps their places or actions',
                'instead' => 'people who clearly differ, such as a hi-vis vest against a striped shirt, and each on their own side of the frame throughout the shot',
            ],
        ];
    }

    /**
     * The limits as a list for a prompt.
     */
    public static function brief(): string
    {
        return collect(self::limits())
            ->map(fn(array $limit) => "- {$limit['risk']}: fails because {$limit['why']}. Instead: {$limit['instead']}.")
            ->join("\n");
    }
}
