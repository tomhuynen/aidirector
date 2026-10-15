<?php

declare(strict_types=1);

namespace App\Ai\Briefs;

use App\Models\Element;
use App\Models\Keyframe;
use App\Support\Shots\KeyframeLayout;
use App\Support\Shots\KeyframeReading;
use Illuminate\Support\Str;

/**
 * The prompts for the layout model, which keeps an image and changes only
 * boxes in it. Each row has an id named after what it holds, such as
 * `security_guard`, and the instruction refers to it as `<security_guard>`,
 * so the model knows which box is meant. Image 0 is the image that is kept;
 * the pictures of the cast follow in the order given.
 *
 * A later keyframe is drawn on the keyframe before it: the cast who stay are
 * anchored, those who move are moved out of that image, and those who are
 * new are drawn from their picture. Keyframe 1, drawn whole, is put right:
 * whoever stands in the wrong spot is moved, whoever does not look like
 * their picture is drawn from it where they stand.
 *
 * @phpstan-import-type Box from KeyframeLayout
 */
final class KeyframeLayoutBrief
{
    /** The whole frame, on the 0 to 1000 grid. */
    private const array WHOLE = [0, 0, 1000, 1000];

    /**
     * @param  array<string, int>  $pictures  per name of the cast, the number of its attached picture
     * @param  list<Element>  $props  objects the cast holds, each with its picture after the cast's
     * @param  string  $extra  added to this drawing only, such as a change the director asked for
     */
    public static function for(Keyframe $keyframe, KeyframeLayout $layout, KeyframeLayout $before, array $pictures, array $props = [], string $extra = ''): string
    {
        $lines = [
            "Keyframe {$keyframe->position} of an animated e-learning film. " . self::keep(),
            'What this keyframe shows: ' . $keyframe->fullDescription(),
        ];

        foreach ($layout->cast as $member) {
            if (! $member['changed']) {
                continue;
            }

            $tag = '<' . self::id($member['name']) . '>';
            $old = $before->member($member['name']);
            $picture = isset($pictures[$member['name']]) ? " {$tag} looks exactly like <ref_image_{$pictures[$member['name']]}>." : '';

            $lines[] = match (true) {
                $old === null => "{$tag} comes into the frame in their box, {$member['facing']}; " . self::sentence($member['pose']) . $picture,
                $old['box'] === $member['box'] => "{$tag} stays where they stand, {$member['facing']}; " . self::sentence($member['pose']) . $picture,
                default => "{$tag} moves to their new box, {$member['facing']}; " . self::sentence($member['pose']) . " Where {$tag} stood before, the place shows as it is behind them, empty, with no shadow left on the floor." . $picture,
            };
            $lines[] = "The feet of {$tag} stand on the floor at the bottom of the box, with the same kind of contact shadow under them as the others in <ref_image_0> have.";
        }

        foreach ($layout->cast as $member) {
            if (! $member['changed']) {
                $lines[] = '<' . self::id($member['name']) . '> stays exactly as it is.';
            }
        }

        foreach ($props as $index => $prop) {
            $lines[] = "{$prop->name} looks exactly like <ref_image_" . (count($pictures) + 1 + $index) . '>.';
        }

        foreach ($layout->changes as $change) {
            $lines[] = "The {$change['thing']} is now {$change['state']}.";
        }

        if (trim($extra) !== '') {
            $lines[] = trim($extra);
        }

        $lines[] = self::noText($keyframe);
        $lines[] = self::encode(self::rows($layout, $before, $pictures));

        return implode("\n", $lines);
    }

    /**
     * The rows: the image as the anchor, the cast who stay anchored where they
     * stand, the cast who move moved out of the image, the cast who are new
     * from their picture, and the things of the place in their new state.
     *
     * @param  array<string, int>  $pictures
     * @return list<array{id: string, from: string|null, src_bbox: Box|null, tgt_bbox: Box|null, desc: string, kind: string}>
     */
    public static function rows(KeyframeLayout $layout, KeyframeLayout $before, array $pictures): array
    {
        $rows = [self::frame('the image before this keyframe: the place and everything in it that does not change')];

        foreach ($layout->cast as $member) {
            $id = self::id($member['name']);

            if (! $member['changed']) {
                $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => $member['box'], 'tgt_bbox' => $member['box'], 'desc' => "{$member['name']}, unchanged", 'kind' => 'anchor'];

                continue;
            }

            $old = $before->member($member['name']);
            $picture = $pictures[$member['name']] ?? null;
            $desc = "{$member['name']}, {$member['facing']}, " . rtrim($member['pose'], '.');

            $rows[] = match (true) {
                $old !== null => ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => self::pad($old['box']), 'tgt_bbox' => self::pad($member['box']), 'desc' => $desc, 'kind' => 'move'],
                $picture !== null => ['id' => $id, 'from' => "ref_image_{$picture}", 'src_bbox' => self::WHOLE, 'tgt_bbox' => $member['box'], 'desc' => $desc, 'kind' => 'move'],
                default => ['id' => $id, 'from' => null, 'src_bbox' => null, 'tgt_bbox' => $member['box'], 'desc' => $desc, 'kind' => 'new'],
            };
        }

        foreach ($layout->changes as $change) {
            $rows[] = ['id' => self::id($change['thing']), 'from' => null, 'src_bbox' => null, 'tgt_bbox' => $change['box'], 'desc' => "the {$change['thing']}, now {$change['state']}", 'kind' => 'new'];
        }

        return $rows;
    }

    /**
     * The prompt that puts keyframe 1 right: who stands in the wrong spot is
     * moved to where they belong, who does not look like their picture is
     * drawn from it where they stand, and everyone else stays.
     *
     * @param  array<string, int>  $pictures  per name of the cast, the number of its attached picture
     */
    public static function fix(Keyframe $keyframe, KeyframeReading $reading, array $pictures): string
    {
        $lines = ['Keyframe 1 of an animated e-learning film. ' . self::keep(), 'What this keyframe shows: ' . $keyframe->fullDescription()];
        $rows = [self::frame('keyframe 1 as it is drawn, unchanged where no row changes it')];

        foreach ($reading->cast as $read) {
            $id = self::id($read['name']);
            $tag = "<{$id}>";
            $picture = $pictures[$read['name']] ?? null;

            if (! $read['placed_right']) {
                $lines[] = "{$tag} moves to their new box, where the description puts them, at the size they have there. Where {$tag} stood, the place shows as it is behind them, empty, with no shadow left on the floor.";
                $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => self::pad($read['box']), 'tgt_bbox' => self::pad($read['target']), 'desc' => "{$read['name']}, {$read['facing']}, " . rtrim($read['pose'], '.'), 'kind' => 'move'];
            } elseif (! $read['looks_right'] && $picture !== null) {
                $lines[] = "{$tag} stays where they stand, in the same pose, but looks exactly like <ref_image_{$picture}>: the same clothing, colours, headwear and details, with nothing added.";
                $rows[] = ['id' => $id, 'from' => "ref_image_{$picture}", 'src_bbox' => self::WHOLE, 'tgt_bbox' => $read['box'], 'desc' => "{$read['name']}, {$read['facing']}, " . rtrim($read['pose'], '.'), 'kind' => 'move'];
            } else {
                $lines[] = "{$tag} stays exactly as it is.";
                $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => $read['box'], 'tgt_bbox' => $read['box'], 'desc' => "{$read['name']}, unchanged", 'kind' => 'anchor'];
            }
        }

        $lines[] = self::noText($keyframe);
        $lines[] = self::encode($rows);

        return implode("\n", $lines);
    }

    /**
     * The prompt for moving one of the cast in a keyframe to the box the
     * director dragged them to, with everyone else staying. Without a box
     * they leave the frame. With a picture they are drawn from it, for
     * someone who was cut off by the edge of the frame; otherwise they are
     * moved out of the keyframe itself. When cut off, only the part inside
     * the frame shows.
     *
     * @param  Box|null  $box
     */
    public static function moved(Keyframe $keyframe, KeyframeLayout $layout, string $name, ?array $box, ?int $picture = null, bool $cutOff = false): string
    {
        $lines = ["Keyframe {$keyframe->position} of an animated e-learning film. " . self::keep(), 'What this keyframe shows: ' . $keyframe->fullDescription()];
        $rows = [self::frame('the keyframe as it is drawn, unchanged where no row changes it')];

        foreach ($layout->cast as $member) {
            $id = self::id($member['name']);
            $desc = "{$member['name']}, {$member['facing']}, " . rtrim($member['pose'], '.');

            if (mb_strtolower($member['name']) !== mb_strtolower($name)) {
                $lines[] = "<{$id}> stays exactly as it is.";
                $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => $member['box'], 'tgt_bbox' => $member['box'], 'desc' => "{$member['name']}, unchanged", 'kind' => 'anchor'];

                continue;
            }

            if ($box === null) {
                $lines[] = "<{$id}> leaves the frame and is no longer in this keyframe. Where <{$id}> stood, the place shows as it is behind them, empty, with no shadow.";
                $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => self::pad($member['box']), 'tgt_bbox' => null, 'desc' => "{$member['name']}, removed", 'kind' => 'move'];

                continue;
            }

            $lines[] = "<{$id}> moves to their new box, in the same pose and facing the same way, at the size they have there. Their feet stand on the floor, and their shadow moves along with them. Where <{$id}> stood, the place shows as it is behind them, empty, with no shadow left on the floor."
                . ($picture !== null ? " <{$id}> looks exactly like <ref_image_{$picture}>, the whole of them." : '')
                . ($cutOff ? " <{$id}> stands at the edge of the frame: only the part of them inside the box shows, the rest is cut off by the edge." : '');
            $rows[] = $picture !== null
                ? ['id' => $id, 'from' => "ref_image_{$picture}", 'src_bbox' => self::WHOLE, 'tgt_bbox' => $box, 'desc' => $desc, 'kind' => 'move']
                : ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => self::pad($member['box']), 'tgt_bbox' => self::pad($box), 'desc' => $desc, 'kind' => 'move'];
        }

        $lines[] = self::noText($keyframe);
        $lines[] = self::encode($rows);

        return implode("\n", $lines);
    }

    /**
     * The prompt for changing one of the cast where they stand, as the
     * director asked in the chat, with everyone else staying.
     */
    public static function changed(Keyframe $keyframe, KeyframeLayout $layout, string $name, string $change): string
    {
        $lines = ["Keyframe {$keyframe->position} of an animated e-learning film. " . self::keep(), 'What this keyframe shows: ' . $keyframe->fullDescription()];
        $rows = [self::frame('the keyframe as it is drawn, unchanged where no row changes it')];

        foreach ($layout->cast as $member) {
            $id = self::id($member['name']);

            if (mb_strtolower($member['name']) !== mb_strtolower($name)) {
                $lines[] = "<{$id}> stays exactly as it is.";
                $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => $member['box'], 'tgt_bbox' => $member['box'], 'desc' => "{$member['name']}, unchanged", 'kind' => 'anchor'];

                continue;
            }

            $lines[] = "<{$id}> stays where they stand, in their box, and changes only this: " . self::sentence($change) . " Everything else about <{$id}> stays as it is.";
            $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => self::pad($member['box']), 'tgt_bbox' => self::pad($member['box']), 'desc' => "{$member['name']}, " . rtrim(trim($change), '.'), 'kind' => 'move'];
        }

        $lines[] = self::noText($keyframe);
        $lines[] = self::encode($rows);

        return implode("\n", $lines);
    }

    /**
     * The prompt that fixes what a check found in a keyframe: each problem is
     * changed in place, inside the box found for it, with the picture of the
     * one of the cast it is about as the reference when they look wrong; the
     * cast it is not about and everything outside the boxes stay.
     *
     * @param  list<array{problem: string, box: Box, member: string|null, looks: bool, shows: string}>  $fixes
     * @param  array<string, int>  $pictures  per name of the cast, the number of its attached picture
     */
    public static function repaired(Keyframe $keyframe, KeyframeLayout $layout, array $fixes, array $pictures): string
    {
        $lines = ["Keyframe {$keyframe->position} of an animated e-learning film. " . self::keep(), 'What this keyframe shows: ' . $keyframe->fullDescription()];
        $rows = [self::frame('the keyframe as it is drawn, unchanged where no row changes it')];
        $fixed = array_filter(array_map(fn(array $fix) => $fix['member'] === null ? null : mb_strtolower($fix['member']), $fixes));

        foreach ($layout->cast as $member) {
            if (! in_array(mb_strtolower($member['name']), $fixed, true)) {
                $id = self::id($member['name']);
                $lines[] = "<{$id}> stays exactly as it is.";
                $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => $member['box'], 'tgt_bbox' => $member['box'], 'desc' => "{$member['name']}, unchanged", 'kind' => 'anchor'];
            }
        }

        foreach ($fixes as $index => $fix) {
            $id = $fix['member'] !== null ? self::id($fix['member']) . '_fix' : 'fix_' . ($index + 1);
            $picture = $fix['looks'] ? ($pictures[(string) $fix['member']] ?? null) : null;
            $lines[] = "Inside <{$id}>, fix this: " . self::sentence($fix['problem']) . ' Afterwards it shows ' . lcfirst(self::sentence($fix['shows']))
                . match (true) {
                    $picture !== null => " Copy how {$fix['member']} looks there exactly from <ref_image_{$picture}>, the picture of {$fix['member']}, keeping their pose and where they stand.",
                    $fix['member'] !== null => " {$fix['member']} looks as they do now; only their pose, what they hold or wear, or where they are changes.",
                    default => '',
                }
            . " Everything else inside <{$id}> stays as it is.";
            $rows[] = ['id' => $id, 'from' => 'ref_image_0', 'src_bbox' => $fix['box'], 'tgt_bbox' => $fix['box'], 'desc' => rtrim($fix['shows'], '.'), 'kind' => 'move'];
        }

        // A fix may bring back a logo from the picture of whoever it is about.
        $lines[] = TextRules::forImages($keyframe->shot->project) . ' Signs and markings already in <ref_image_0> stay where they are.';
        $lines[] = self::encode($rows);

        return implode("\n", $lines);
    }

    /**
     * The id of a row, named after what it holds, such as `security_guard`.
     */
    public static function id(string $name): string
    {
        return Str::slug($name, '_');
    }

    private static function keep(): string
    {
        return 'In <ref_image_0>, keep everything exactly as it is, including every sign, the camera, the framing, the light and the style. Change only what the rows below change.';
    }

    private static function noText(Keyframe $keyframe): string
    {
        return TextRules::forEdits($keyframe->shot->project) . ' Signs and markings already in <ref_image_0> stay where they are.';
    }

    /**
     * A box with room around someone drawn again out of the image, so the
     * edge where the new part blends in falls on plain floor and wall, not
     * along their legs and feet, and their shadow is inside it: taken from
     * where they stood, it moves along with them. Wider at the sides and
     * below, the same on both spots, so they keep their size.
     *
     * @param  Box  $box
     * @return Box
     */
    private static function pad(array $box): array
    {
        [$top, $left, $bottom, $right] = $box;
        $width = $right - $left;
        $height = $bottom - $top;

        return [
            max(0, (int) round($top - $height * 0.03)),
            max(0, (int) round($left - $width * 0.15)),
            min(1000, (int) round($bottom + $height * 0.12)),
            min(1000, (int) round($right + $width * 0.15)),
        ];
    }

    private static function sentence(string $text): string
    {
        return rtrim(trim($text), '.') . '.';
    }

    /**
     * @return array{id: string, from: string, src_bbox: Box, tgt_bbox: Box, desc: string, kind: string}
     */
    private static function frame(string $desc): array
    {
        return ['id' => 'frame', 'from' => 'ref_image_0', 'src_bbox' => self::WHOLE, 'tgt_bbox' => self::WHOLE, 'desc' => $desc, 'kind' => 'anchor'];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function encode(array $rows): string
    {
        return json_encode(['rows' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
