<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

/**
 * How a keyframe is staged in its fields: the short line the director
 * reads, the spot, and per person of the cast where they stand, which way
 * they face, what they look at, what they hold and what they do. The
 * description the image model and the check get is built from these fields
 * in code, so every fact belongs in exactly one field.
 */
final class StagingRules
{
    public static function rules(): string
    {
        $hands = HandRules::RECEIVING . ' ' . HandRules::AT_THE_INSTANT;
        $natural = BodyRules::NATURAL_POSE;
        $walk = BodyRules::WALK_DISTANCE;

        return <<<RULES
            - Summary: what happens in this keyframe in one short plain line of at most twelve words, who does what, such as "The guard checks the permit at the barrier". The director reads it; the fields below are what the image model gets.
            - Place: the spot in one or two sentences, with the same wording in every keyframe: where it is, the context objects on it and where they are, any object that is not in the cast and sets, and the state of the key objects nobody holds, such as "the barrier arm is down". When someone picks something up or takes it out, say here that the spot it came from is now empty, such as "the cradle on the wall is empty". Never the people.
            - Cast: one entry per person of the cast and sets in the keyframe, by their exact name. Never describe how they look, such as age, build, hair, clothing or colours; their pictures decide that. Leave a field empty when it does not apply.
              - stands: left, centre or right in the frame; empty when it does not matter, such as the hands in a close-up.
              - faces: which way the body faces from the camera's point of view: camera; camera-left or camera-right (turned three-quarters toward the camera, facing that side of the frame); left or right (side-on, facing that side of the frame); away-left or away-right (turned three-quarters away from the camera, into the frame on that side); or away (back to the camera). The body faces what their hands work on or where they go; with nothing to do or go to, it faces what they look at. In depth: what lies behind them, deeper in the frame or in the background, means away-left, away-right or away; what is beside them in the frame means left or right; what is between them and the camera means camera, camera-left or camera-right.
              - looks_at: what they look at, with "the", such as "the Damen host", "the naval vessel" or "the camera". The head turns to it from the way the body faces; the body turns with it only when it lies further round than a head can turn. Something out of view is at the edge of the frame on the side where it is in the place; looks_at then names that edge, such as "the left edge of the frame".
              - holds: what they hold, with "the", such as "the permit"; empty when the hands are empty. hand: the hand that holds it, left, right or both. Keep it in that hand in every keyframe unless the story moves it.
              - doing: their pose, gesture and expression as a short verb phrase in the present tense, without their name, such as "raises it to chest height, aimed at the vessel". {$hands}
            - Pose: {$natural}
            - Distance: {$walk}
            - Never write "forward", "looking forward", "ahead" or "angled into": the image model then draws them facing the viewer. Concrete nouns, no style words: the visual style is added separately.
            - Every fact goes in one field only: the spatial fact is never repeated in the place or the cast, and the place never says what a person does.
            RULES;
    }
}
