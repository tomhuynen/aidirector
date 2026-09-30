<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

use App\Enums\ProjectPurpose;

/**
 * What the director optimises for, per project purpose. Injected into every
 * director prompt so recommendations are judged in the project's own terms.
 */
class PurposeBrief
{
    public static function for(ProjectPurpose $purpose): string
    {
        return match ($purpose) {
            ProjectPurpose::E_LEARNING => <<<'BRIEF'
                Purpose: e-learning. Clarity comes first. One idea per shot, in the order a learner needs it.
                Every keyframe must be a clearly readable state; never rely on speed or spectacle.
                Prefer a stable point of view, eye level, medium framing. Keep the number of keyframes low and each one unambiguous.
                Ask of every keyframe: does the viewer understand what to do or what is true?
                BRIEF,
            ProjectPurpose::EXPLAINER => <<<'BRIEF'
                Purpose: explainer. Understanding comes first, with a little more energy than pure instruction.
                Build cause and effect across keyframes; each one should answer "and then what?".
                Clear framing, occasional emphasis through scale or proximity. Avoid clutter.
                BRIEF,
            ProjectPurpose::COMMERCIAL => <<<'BRIEF'
                Purpose: commercial. Emotion and attention come first.
                Keyframes may be denser and more dynamic; favour striking moments over complete explanation.
                Ask of every keyframe: does this make the viewer feel something about the subject?
                BRIEF,
            ProjectPurpose::DOCUMENTARY => <<<'BRIEF'
                Purpose: documentary. Authenticity comes first.
                Observational moments, natural staging, honest light. Nothing that feels acted or advertised.
                BRIEF,
            ProjectPurpose::SOCIAL_SHORT => <<<'BRIEF'
                Purpose: social short. Hook first, fast.
                The first keyframe must already be interesting. Bold framing, quick progression, a clear payoff at the end.
                Assume a vertical phone screen unless told otherwise.
                BRIEF,
            ProjectPurpose::NARRATIVE => <<<'BRIEF'
                Purpose: narrative. Story comes first.
                Keyframes follow motivation: what the character wants, what gets in the way, what changes.
                Build tension across the sequence and give it a resolution.
                BRIEF,
        };
    }

    /**
     * Angles a shot can take on the same idea, per purpose. Offered to the
     * storyline writer as inspiration; the storylines must differ as scenes,
     * an angle alone does not make one.
     */
    public static function storylineAngles(ProjectPurpose $purpose): string
    {
        return match ($purpose) {
            ProjectPurpose::E_LEARNING => <<<'ANGLES'
                - Correct behaviour modelled: the subject does the right thing from the start. The learner sees what good looks like, with no mistake in the shot.
                - Mistake and correction: the subject starts wrong, notices the cue, and puts it right. The learner sees how to recognise and recover from the error.
                - Consequence first: the shot shows what goes wrong when the rule is ignored, then the correct action. The learner sees why the rule exists.
                - Cue spotting: the shot centres on the cue (a sign, a label, a warning) before any action is taken. The learner practises noticing the trigger.
                ANGLES,
            ProjectPurpose::EXPLAINER => <<<'ANGLES'
                - Cause and effect: one action leads visibly to its result.
                - Before and after: the same scene in its old state and its improved state.
                - Step by step: the process shown as a sequence of small, clear steps.
                - Problem then solution: the difficulty is shown first, the answer resolves it.
                ANGLES,
            ProjectPurpose::COMMERCIAL => <<<'ANGLES'
                - Desire first: open on the most appealing moment, then show how it came about.
                - Problem then relief: a frustration, then the product or behaviour that removes it.
                - Social proof: others react to the subject, making the benefit visible.
                - Reveal at the end: withhold the key object until the final moment.
                ANGLES,
            ProjectPurpose::DOCUMENTARY => <<<'ANGLES'
                - Observed routine: the action as it would naturally happen, without staging.
                - One telling detail: the story told through a single object or gesture.
                - Change over time: the same place or person at two moments.
                - A bystander's view: the moment seen through someone who witnesses it.
                ANGLES,
            ProjectPurpose::SOCIAL_SHORT => <<<'ANGLES'
                - Hook with the mistake: open mid-error, resolve fast.
                - Hook with the consequence: open on the result, then rewind to the cause.
                - Unexpected twist: the obvious outcome is replaced by a surprising one.
                - Satisfying payoff: build to one clean, repeatable resolving moment.
                ANGLES,
            ProjectPurpose::NARRATIVE => <<<'ANGLES'
                - Want and obstacle: the subject wants something, something stands in the way.
                - Temptation and choice: the subject is tempted and visibly decides.
                - Escalation: the situation gets worse before it resolves.
                - Change of heart: the subject starts with one intention and ends with another.
                ANGLES,
        };
    }
}
