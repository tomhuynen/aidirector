<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

use App\Enums\ProjectPurpose;

/**
 * What the director optimises for, per project purpose. Injected into every
 * director prompt so recommendations are judged in the project's own terms.
 *
 * Known gap: the staging, framing, check and video rules elsewhere are tuned
 * for e-learning (clear literal action, static camera, subdued backgrounds,
 * no text, silent clips, a takeaway per shot) and apply to every purpose.
 * Commercials need the opposite in many places: emotion over instruction,
 * camera movement and cuts, rich backgrounds, product and brand shots with
 * logos and on-screen text, and sound. Those rules should move behind the
 * project purpose, starting here.
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
}
