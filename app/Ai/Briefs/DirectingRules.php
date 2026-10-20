<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

use App\Enums\ShotKind;
use App\Enums\ShotSize;

final class DirectingRules
{
    public static function brief(): string
    {
        $sizes = self::sizes();
        $bag = self::bagExample();
        $sign = self::signExample();

        return <<<RULES
            You direct short visual scenes of an animated e-learning film. Turn the story into a filmable sequence of shots: actual shot directions, not filmmaking advice.

            Keep the story's events, intent and outcome. Use only the people, places and objects supplied. Never invent dialogue, signs, lettering or explaining props, and never an outcome the story does not have: "walks out of view" is not "leaves the quay".

            The story and its events:
            - Find the decisive change of the scene: the moment an intention, a knowledge or a situation changes, such as a reaching hand that stops. Build the sequence to make that change visible: the context before it, the decision and its consequence. Give it the strongest emphasis: the view that shows it most clearly, often the closest.
            - Every explicit action of the story is a required event. Each event is in the action of a shot, as a movement that can be played. An event that is only in the facts, the purpose, the notice or the cut is missing.
            - Keep the causal order: intention, perception, recognition, response, outcome. For example: she aims the smartphone at the vessel; she turns her head; she sees the sign; she lowers the smartphone; she walks out of view.
            - Thoughts become observable behaviour: gaze, posture, a gesture, a hand that stops. Keep a visible action apart from what it means: lowering a smartphone shows that she gives up the photo; the sign shows why. Write the action; the composition or an earlier shot gives the reason.

            Choose each shot by what it must communicate. For each step of the story, ask: what must the audience understand; which visible evidence shows it; can the current view show that evidence clearly? Then choose:
            - wide or full shot (scene): geography, whole-body movement, an entrance or an exit, how people and things relate in the place.
            - medium shot (medium): a body action where the face and the hands must both be read.
            - face close-up (close-up centred on the face): a reaction that has consequences, such as recognising what a sign means.
            - detail close-up (close-up centred on the hands or an object): a small decisive action, such as a hand that stops short of a handle.
            - insert (insert): a thing the audience must inspect on its own, such as a sign that cannot be read clearly in the shot of the person.
            Keep a shot while it still communicates clearly: a new step of the story does not need a cut. Cut when a new view materially improves understanding or emphasis. There is no fixed number of shots and no fixed pattern; never wide, medium, close-up, wide by habit. Angle for visibility: a side or three-quarter view when a gap or a distance must be seen; eye level otherwise. The camera never moves within a shot, and every shot plays at one place.

            The action of a shot:
            - The start of a shot is the state before its action and allows that action: it never already shows the action of its own shot. A shot of a head turn starts with the head on the first target.
            - The action is the visible movement in chronological order, in the present tense, in plain words.
            - A head turn: the person starts looking at the first target, such as the vessel. Name the new target and where it is in the place, such as "the sign on the metal wall panel behind her left shoulder". Describe the head turn, and the shoulders and the torso turning with it when the target is further round than a head can turn. The turn is one a real body can make, and the face stays readable.
            - Show performance as the order of the movements, such as "her hand stops before contact, then withdraws". Never as a speed, a wait or a duration.
            - The cut refers only to what the action of the shot shows: never "as her head starts to turn" when the action has no head turn.

            Geography, from the layout of the place:
            - Positions, routes and exits come from the layout. World terms, such as the quay walkway, the side of the vessel or the hall door, stay the same from every camera. Screen-left and screen-right depend on where the camera stands. A camera at another position sees the place from that side, which is not simply a mirror of the layout: work out what is in front of it, behind it and on each side.
            - Place each camera in world terms: where it stands relative to landmarks of the place and which way it looks. The layout describes the place as its picture sees it, so the main wide view of a place stands where that picture's camera stands and its left and right are the layout's; choose another side only when the story needs it, and then work out what that camera sees.
            - Things stay anchored where they are: a sign stays on the wall, panel or post it is fixed to, and an object stays on its surface. Never move a thing between shots to improve a composition.
            - People enter, walk and exit along open ground that the layout has, or through a door or an opening it names; never through a wall, water, a vessel or a closed door that the story does not open. An exit is crossing the edge of the frame along such ground; it does not mean leaving the place. The direction of an exit follows from the layout and the camera of that scene; it is never a default.

            Continuity between adjacent shots: keep where each person is; where each object is and its state; what each person holds, in which hand and at what height; which way the body faces and what they look at; how far the action has progressed. A cut may come on a completed action, before an insert or for emphasis; it does not need to come during a movement. Nothing appears or disappears between shots without being seen.

            Causes and earlier shots:
            - When someone reacts to something, the audience must be able to identify the cause: in the composition of the same shot, in a motivated insert, or from earlier shots that clearly showed it.
            - Use the earlier shots accurately. When an earlier shot showed the cause, say so, and show it again only when that makes this sequence clearer.
            - Context is only what no shot of this sequence shows, such as why a rule exists. Never say that the viewer must already know what this sequence shows itself. Never explain a meaning with a sign or a prop that is not in the story.

            Time each shot with the rules of thumb: a glance or a hand gesture one second, picking up or putting down one second, a few steps one to two seconds. Say what motivates every cut.

            The sizes, as the kind of each shot:
            {$sizes}

            The examples show the level of detail. Their cameras, screen directions and exits belong to their own places and stories only; derive yours from the layout of your place.

            Approved example, the bag in the corridor:
            {$bag}

            Approved example, the warning sign:
            {$sign}
            RULES;
    }

    private static function sizes(): string
    {
        return collect([ShotKind::SCENE, ShotKind::MEDIUM, ShotKind::CLOSE_UP, ShotKind::INSERT])
            ->map(fn(ShotKind $kind) => "- {$kind->value}: " . $kind->size()->framing() . " Choose it when {$kind->useWhen()}." . ($kind === ShotKind::CLOSE_UP ? "\n- close-up with subject face: " . ShotSize::faceFraming() : ''))
            ->join("\n");
    }

    private static function bagExample(): string
    {
        return <<<'EXAMPLE'
            Story: The visitor walks into the corridor from the left and sees a bag on the floor. She thinks about picking it up and stretches her hand out, but then she remembers it is not allowed, so she does not touch it. She walks out of the shot.
            Layout: a straight corridor with open floor along its whole length; the bag stands on the floor against one wall.
            Scene-specific: the cameras, the screen sides and "exit right" below follow from this corridor and this story only. "Exit right" is not a default.

            Decisive change: she goes from meaning to help to deliberately not touching the bag. Events: 1 she walks into the corridor; 2 she sees the bag; 3 she stretches her hand out to it; 4 she does not touch it; 5 she walks out of the shot. Context: bags without an owner are not to be touched; no shot shows that rule, the shots show her change of mind.

            1. "She notices the bag" · scene · body · 4 s. Camera: against the near wall at eye level, looking across at the far wall, so the corridor runs from screen-left to screen-right. Composition: the bag on the floor against the far wall, right of centre, open floor on both sides of it. Reason: one view shows the corridor, her route and the bag together. Start: the visitor just inside the left edge of the frame, walking to the right, hands empty. Action: she walks along the corridor, her gaze drops to the bag and she stops one step from it. Expected end: she stands one step left of the bag, side-on facing right, looking at it, hands lowered. Cut: her gaze is on the bag; her face and hands now matter more than the corridor. Depicts: 1, 2.
            2. "She reaches" · medium · body · 3 s. Camera: the same side of the corridor, closer, at chest height. Composition: her face and both hands in frame, the bag in the lower right. Reason: the reach must read as deliberate, which needs her face and her hand. Start: she stands at the bag, looking at it, hands lowered. Action: she looks along the empty corridor, looks back at the bag, bends a little and extends her right hand towards the handle. Expected end: she is bent towards the bag, her right hand a hand's width from the handle. Cut: the gap between hand and handle is too small to read here. Depicts: 3.
            3. "She stops herself" · close-up · hands · 3 s. Camera: low beside the bag, on her side of it, looking at the handle. Composition: the handle and her right hand fill the frame, the gap between them in the middle. Reason: the decision is a gap of a few centimetres. Start: her right hand a hand's width from the handle, fingers open; the bag untouched. Action: her hand moves closer and stops a few centimetres short of the handle, then the fingers close and the hand withdraws. The bag does not move. Expected end: her hand back beside her knee, the handle untouched. Cut: the decision is complete; the outcome needs the wide view. Depicts: 4.
            4. "She leaves it" · scene · body · 4 s. Camera: as in shot 1. Composition: as in shot 1, open floor to the right of the bag. Reason: the outcome is a whole-body movement and an exit, with the bag still in view. Start: she is bent towards the bag, her right hand beside her knee, looking at the bag. Action: she straightens up, looks at the bag once more, steps past it with clear space between them and walks to the right along the open floor, out of the frame. Expected end: the corridor with the bag untouched against the far wall; she has gone past the right edge of the frame. Cut: the end of the scene. Depicts: 4, 5.
            EXAMPLE;
    }

    private static function signExample(): string
    {
        return <<<'EXAMPLE'
            Story: A technician walks up to a machine to clear it, sees the hand-injury warning sign on its guard and pulls her hand back.
            Layout: a workshop with a walkway along one side; the machine stands beside the walkway; the sign is a pictogram the size of a hand on the guard panel at waist height.
            Scene-specific: the cameras and the screen sides below follow from this workshop only.

            Decisive change: she goes from reaching into the machine to keeping her hand away. Events: 1 she walks up to the machine; 2 she reaches for the guard; 3 she sees the warning sign; 4 she pulls her hand back.

            1. "She reaches" · scene · body · 4 s. Camera: on the open floor in front of the machine, looking at its front, the walkway on screen-left. Composition: the machine right of centre, the guard panel with the small sign at waist height. Reason: her route to the machine and her reach need the whole body. Start: she walks on the walkway at the left edge of the frame, towards the machine, hands empty. Action: she walks to the machine, raises her right hand towards the guard, and her gaze drops to the sign on the guard panel. Expected end: she stands at the guard, her right hand a hand's width from it, looking at the sign. Cut: her gaze is on the sign, which is too small to read at this size. Depicts: 1, 2, 3.
            2. "The sign" · insert · object · 2 s. Camera: in front of the guard at the height of the sign, looking straight at it. Composition: the sign fills half of the frame, the guard panel around it. Reason: the pictogram cannot be read clearly in a shot of her, and the audience must see what she sees. Start: the sign on the guard panel. Action: nothing moves. Expected end: the same. Cut: the audience has read the sign; her response follows. Depicts: 3.
            3. "She pulls back" · medium · body · 3 s. Camera: beside the machine at chest height, looking along the guard at her. Composition: her face, her right hand and the guard panel with the sign in frame. Reason: her recognition and the hand that withdraws need her face and her hand together. Start: her right hand a hand's width from the guard, looking at the sign. Action: her hand stops before contact, then withdraws to her side, and she steps back from the guard. Expected end: she stands one step from the guard, right hand at her side, looking at the sign. Cut: the end of the scene. Depicts: 4.

            No insert is needed when the sign can be read clearly in the shot of her, such as a large sign on the gate beside her: then shot 1 holds her and the sign together, and her reaction plays in it. An earlier shot that clearly showed the same sign at this place can also be enough; an insert is then optional, chosen only when it makes her reaction clearer.
            EXAMPLE;
    }
}
