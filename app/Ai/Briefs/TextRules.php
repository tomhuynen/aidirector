<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

use App\Models\Project;

/**
 * What text an image may show: none, except a logo from the project's
 * branding, and that only drawn from its uploaded picture. Image models
 * invent and misspell brand names, so a logo they were not shown never
 * appears; the planners only ever ask for a logo the branding has.
 */
class TextRules
{
    /**
     * For an image drawn or changed without a logo attached.
     */
    public const NO_TEXT = 'Never write any text on it or in the image: no letters, words, slogans, numbers, labels, brand names or logos, not even words from the description. Markings are plain shapes and colours.';

    /**
     * For an image drawn or changed with logos attached, named by their brands.
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
            return 'Never put text on anything: no words, labels, numbers, slogans, logos or brand names; markings are plain shapes and colours. The project has no logo, so nothing carries one.';
        }

        $list = implode(', ', $brands);

        return "Never put text on anything: no words, labels, numbers or slogans; markings are plain shapes and colours. The only exception is the branding: the logos of {$list}, which may be on what carries the branding, such as a work vest, a helmet, a vehicle or a building sign. Say so by the brand's exact name, such as \"the {$brands[0]} logo on the back of the vest\", and never spell out words of a logo or any other brand.";
    }
}
