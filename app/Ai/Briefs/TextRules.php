<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

use App\Models\Project;

/**
 * The rule about text that every image prompt carries: nothing in a film
 * carries letters, numbers or logos, since the image models write gibberish
 * and the film is understood without words. Signs show a pictogram only.
 * The one exception is a logo from the project's branding, and that only
 * drawn from its uploaded picture: a logo the model was not shown never
 * appears, and the planners only ever ask for a logo the branding has.
 */
final class TextRules
{
    public const string NO_TEXT = 'No text anywhere in the image: no letters, words, numbers, labels, logos, captions or watermarks, not on signs, buildings, vehicles, clothing, helmets, cards, badges, permits, papers or screens, and not even words from the description. A sign shows only a simple pictogram and plain colours.';

    /**
     * For an image drawn from pictures of the cast and sets: without branding
     * no text at all, with it the logos those pictures carry stay as they are.
     */
    public static function forImages(Project $project): string
    {
        $brands = $project->brandNames();

        if ($brands === []) {
            return self::NO_TEXT;
        }

        $names = implode(' and ', $brands);

        return 'No text anywhere in the image: no letters, words, numbers, labels, captions or watermarks, not on signs, buildings, vehicles, clothing, helmets, cards, badges, permits, papers or screens, and not even words from the description. A sign shows only a simple pictogram and plain colours. '
            . "The only exception is the {$names} logo where it is on the people, places and things in the attached pictures: keep it exactly as it is there. Never add a logo anywhere else, and never spell or redraw one yourself.";
    }

    /**
     * For a change to an image: without branding no text at all, with it the
     * logos already in the image stay as they are.
     */
    public static function forEdits(Project $project): string
    {
        $brands = $project->brandNames();

        if ($brands === []) {
            return self::NO_TEXT;
        }

        $names = implode(' and ', $brands);

        return 'No text anywhere in the image: no letters, words, numbers, labels, captions or watermarks, not on signs, buildings, vehicles, clothing, helmets, cards, badges, permits, papers or screens, and not even words from the description. A sign shows only a simple pictogram and plain colours. '
            . "The only exception is the {$names} logo where it already is in the image: keep it exactly as it is there. Never add a logo anywhere else, and never spell or redraw one yourself.";
    }

    /**
     * For an element drawn with logos attached, named by their brands.
     *
     * @param  list<string>  $brands
     */
    public static function withLogos(array $brands): string
    {
        $names = implode(' and ', $brands);

        return "The only text allowed is the {$names} logo from the attached logo image: draw it exactly like that image, with the same letters, shapes and colours, and only where the description puts it. Never spell, redraw or change it yourself. Write nothing else: no other letters, words, slogans, numbers, labels or brand names, not even words from the description. Other markings are plain shapes and colours.";
    }

    /**
     * For the planners, who write the descriptions images are drawn from.
     */
    public static function forPlanning(Project $project): string
    {
        $brands = $project->brandNames();

        if ($brands === []) {
            return 'Never put text on anything: no words, labels, numbers, slogans, logos or brand names; markings are plain shapes and colours, and a sign shows a simple pictogram. The project has no logo, so nothing carries one.';
        }

        $list = implode(', ', $brands);

        return "Never put text on anything: no words, labels, numbers or slogans; markings are plain shapes and colours, and a sign shows a simple pictogram. The only exception is the branding: the logos of {$list}, which may be on what carries the branding, such as a work vest, a helmet, a vehicle or a building sign. Say so by the brand's exact name, such as \"the {$brands[0]} logo on the back of the vest\", and never spell out words of a logo or any other brand.";
    }
}
